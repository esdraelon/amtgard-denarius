<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Env;

final class EnvFragment
{
    /**
     * @param array<string, string> $values
     */
    public function write(string $path, array $values): void
    {
        if (!$this->directory($path) || file_put_contents($path, $this->body($values)) === false) {
            throw new \RuntimeException('Unable to write the provider env file.');
        }
        chmod($path, 0600);
    }

    private function directory(string $path): bool
    {
        return is_dir(dirname($path));
    }

    /**
     * @param array<string, string> $values
     */
    private function body(array $values): string
    {
        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . $this->quoted($value);
        }

        return implode("\n", $lines) . "\n";
    }

    private function quoted(string $value): string
    {
        return '"' . str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
    }
}
