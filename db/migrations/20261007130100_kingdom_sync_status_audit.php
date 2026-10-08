<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KingdomSyncStatusAudit extends AbstractMigration
{
    public function change(): void
    {
        $this->table('kingdoms_audit')
            ->addColumn('last_sync_attempted_at', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('last_sync_status', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('last_sync_error', 'string', ['limit' => 512, 'null' => true])
            ->update();
    }
}
