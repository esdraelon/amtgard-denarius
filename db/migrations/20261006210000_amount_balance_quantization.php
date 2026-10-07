<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AmountBalanceQuantization extends AbstractMigration
{
    public function change(): void
    {
        $this->table('kingdoms')
            ->addColumn('amount_quantum_cents', 'integer', ['default' => 100, 'null' => false])
            ->addColumn('balance_quantum_floor_cents', 'integer', ['default' => 500, 'null' => false])
            ->addColumn('balance_quantum_ceiling_cents', 'integer', ['default' => 500, 'null' => false])
            ->addColumn('balance_quantum_step_cents', 'integer', ['default' => 0, 'null' => false])
            ->update();

        $this->table('kingdoms_audit')
            ->addColumn('amount_quantum_cents', 'integer', ['null' => true])
            ->addColumn('balance_quantum_floor_cents', 'integer', ['null' => true])
            ->addColumn('balance_quantum_ceiling_cents', 'integer', ['null' => true])
            ->addColumn('balance_quantum_step_cents', 'integer', ['null' => true])
            ->update();
    }
}
