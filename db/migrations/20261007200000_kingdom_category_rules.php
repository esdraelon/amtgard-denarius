<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KingdomCategoryRules extends AbstractMigration
{
    public function up(): void
    {
        $this->table('kingdom_category_rules')
            ->addColumn('kingdom_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('category', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('fields_json', 'text', ['null' => false])
            ->addColumn('match_type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('regex_pattern', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('token', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('any_of_json', 'text', ['null' => true])
            ->addColumn('flows_json', 'text', ['null' => false])
            ->addColumn('confidence', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'signed' => false, 'default' => 100, 'null' => false])
            ->addIndex(['kingdom_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('kingdom_category_rules')->drop()->save();
    }
}
