<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

use Amtgard\Denarius\Tests\Support\PersistenceStoreArrange;
use Amtgard\PHPUnit\AmtgardTestCase;
use PDO;

final class TransactionCategorySchemaMigrationTest extends AmtgardTestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = PersistenceStoreArrange::tryPdo();
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MariaDB is not available.');
        }
        PersistenceStoreArrange::migrateFresh(self::$pdo);
    }

    public function testCategoryColumnsExistAfterMigrate(): void
    {
        $pdo = self::$pdo;
        $this->assertNotFalse($pdo->query("SHOW COLUMNS FROM transactions LIKE 'category_id'")->fetch());
        $this->assertNotFalse($pdo->query("SHOW COLUMNS FROM transactions LIKE 'provider_category'")->fetch());
        $this->assertNotFalse($pdo->query("SHOW COLUMNS FROM transactions LIKE 'category_source'")->fetch());
        $this->assertNotFalse($pdo->query("SHOW COLUMNS FROM categories LIKE 'lineage_key'")->fetch());
    }

    public function testRollbackRemovesCategoryColumnsAndMigrateRestores(): void
    {
        $this->markTestSkipped('Global categories migration (20261010120000) is not reversible via Phinx rollback.');
        $root = dirname(__DIR__, 2);
        $phinx = escapeshellarg($root . '/vendor/bin/phinx');
        $rollback = sprintf(
            'cd %s && %s %s rollback -e testing -t 20261006210000',
            escapeshellarg($root),
            PersistenceStoreArrange::phinxEnvPrefix(),
            $phinx,
        );
        exec($rollback, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $pdo = self::$pdo;
        $this->assertFalse($pdo->query("SHOW COLUMNS FROM transactions LIKE 'category_id'")->fetch());

        $migrate = sprintf(
            'cd %s && %s %s migrate -e testing',
            escapeshellarg($root),
            PersistenceStoreArrange::phinxEnvPrefix(),
            $phinx,
        );
        exec($migrate, $migrateOutput, $migrateCode);
        $this->assertSame(0, $migrateCode, implode("\n", $migrateOutput));
        $this->assertNotFalse($pdo->query("SHOW COLUMNS FROM transactions LIKE 'category_id'")->fetch());
    }

    public function testDataMigrationNormalizesLegacyGeneral(): void
    {
        $this->markTestSkipped('Legacy slug column removed; normalization is covered by GlobalCategoriesMigrator.');
        $root = dirname(__DIR__, 2);
        $phinx = escapeshellarg($root . '/vendor/bin/phinx');
        $rollback = sprintf(
            'cd %s && %s %s rollback -e testing -t 20261006210000',
            escapeshellarg($root),
            PersistenceStoreArrange::phinxEnvPrefix(),
            $phinx,
        );
        exec($rollback);

        $pdo = self::$pdo;
        $kingdomId = (int) $pdo->query('SELECT id FROM kingdoms LIMIT 1')->fetchColumn();
        if ($kingdomId === 0) {
            $pdo->exec("INSERT INTO kingdoms (ork_kingdom_id, name, slug, visibility, display_mode, enrollment_status) VALUES (99, 'Legacy', 'legacy', 'public', 'all', 'connected')");
            $kingdomId = (int) $pdo->lastInsertId();
        }
        $pdo->exec(
            'INSERT INTO transactions (kingdom_id, teller_transaction_id, teller_account_id, posted_on, amount_cents, category) '
            . "VALUES ($kingdomId, 'legacy-general', 'acc', '2026-09-01', -100, 'general')",
        );

        $migrate = sprintf(
            'cd %s && %s %s migrate -e testing',
            escapeshellarg($root),
            PersistenceStoreArrange::phinxEnvPrefix(),
            $phinx,
        );
        exec($migrate);

        $row = $pdo->query(
            "SELECT category_id, provider_category, category_source FROM transactions WHERE teller_transaction_id = 'legacy-general'",
        )->fetch(PDO::FETCH_ASSOC);
        $uncategorizedId = (int) $pdo->query(
            "SELECT c.id FROM categories c INNER JOIN category_lineages cl ON cl.current_category_id = c.id WHERE cl.lineage_key = 'uncategorized' LIMIT 1",
        )->fetchColumn();
        $this->assertSame($uncategorizedId, (int) $row['category_id']);
        $this->assertNull($row['provider_category']);
        $this->assertSame('fallback', $row['category_source']);
    }
}
