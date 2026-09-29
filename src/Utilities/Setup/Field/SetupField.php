<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Field;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SetupField
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly bool $hidden,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function key(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->key;
        });
    }

    public function label(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->label;
        });
    }

    public function hidden(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return $this->hidden;
        });
    }
}
