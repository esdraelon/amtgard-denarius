<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KingdomCustomCategories extends AbstractMigration
{
    public function up(): void
    {
        $this->table('kingdom_custom_categories')
            ->addColumn('kingdom_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('flow', 'string', ['limit' => 16, 'null' => false])
            ->addIndex(['kingdom_id', 'slug'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('kingdom_custom_categories')->drop()->save();
    }
}
