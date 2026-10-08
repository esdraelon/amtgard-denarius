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
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\Impl\KingdomCategoryRuleRepository;
use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\TransactionRepository;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use PDO;

/** Shared MariaDB arrange for repository round-trips (StoreTest and method-log tests). */
final class PersistenceStoreArrange
{
    public const TEST_DATABASE = 'denarius_test';

    public static function tryPdo(): ?PDO
    {
        try {
            self::ensureTestDatabaseExists();
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
        self::assertSafeToWipe();

        foreach (['kingdom_category_rules', 'role_grants', 'transactions', 'enrollment_secrets', 'published_accounts_audit', 'published_accounts', 'kingdoms_audit', 'kingdoms', 'principals', 'phinxlog'] as $table) {
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

    public static function ensureTestDatabaseExists(): void
    {
        if (self::env('APP_ENV') !== 'testing') {
            return;
        }
        if (self::env('DB_NAME') !== self::TEST_DATABASE) {
            return;
        }

        $host = self::env('DB_HOST', '127.0.0.1');
        $port = self::env('DB_PORT', '3306');
        $appUser = self::env('DB_USER', 'denarius');
        $rootUser = self::env('DB_ROOT_USER', 'root');
        $rootPass = self::env('DB_ROOT_PASS', '');

        if ($rootPass === '') {
            return;
        }

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port,
            $rootUser,
            $rootPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec(
            'CREATE DATABASE IF NOT EXISTS `' . self::TEST_DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $quotedUser = $admin->quote($appUser);
        $admin->exec('GRANT ALL PRIVILEGES ON `' . self::TEST_DATABASE . '`.* TO ' . $quotedUser . "@'%'");
        $admin->exec('FLUSH PRIVILEGES');
    }

    public static function ensureTestSchemaMigrated(): void
    {
        if (self::env('APP_ENV') !== 'testing' || self::env('DB_NAME') !== self::TEST_DATABASE) {
            return;
        }

        $pdo = self::tryPdoWithoutSchemaEnsure();
        if ($pdo === null) {
            return;
        }

        if ($pdo->query("SHOW TABLES LIKE 'kingdom_category_rules'")->fetchColumn() === 'kingdom_category_rules') {
            return;
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
            throw new \RuntimeException('Phinx migrate failed for test database:' . "\n" . implode("\n", $output));
        }
    }

    private static function tryPdoWithoutSchemaEnsure(): ?PDO
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

    private static function env(string $key, string $default = ''): string
    {
        $fromEnv = $_ENV[$key] ?? getenv($key);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return $default;
    }

    private static function assertSafeToWipe(): void
    {
        if (self::env('APP_ENV') !== 'testing') {
            throw new \RuntimeException('Refusing migrateFresh: APP_ENV must be testing.');
        }
        $name = self::env('DB_NAME');
        if ($name !== self::TEST_DATABASE) {
            throw new \RuntimeException(
                'Refusing migrateFresh on database "' . $name . '". '
                . 'PHPUnit must use DB_NAME=' . self::TEST_DATABASE . ' (see phpunit.xml). '
                . 'Never point tests at the dev database "denarius".',
            );
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
        KingdomCategoryRuleRepository::getTableName();
        KingdomCategoryRuleRepository::getEntityClass();

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

        $catalog = (new \Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader(dirname(__DIR__, 2), 'data/taxonomy'))->load();
        $patternRules = Orm::repository(KingdomCategoryRuleRepository::class);
        $pattern = $patternRules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId((int) $saved->getId())
            ->category('expense.storage')
            ->matchType('token')
            ->token('STORAGE UNIT')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $patternRules->forKingdom((int) $saved->getId());
        $patternRules->findById((int) $saved->getId(), (int) $pattern->getId());
        $pattern->publicRuleId();
        $pattern->toKeywordRule();
        $pattern->manageView($catalog);
        $patternRules->save(KingdomCategoryRuleRecord::builder()
            ->id($pattern->getId())
            ->kingdomId((int) $saved->getId())
            ->category('expense.storage')
            ->matchType('token')
            ->token('STORAGE LOCKER')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $patternRules->removeRule((int) $saved->getId(), (int) $pattern->getId());
    }
}
