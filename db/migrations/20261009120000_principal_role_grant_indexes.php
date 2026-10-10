<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PrincipalRoleGrantIndexes extends AbstractMigration
{
    public function change(): void
    {
        $this->table('principals')
            ->addIndex(['email'])
            ->update();

        $this->table('role_grants')
            ->addIndex(['target_idp_user_id'])
            ->addIndex(['created_at', 'id'])
            ->update();
    }
}
