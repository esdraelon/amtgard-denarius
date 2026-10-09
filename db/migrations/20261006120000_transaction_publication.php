<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TransactionPublication extends AbstractMigration
{
    public function change(): void
    {
        $this->table('kingdoms')
            ->addColumn('embargo_days', 'integer', ['default' => 3, 'null' => false])
            ->addColumn('initial_backfill_completed_at', 'string', ['limit' => 40, 'null' => true])
            ->update();

        $this->table('kingdoms_audit')
            ->addColumn('embargo_days', 'integer', ['null' => true])
            ->addColumn('initial_backfill_completed_at', 'string', ['limit' => 512, 'null' => true])
            ->update();

        $this->table('transactions')
            ->addColumn('published_at', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('publishable_after', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('publication_flags', 'text', ['null' => true])
            ->addIndex(['kingdom_id', 'published_at'])
            ->update();
    }
}
