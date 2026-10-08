<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** DEV_INTEG: canned Plaid link and transaction payloads (Fake Object). */
final class IntegPlaidApi implements PlaidApi
{
    /** @var array<string, mixed> */
    private readonly array $verificationKey;

    public function __construct()
    {
        $entered = DenariusLog::enter(__METHOD__);
        $this->verificationKey = IntegPlaidVerificationKey::jwk();
    }

    public function institutions(string $query): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $query): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'institutions', 'query' => $query]);

            return [['name' => 'Integ Bank', 'institution_id' => 'ins_integ']];
        });
    }

    public function linkToken(string $kingdomKey): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdomKey): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'linkToken', 'kingdom_key' => $kingdomKey]);

            return ['link_token' => 'link-integ-sandbox'];
        });
    }

    public function exchange(string $publicToken): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $publicToken): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'exchange']);

            return [
                'access_token' => 'access-integ',
                'item_id' => 'item_integ',
                'public_token' => $publicToken,
            ];
        });
    }

    public function accounts(string $accessToken): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'accounts']);

            return [
                ['account_id' => 'acc_integ', 'name' => 'Integ Checking', 'type' => 'depository', 'mask' => '4242'],
            ];
        });
    }

    public function transactions(string $accessToken): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'transactions']);

            return [[
                'transaction_id' => 'tx_integ_plaid',
                'account_id' => 'acc_integ',
                'amount' => 12.5,
                'date' => '2026-09-02',
                'name' => 'Integ Plaid txn',
                'pending' => false,
            ]];
        });
    }

    public function verificationKey(string $keyId): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $keyId): array {
            DenariusLog::infoBranch('integ_plaid_stub_answered', $method, ['operation' => 'verificationKey', 'key_id' => $keyId]);

            return $this->verificationKey;
        });
    }
}
