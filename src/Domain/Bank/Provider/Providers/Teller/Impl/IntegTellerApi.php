<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** DEV_INTEG: canned Teller accounts and transactions (Fake Object). */
final class IntegTellerApi implements TellerApi
{
    public function accounts(string $accessToken): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): array {
            DenariusLog::infoBranch('integ_teller_stub_answered', $method, ['operation' => 'accounts']);

            return [['id' => 'acc_integ_1', 'name' => 'Integ Checking', 'type' => 'depository', 'last_four' => '4242']];
        });
    }

    public function transactions(string $accessToken, string $accountId, ?string $fromId): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $fromId): array {
            DenariusLog::infoBranch('integ_teller_stub_answered', $method, ['operation' => 'transactions', 'from_id' => $fromId]);

            if ($fromId === 'txn_integ_2') {
                return [];
            }
            if ($fromId === 'txn_integ_1') {
                return [[
                    'id' => 'txn_integ_2',
                    'date' => '2026-09-03',
                    'amount' => '25.00',
                    'description' => 'Integ patron',
                    'status' => 'posted',
                    'details' => ['category' => 'income', 'counterparty' => ['name' => 'Patron']],
                ]];
            }

            return [[
                'id' => 'txn_integ_1',
                'date' => '2026-09-02',
                'amount' => '-12.50',
                'description' => 'Integ supplies',
                'status' => 'posted',
                'details' => ['category' => 'office'],
            ]];
        });
    }
}
