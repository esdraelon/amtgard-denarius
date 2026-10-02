<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SimpleFinHost
{
    /**
     * @param list<string> $hosts
     */
    public function __construct(
        private readonly array $hosts,
        private readonly bool $allowHttp = false,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function accepts(string $url): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($url): bool {
            $parts = parse_url($url);
            if (!is_array($parts)) {
                return false;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!$this->scheme($scheme) || $host === '') {
                return false;
            }

            foreach ($this->hosts as $allowed) {
                $suffix = strtolower($allowed);
                if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function scheme(string $scheme): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($scheme): bool {
            return $scheme === 'https' || ($this->allowHttp && $scheme === 'http');
        });
    }
}
