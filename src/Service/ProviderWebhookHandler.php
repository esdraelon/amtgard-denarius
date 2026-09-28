<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Bank\LedgerNoticeRegistry;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Contract\KingdomStore;

final class ProviderWebhookHandler
{
    public function __construct(
        private readonly LedgerProvider $provider,
        private readonly KingdomStore $kingdoms,
        private readonly LedgerNoticeRegistry $notices,
    ) {
    }

    public function signatureHeader(): string
    {
        return $this->provider->signatureHeader();
    }

    public function handle(string $body, ?string $signature, int $now): bool
    {
        $notice = $this->provider->notice($body, $signature, $now);
        if (!$notice->accepted) {
            return false;
        }

        return $this->dispatch($notice->enrollmentId, $notice->action);
    }

    private function dispatch(string $enrollmentId, string $action): bool
    {
        if ($enrollmentId === '') {
            return true;
        }

        $kingdom = $this->kingdoms->findByEnrollmentId($enrollmentId);
        if ($kingdom === null) {
            return true;
        }

        $this->notices->find($action)->apply($kingdom);

        return true;
    }
}
