<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Domain\Bank\Providers\Registry\LedgerProviderRegistry;
use Optional\Optional;

final class BankConnect
{
    public function __construct(private readonly LedgerProviderRegistry $providers)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function blank(string $institution): array
    {
        return $this->none(trim($institution), [], '');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function offer(string $kingdomKey, array $body): array
    {
        $institution = trim((string) ($body['institution'] ?? ''));
        $skipped = $this->skipped($body);
        if ($institution === '') {
            return $this->none($institution, $skipped, 'institution');
        }

        $provider = $this->providers->resolve($institution, $skipped);
        if ($provider->id() === '') {
            return $this->none($institution, $skipped, 'exhausted');
        }

        return [
            'available' => true,
            'reason' => '',
            'provider' => $provider->id(),
            'institution' => $institution,
            'skipped' => $skipped,
            'config' => $provider->connectConfig($kingdomKey),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function skipped(array $body): array
    {
        $ids = $this->listed($body['skipped'] ?? null);
        $current = $this->current($body);
        Optional::ofNullable($current)->ifPresent(function (string $id) use (&$ids): void {
            $ids[$id] = $id;
        });

        return array_values($ids);
    }

    /**
     * @return array<string, string>
     */
    private function listed(mixed $skipped): array
    {
        $ids = [];
        foreach ($this->rows($skipped) as $id) {
            $name = trim((string) $id);
            if ($name !== '') {
                $ids[$name] = $name;
            }
        }

        return $ids;
    }

    /**
     * @return list<mixed>
     */
    private function rows(mixed $skipped): array
    {
        return is_array($skipped) ? $skipped : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function current(array $body): ?string
    {
        if ((string) ($body['skip'] ?? '') !== '1') {
            return null;
        }
        $current = trim((string) ($body['current'] ?? ''));

        return $current === '' ? null : $current;
    }

    /**
     * @param list<string> $skipped
     * @return array<string, mixed>
     */
    private function none(string $institution, array $skipped, string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'provider' => '',
            'institution' => $institution,
            'skipped' => $skipped,
            'config' => [],
        ];
    }
}
