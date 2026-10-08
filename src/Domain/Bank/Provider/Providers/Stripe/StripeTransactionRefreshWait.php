<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Blocks until Stripe finishes the Financial Connections transaction refresh. */
final class StripeTransactionRefreshWait
{
    public function __construct(
        private readonly StripeApi $api,
        private readonly int $pollMicros = 500_000,
        private readonly int $timeoutSeconds = 45,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function beforeListing(string $accountId): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($accountId, $method): void {
            $deadline = time() + $this->timeoutSeconds;
            while (time() < $deadline) {
                $status = $this->refreshStatus($accountId);
                if ($status === 'succeeded') {
                    DenariusLog::debugBranch('stripe_transaction_refresh_ready', $method, [
                        'account_id' => $accountId,
                    ]);

                    return;
                }
                if ($status === 'pending') {
                    usleep($this->pollMicros);

                    continue;
                }
                DenariusLog::debugBranch('stripe_transaction_refresh_requested', $method, [
                    'account_id' => $accountId,
                    'prior_status' => $status,
                ]);
                $this->api->refreshTransactions($accountId);
                usleep($this->pollMicros);
            }

            throw new \RuntimeException('Stripe transaction refresh timed out.');
        });
    }

    private function refreshStatus(string $accountId): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($accountId): string {
            $account = $this->api->account($accountId);
            $refresh = is_array($account['transaction_refresh'] ?? null) ? $account['transaction_refresh'] : [];

            return (string) ($refresh['status'] ?? '');
        });
    }
}
