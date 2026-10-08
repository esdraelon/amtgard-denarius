<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** DEV_INTEG: canned Stripe Financial Connections payloads (Fake Object). */
final class IntegStripeApi implements StripeApi
{
    public function createCustomer(string $kingdomKey): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdomKey): array {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, ['operation' => 'createCustomer', 'kingdom_key' => $kingdomKey]);

            return ['id' => 'cus_integ'];
        });
    }

    public function createSession(string $customerId): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): array {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, ['operation' => 'createSession']);

            return ['client_secret' => 'fcs_integ_test_secret'];
        });
    }

    public function accounts(string $customerId): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): array {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, ['operation' => 'accounts']);

            return [
                ['id' => 'fca_integ_1', 'display_name' => 'Integ Checking', 'subcategory' => 'checking', 'last4' => '4242'],
            ];
        });
    }

    public function subscribe(string $accountId): void
    {
        $method = __METHOD__;
        DenariusLog::trace($method, function () use ($method, $accountId): void {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, ['operation' => 'subscribe', 'account_id' => $accountId]);
        });
    }

    public function account(string $accountId): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $accountId): array {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, ['operation' => 'account']);

            return [
                'id' => $accountId,
                'transaction_refresh' => ['status' => 'succeeded'],
            ];
        });
    }

    public function refreshTransactions(string $accountId): array
    {
        return $this->account($accountId);
    }

    public function transactions(string $accountId, int $startsAt, int $endsAt): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $accountId, $startsAt, $endsAt): array {
            DenariusLog::infoBranch('integ_stripe_stub_answered', $method, [
                'operation' => 'transactions',
                'account_id' => $accountId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $posted = max($startsAt, $endsAt - 86400);

            return [[
                'id' => 'txn_integ_stripe',
                'amount' => 1250,
                'status' => 'posted',
                'transacted_at' => $posted,
                'description' => 'Integ Stripe txn',
            ]];
        });
    }
}
