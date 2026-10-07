<?php

declare(strict_types=1);

use Amtgard\Denarius\Domain\Taxonomy\PhinxTransactionCategoryMigrationStore;
use Amtgard\Denarius\Domain\Taxonomy\TransactionCategoryLegacyNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\TransactionCategorySchemaMigrator;
use Phinx\Migration\AbstractMigration;

final class TransactionCategorySchema extends AbstractMigration
{
    public function up(): void
    {
        $this->table('transactions')
            ->addColumn('provider_category', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('category_source', 'string', ['limit' => 16, 'default' => 'fallback', 'null' => false])
            ->addColumn('category_rule_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('category_confidence', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('category_suggested', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('taxonomy_version', 'string', ['limit' => 16, 'null' => true])
            ->update();

        $pack = dirname(__DIR__, 2) . '/data/taxonomy/taxonomy.json';
        $normalizer = TransactionCategoryLegacyNormalizer::fromTaxonomyJson($pack);
        (new TransactionCategorySchemaMigrator(
            $normalizer,
            new PhinxTransactionCategoryMigrationStore($this->getAdapter()),
        ))->migrate();
    }

    public function down(): void
    {
        $this->table('transactions')
            ->removeColumn('taxonomy_version')
            ->removeColumn('category_suggested')
            ->removeColumn('category_confidence')
            ->removeColumn('category_rule_id')
            ->removeColumn('category_source')
            ->removeColumn('provider_category')
            ->update();
    }
}
