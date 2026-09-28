<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Persistence\Orm;
use Amtgard\Denarius\Persistence\Repository\AccountRepository;
use Amtgard\Denarius\Persistence\Repository\KingdomRepository;
use Amtgard\Denarius\Persistence\Repository\PrincipalRepository;
use Amtgard\Denarius\Persistence\Repository\RoleGrantRepository;
use Amtgard\Denarius\Persistence\Repository\SecretRepository;
use Amtgard\Denarius\Persistence\Repository\TransactionRepository;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\PHPUnit\AmtgardTestCase;
use PDO;

final class StoreTest extends AmtgardTestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        try {
            $config = DatabaseConfiguration::fromEnvironment();
            self::$pdo = MysqlPdoProvider::fromConfiguration($config)->getPdo();
            self::$pdo->query('SELECT 1');
        } catch (\Throwable) {
            self::$pdo = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MariaDB is not available.');
        }
        foreach (['role_grants', 'transactions', 'enrollment_secrets', 'published_accounts_audit', 'published_accounts', 'kingdoms_audit', 'kingdoms', 'principals', 'phinxlog'] as $table) {
            self::$pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $phinx = dirname(__DIR__, 2) . '/vendor/bin/phinx';
        $root = dirname(__DIR__, 2);
        $command = sprintf('cd %s && PHINX_ENV=testing %s migrate -e testing', escapeshellarg($root), escapeshellarg($phinx));
        exec($command, $output, $code);
        if ($code !== 0) {
            $this->fail(implode("\n", $output));
        }
        CurrentActor::set('15');
    }

    public function testRepositoriesRoundTripAuditedKingdoms(): void
    {
        $this->assertSame('kingdoms', KingdomRepository::getTableName());
        $this->assertSame(KingdomRepository::class, AccountRepository::getTableName() === 'published_accounts' ? KingdomRepository::class : '');
        $this->assertSame('principals', PrincipalRepository::getTableName());
        $this->assertSame('enrollment_secrets', SecretRepository::getTableName());
        $this->assertSame('transactions', TransactionRepository::getTableName());
        $this->assertSame('role_grants', RoleGrantRepository::getTableName());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\KingdomEntity::class, KingdomRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\AccountEntity::class, AccountRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\PrincipalEntity::class, PrincipalRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\SecretEntity::class, SecretRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\TransactionEntity::class, TransactionRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\RoleGrantEntity::class, RoleGrantRepository::getEntityClass());

        $pdo = self::$pdo;
        $kingdoms = Orm::repository(KingdomRepository::class);
        $saved = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentId('enr')->institutionName('Bank')->provider('teller')->enrollmentStatus('connected')->build());
        $this->assertNotNull($saved->getId());
        $this->assertSame('golden-plains', $kingdoms->findBySlug('golden-plains')->getSlug());
        $this->assertSame(4, $kingdoms->findByOrkId(4)->getOrkKingdomId());
        $this->assertSame('enr', $kingdoms->findByEnrollmentId('enr')->getEnrollmentId());
        $this->assertSame('teller', $kingdoms->findByProviderEnrollment('teller', 'enr')->getProvider());
        $this->assertNull($kingdoms->findByProviderEnrollment('stripe', 'enr'));
        $this->assertCount(1, $kingdoms->connected());
        $kingdoms->save(KingdomRecord::builder()->id($saved->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('registered')->displayMode('summarized')->enrollmentId('enr')->institutionName('Bank')->provider('teller')->enrollmentStatus('connected')->lastSyncedAt('2026-09-01')->build());
        $audit = $pdo->query('SELECT COUNT(*) FROM kingdoms_audit')->fetchColumn();
        $this->assertGreaterThan(0, (int) $audit);

        $principals = Orm::repository(PrincipalRepository::class);
        $principal = $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->orkKingdomId(4)->orkKingdomName('Golden Plains')->build());
        $this->assertSame('person@example.com', $principals->findByIdpUserId('9')->getEmail());
        $this->assertCount(1, $principals->searchByEmail('person@'));
        $principals->save(PrincipalRecord::builder()->id($principal->getId())->idpUserId('9')->email('other@example.com')->build());

        $accounts = Orm::repository(AccountRepository::class);
        $account = $accounts->save(AccountRecord::builder()->kingdomId((int) $saved->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->lastFour('1234')->published(true)->build());
        $this->assertTrue($accounts->forKingdom((int) $saved->getId())[0]->getPublished());
        $accounts->save(AccountRecord::builder()->id($account->getId())->kingdomId((int) $saved->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->published(false)->build());
        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM published_accounts_audit')->fetchColumn());

        $secrets = Orm::repository(SecretRepository::class);
        $this->assertNull($secrets->findCiphertext((int) $saved->getId()));
        $secrets->saveCiphertext((int) $saved->getId(), 'cipher');
        $secrets->saveCiphertext((int) $saved->getId(), 'cipher-2');
        $this->assertSame('cipher-2', $secrets->findCiphertext((int) $saved->getId()));

        $transactions = Orm::repository(TransactionRepository::class);
        $transactions->upsert(TransactionRecord::builder()->kingdomId((int) $saved->getId())->tellerTransactionId('txn')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(-100)->category('office')->description('paper')->counterparty('Shop')->status('posted')->build());
        $transactions->upsert(TransactionRecord::builder()->kingdomId((int) $saved->getId())->tellerTransactionId('txn')->tellerAccountId('acc')->postedOn('2026-09-03')->amountCents(-200)->category('fuel')->description('gas')->counterparty('Station')->status('posted')->build());
        $rows = $transactions->forKingdom((int) $saved->getId());
        $this->assertCount(1, $rows);
        $this->assertSame(-200, $rows[0]->getAmountCents());

        $grants = Orm::repository(RoleGrantRepository::class);
        $grants->append(RoleGrantRecord::builder()->actorIdpUserId('15')->targetIdpUserId('9')->action('grant')->resource('Denarius/Admin')->createdAt('2026-09-01T00:00:00+00:00')->build());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM role_grants')->fetchColumn());
    }
}
