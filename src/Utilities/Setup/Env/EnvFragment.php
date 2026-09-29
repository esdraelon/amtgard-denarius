<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Env;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class EnvFragment
{
    /**
     * @param array<string, string> $values
     */
    public function write(string $path, array $values): void
    {
        DenariusLog::trace(__METHOD__, function () use ($path, $values): mixed {
            if (!$this->directory($path) || file_put_contents($path, $this->body($values)) === false) {
                throw new \RuntimeException('Unable to write the provider env file.');
            }
            chmod($path, 0600);

            return null;
        });
    }

    private function directory(string $path): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($path): bool {
            return is_dir(dirname($path));
        });
    }

    /**
     * @param array<string, string> $values
     */
    private function body(array $values): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($values): string {
            $lines = [];
            foreach ($values as $key => $value) {
                $lines[] = $key . '=' . $this->quoted($value);
            }

            return implode("\n", $lines) . "\n";
        });
    }

    private function quoted(string $value): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): string {
            return '"' . str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
        });
    }
}
