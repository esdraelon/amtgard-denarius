<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Drop persisted category_suggested; hints are computed at review render time. */
final class DropTransactionCategorySuggested extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('transactions')->hasColumn('category_suggested')) {
            return;
        }
        $this->table('transactions')
            ->removeColumn('category_suggested')
            ->update();
    }

    public function down(): void
    {
        if ($this->table('transactions')->hasColumn('category_suggested')) {
            return;
        }
        $this->table('transactions')
            ->addColumn('category_suggested', 'string', ['limit' => 64, 'null' => true])
            ->update();
    }
}
