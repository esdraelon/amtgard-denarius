<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeTransactionRefreshWait;
use Amtgard\PHPUnit\AmtgardTestCase;

final class StripeTransactionRefreshWaitTest extends AmtgardTestCase
{
    public function testWaitsUntilRefreshSucceeds(): void
    {
        $api = new PendingThenSucceededStripe();
        (new StripeTransactionRefreshWait($api, pollMicros: 0, timeoutSeconds: 2))->beforeListing('fca_1');
        $this->assertSame(2, $api->reads);
    }
}

final class PendingThenSucceededStripe implements StripeApi
{
    public int $reads = 0;

    public function createCustomer(string $kingdomKey): array
    {
        return [];
    }

    public function createSession(string $customerId): array
    {
        return [];
    }

    public function accounts(string $customerId): array
    {
        return [];
    }

    public function subscribe(string $accountId): void
    {
    }

    public function account(string $accountId): array
    {
        ++$this->reads;

        return [
            'transaction_refresh' => ['status' => $this->reads >= 2 ? 'succeeded' : 'pending'],
        ];
    }

    public function refreshTransactions(string $accountId): array
    {
        return $this->account($accountId);
    }

    public function transactions(string $accountId, int $startsAt, int $endsAt): array
    {
        return [];
    }
}
