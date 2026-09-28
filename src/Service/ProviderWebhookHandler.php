<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Bank\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;

final class ProviderWebhookHandler
{
    public function __construct(
        private readonly LedgerProviderRegistry $providers,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly LedgerNoticeRegistry $notices,
    ) {
    }

    public function signatureHeader(string $providerId): string
    {
        return $this->providers->find($providerId)->signatureHeader();
    }

    public function handle(string $providerId, string $body, ?string $signature, int $now): bool
    {
        $provider = $this->providers->find($providerId);
        $notice = $provider->notice($body, $signature, $now);
        if (!$notice->accepted) {
            return false;
        }

        return $this->dispatch($provider->id(), $notice->enrollmentId, $notice->action);
    }

    private function dispatch(string $providerId, string $enrollmentId, string $action): bool
    {
        if ($enrollmentId === '') {
            return true;
        }

        $kingdom = $this->kingdoms->findByProviderEnrollment($providerId, $enrollmentId);
        if ($kingdom === null) {
            return true;
        }

        $this->notices->find($action)->apply($kingdom);

        return true;
    }
}
