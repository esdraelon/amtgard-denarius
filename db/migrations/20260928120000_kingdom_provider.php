<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class KingdomProvider extends AbstractMigration
{
    public function change(): void
    {
        $this->table('kingdoms')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => true, 'default' => 'teller'])
            ->update();

        $this->table('kingdoms_audit')
            ->addColumn('provider', 'string', ['limit' => 512, 'null' => true])
            ->update();
    }
}
