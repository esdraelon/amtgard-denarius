<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SummarizedCategoryMinLines extends AbstractMigration
{
    public function change(): void
    {
        $this->table('kingdoms')
            ->addColumn('summarized_category_min_lines', 'integer', ['default' => 2, 'null' => false])
            ->update();

        $this->table('kingdoms_audit')
            ->addColumn('summarized_category_min_lines', 'integer', ['null' => true])
            ->update();
    }
}
