<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Field;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RequiredSettings
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    public function ready(array $values, array $keys): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($values, $keys): bool {
            foreach ($keys as $key) {
                if ($this->blank($values[$key] ?? null)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function blank(mixed $value): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): bool {
            return trim((string) $value) === '';
        });
    }
}
