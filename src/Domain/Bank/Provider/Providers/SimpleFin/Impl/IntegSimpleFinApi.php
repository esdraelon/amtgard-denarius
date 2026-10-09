<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** DEV_INTEG: canned SimpleFIN claim and account payloads (Fake Object). */
final class IntegSimpleFinApi implements SimpleFinApi
{
    private const ACCESS_URL = 'https://integ-user:integ-pass@bridge.simplefin.org/simplefin';

    public function claim(string $claimUrl): string
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $claimUrl): string {
            DenariusLog::infoBranch('integ_simplefin_stub_answered', $method, ['operation' => 'claim', 'claim_url' => $claimUrl]);

            return self::ACCESS_URL;
        });
    }

    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $startsAt, $endsAt): array {
            DenariusLog::infoBranch('integ_simplefin_stub_answered', $method, [
                'operation' => 'accounts',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $inside = max($startsAt, $endsAt - 86400);

            return [[
                'id' => 'acc_integ_sf',
                'name' => 'Integ SimpleFIN Checking',
                'transactions' => [[
                    'id' => 'tx_integ_sf',
                    'posted' => $inside,
                    'amount' => '-4.50',
                    'description' => 'Integ coffee',
                    'pending' => false,
                ]],
            ]];
        });
    }
}
