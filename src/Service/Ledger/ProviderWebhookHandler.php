<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class ProviderWebhookHandler
{
    public function __construct(
        private readonly LedgerProviderRegistry $providers,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly LedgerNoticeRegistry $notices,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function signatureHeader(string $providerId): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($providerId): string {
            return $this->providers->find($providerId)->signatureHeader();
        });
    }

    public function handle(string $providerId, string $body, ?string $signature, int $now): bool
    {
        $method = __METHOD__;

        return DenariusLog::trace(__METHOD__, function () use ($providerId, $body, $signature, $now, $method): bool {
            $provider = $this->providers->find($providerId);
            $notice = $provider->notice($body, $signature, $now);
            if (!$notice->accepted) {
                DenariusLog::warnBranch('webhook_auth_denied', $method, [
                    'provider_id' => $providerId,
                ]);

                return false;
            }

            DenariusLog::debugBranch('webhook_accepted', $method, [
                'provider_id' => $providerId,
                'action' => $notice->action,
            ]);

            return $this->dispatch($provider->id(), $notice->enrollmentId, $notice->action);
        });
    }

    private function dispatch(string $providerId, string $enrollmentId, string $action): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($providerId, $enrollmentId, $action): bool {
            if ($enrollmentId === '') {
                return true;
            }

            $kingdom = $this->kingdoms->findByProviderEnrollment($providerId, $enrollmentId);
            if ($kingdom === null) {
                return true;
            }

            $this->notices->find($action)->apply($kingdom);

            return true;
        });
    }
}
