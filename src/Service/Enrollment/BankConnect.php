<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class BankConnect
{
    public function __construct(private readonly LedgerProviderRegistry $providers)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function idle(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return $this->none([], '');
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function launch(string $kingdomKey, array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomKey, $body): array {
            $skipped = $this->skipped($body);
            $provider = $this->providers->next($skipped);
            if ($provider->id() === '') {
                return $this->none($skipped, 'exhausted');
            }

            $id = $provider->id();

            return [
                'available' => true,
                'autostart' => $id !== 'simplefin',
                'reason' => '',
                'provider' => $id,
                'skipped' => $skipped,
                'config' => $provider->connectConfig($kingdomKey),
            ];
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function offer(string $kingdomKey, array $body): array
    {
        return $this->launch($kingdomKey, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function blank(string $institution): array
    {
        return $this->idle();
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function skipped(array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): array {
            $ids = $this->listed($body['skipped'] ?? null);
            $current = $this->current($body);
            Optional::ofNullable($current)->ifPresent(function (string $id) use (&$ids): void {
                $ids[$id] = $id;
            });

            return array_values($ids);
        });
    }

    /**
     * @return array<string, string>
     */
    private function listed(mixed $skipped): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($skipped): array {
            $ids = [];
            foreach ($this->rows($skipped) as $id) {
                $name = trim((string) $id);
                if ($name !== '') {
                    $ids[$name] = $name;
                }
            }

            return $ids;
        });
    }

    /**
     * @return list<mixed>
     */
    private function rows(mixed $skipped): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($skipped): array {
            return is_array($skipped) ? $skipped : [];
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function current(array $body): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): ?string {
            if ((string) ($body['skip'] ?? '') !== '1') {
                return null;
            }
            $current = trim((string) ($body['current'] ?? ''));

            return $current === '' ? null : $current;
        });
    }

    /**
     * @param list<string> $skipped
     * @return array<string, mixed>
     */
    private function none(array $skipped, string $reason): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($skipped, $reason): array {
            return [
                'available' => false,
                'autostart' => false,
                'reason' => $reason,
                'provider' => '',
                'skipped' => $skipped,
                'config' => [],
            ];
        });
    }
}
