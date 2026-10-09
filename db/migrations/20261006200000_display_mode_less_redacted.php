<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DisplayModeLessRedacted extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE kingdoms SET display_mode = 'less_redacted' WHERE display_mode = 'all'");
        $this->execute("UPDATE kingdoms_audit SET display_mode = 'less_redacted' WHERE display_mode = 'all'");
    }

    public function down(): void
    {
        $this->execute("UPDATE kingdoms SET display_mode = 'all' WHERE display_mode = 'less_redacted'");
        $this->execute("UPDATE kingdoms_audit SET display_mode = 'all' WHERE display_mode = 'less_redacted'");
    }
}
