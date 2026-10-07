<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;
use Amtgard\Denarius\Persistence\Orm;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Account\Impl\AccountRepository;
use Amtgard\Denarius\Persistence\Repository\Kingdom\Impl\KingdomRepository;
use Amtgard\Denarius\Persistence\Repository\Principal\Impl\PrincipalRepository;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\Impl\RoleGrantRepository;
use Amtgard\Denarius\Persistence\Repository\Secret\Impl\SecretRepository;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\TransactionRepository;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use PDO;

/** Shared MariaDB arrange for repository round-trips (StoreTest and method-log tests). */
final class PersistenceStoreArrange
{
    public static function tryPdo(): ?PDO
    {
        try {
            $config = DatabaseConfiguration::fromEnvironment();
            $pdo = MysqlPdoProvider::fromConfiguration($config)->getPdo();
            $pdo->query('SELECT 1');

            return $pdo;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function migrateFresh(PDO $pdo): void
    {
        foreach (['role_grants', 'transactions', 'enrollment_secrets', 'published_accounts_audit', 'published_accounts', 'kingdoms_audit', 'kingdoms', 'principals', 'phinxlog'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $phinx = dirname(__DIR__, 2) . '/vendor/bin/phinx';
        $root = dirname(__DIR__, 2);
        $command = sprintf(
            'cd %s && %s %s migrate -e testing',
            escapeshellarg($root),
            self::phinxEnvPrefix(),
            escapeshellarg($phinx),
        );
        exec($command, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(implode("\n", $output));
        }
        if ($pdo->query("SHOW TABLES LIKE 'kingdoms'")->fetchColumn() !== 'kingdoms') {
            throw new \RuntimeException('Phinx migrate did not create kingdoms:' . "\n" . implode("\n", $output));
        }
    }

    public static function phinxEnvPrefix(): string
    {
        $parts = ['PHINX_ENV=testing'];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value !== false && $value !== null && $value !== '') {
                $parts[] = $key . '=' . escapeshellarg((string) $value);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Exercises every public persistence repository path used in integration store tests.
     */
    public static function exerciseRepositories(): void
    {
        CurrentActor::set('15');

        Orm::configure(true);

        KingdomRepository::getTableName();
        KingdomRepository::getEntityClass();
        AccountRepository::getTableName();
        AccountRepository::getEntityClass();
        PrincipalRepository::getTableName();
        PrincipalRepository::getEntityClass();
        SecretRepository::getTableName();
        SecretRepository::getEntityClass();
        TransactionRepository::getTableName();
        TransactionRepository::getEntityClass();
        RoleGrantRepository::getTableName();
        RoleGrantRepository::getEntityClass();

        $kingdoms = Orm::repository(KingdomRepository::class);
        $saved = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentId('enr')->institutionName('Bank')->provider('teller')->enrollmentStatus('connected')->build());
        $kingdoms->findBySlug('golden-plains');
        $kingdoms->findByOrkId(4);
        $kingdoms->findByEnrollmentId('enr');
        $kingdoms->findByProviderEnrollment('teller', 'enr');
        $kingdoms->findByProviderEnrollment('stripe', 'enr');
        $kingdoms->connected();
        $kingdoms->all();
        $kingdoms->save(KingdomRecord::builder()->id($saved->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('registered')->displayMode('summarized')->enrollmentId('enr')->institutionName('Bank')->provider('teller')->enrollmentStatus('connected')->lastSyncedAt('2026-09-01')->build());

        $principals = Orm::repository(PrincipalRepository::class);
        $principal = $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->orkKingdomId(4)->orkKingdomName('Golden Plains')->build());
        $principals->findByIdpUserId('9');
        $principals->findByEmail('person@example.com');
        $principals->searchByEmail('person@');
        $principals->listOrkKingdomHints();
        $principals->save(PrincipalRecord::builder()->id($principal->getId())->idpUserId('9')->email('other@example.com')->build());

        $accounts = Orm::repository(AccountRepository::class);
        $account = $accounts->save(AccountRecord::builder()->kingdomId((int) $saved->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->lastFour('1234')->published(true)->build());
        $accounts->forKingdom((int) $saved->getId());
        $accounts->save(AccountRecord::builder()->id($account->getId())->kingdomId((int) $saved->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->published(false)->build());

        $secrets = Orm::repository(SecretRepository::class);
        $secrets->findCiphertext((int) $saved->getId());
        $secrets->saveCiphertext((int) $saved->getId(), 'cipher');
        $secrets->saveCiphertext((int) $saved->getId(), 'cipher-2');

        $transactions = Orm::repository(TransactionRepository::class);
        $transactions->upsert(TransactionRecord::builder()->kingdomId((int) $saved->getId())->tellerTransactionId('txn-rent')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(-100)->category('expense.site_rental')->providerCategory('RENT')->categorySource(CategorySource::ProviderHint->value)->categoryRuleId('hint.test')->categoryConfidence(70)->categorySuggested('expense.feast_groceries')->taxonomyVersion('taxonomy/v1')->description('paper')->counterparty('Shop')->status('posted')->publishableAfter('2026-09-05T23:59:59+00:00')->build());
        $transactions->upsert(TransactionRecord::builder()->kingdomId((int) $saved->getId())->tellerTransactionId('txn-grocery')->tellerAccountId('acc')->postedOn('2026-09-03')->amountCents(-200)->category('expense.feast_groceries')->description('gas')->counterparty('Station')->status('posted')->publishedAt('2026-09-04T00:00:00+00:00')->publishableAfter('2026-09-06T23:59:59+00:00')->build());
        $transactions->findByTellerTransactionId('txn-rent');
        $transactions->findByTellerTransactionId('txn-grocery');
        $transactions->forKingdom((int) $saved->getId());
        $transactions->forKingdomPublished((int) $saved->getId());
        $transactions->markPublished((int) $saved->getId(), 'txn-grocery', '2026-09-05T12:00:00+00:00');
        $transactions->markUnpublished((int) $saved->getId(), 'txn-grocery');

        $grants = Orm::repository(RoleGrantRepository::class);
        $grants->append(RoleGrantRecord::builder()->actorIdpUserId('15')->targetIdpUserId('9')->action('grant')->resource('Denarius/Admin')->createdAt('2026-09-01T00:00:00+00:00')->build());
        $grants->listChronological();
    }
}
