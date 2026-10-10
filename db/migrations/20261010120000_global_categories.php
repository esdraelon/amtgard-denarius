<?php

declare(strict_types=1);

use Amtgard\Denarius\Domain\Taxonomy\GlobalCategoriesMigrator;
use Amtgard\Denarius\Domain\Taxonomy\PhinxGlobalCategoriesMigrationStore;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\QuietMethodLog;
use Phinx\Migration\AbstractMigration;

final class GlobalCategories extends AbstractMigration
{
    public function up(): void
    {
        $this->table('categories')
            ->addColumn('lineage_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('flows_json', 'text', ['null' => false])
            ->addColumn('sensitivity', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('summary_parent_label', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('assignable', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 1, 'null' => false])
            ->addColumn('supersedes_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'string', ['limit' => 40, 'null' => false])
            ->addIndex(['lineage_key'])
            ->create();

        $this->table('category_lineages')
            ->addColumn('lineage_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('current_category_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['lineage_key'], ['unique' => true])
            ->create();

        $this->table('transactions')
            ->addColumn('category_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'category'])
            ->addIndex(['category_id'])
            ->update();

        $this->table('kingdom_category_rules')
            ->addColumn('category_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'category'])
            ->addIndex(['category_id'])
            ->update();

        if (! DenariusLog::installedQuietly()) {
            DenariusLog::install(new QuietMethodLog(new class implements MethodLog {
                public function trace(string $method, callable $body): mixed
                {
                    return $body();
                }

                public function enter(string $method): string
                {
                    return $method;
                }

                public function branch(BranchLogLevel $level, string $branch, string $method, array $context = []): void
                {
                }
            }));
        }
        $root = dirname(__DIR__, 2);
        $catalog = (new TaxonomyCatalogLoader($root, 'data/taxonomy'))->load();
        (new GlobalCategoriesMigrator(
            $catalog,
            new PhinxGlobalCategoriesMigrationStore($this->getAdapter()),
        ))->migrate();

        $this->execute('UPDATE transactions SET category_id = (SELECT current_category_id FROM category_lineages WHERE lineage_key = \'uncategorized\') WHERE category_id IS NULL');
        $this->execute('UPDATE kingdom_category_rules SET category_id = (SELECT current_category_id FROM category_lineages WHERE lineage_key = \'uncategorized\') WHERE category_id IS NULL');
        $this->table('transactions')->changeColumn('category_id', 'integer', ['signed' => false, 'null' => false])->update();
        $this->table('kingdom_category_rules')->changeColumn('category_id', 'integer', ['signed' => false, 'null' => false])->update();

        $this->execute(
            'ALTER TABLE transactions
                ADD CONSTRAINT fk_transactions_category
                FOREIGN KEY (category_id) REFERENCES categories(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
        $this->execute(
            'ALTER TABLE kingdom_category_rules
                ADD CONSTRAINT fk_kingdom_category_rules_category
                FOREIGN KEY (category_id) REFERENCES categories(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        $this->table('transactions')->removeColumn('category')->update();
        $this->table('kingdom_category_rules')->removeColumn('category')->update();
        $this->table('kingdom_custom_categories')->drop()->save();
    }

    public function down(): void
    {
        throw new \RuntimeException('Global categories migration is not reversible automatically.');
    }
}
