<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KingdomSyncStatus extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('kingdoms');
        $table
            ->addColumn('last_sync_attempted_at', 'string', ['limit' => 40, 'null' => true, 'after' => 'last_synced_at'])
            ->addColumn('last_sync_status', 'string', ['limit' => 20, 'null' => true, 'after' => 'last_sync_attempted_at'])
            ->addColumn('last_sync_error', 'string', ['limit' => 255, 'null' => true, 'after' => 'last_sync_status'])
            ->update();
    }
}
