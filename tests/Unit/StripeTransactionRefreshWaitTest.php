<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeTransactionRefreshWait;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class StripeTransactionRefreshWaitTest extends AmtgardTestCase
{
    public function testWaitsUntilRefreshSucceeds(): void
    {
        $api = new ScriptedRefreshStripe(['pending', 'succeeded']);
        (new StripeTransactionRefreshWait($api, pollMicros: 0, timeoutSeconds: 2))->beforeListing('fca_1');
        $this->assertSame(2, $api->reads);
        $this->assertSame(0, $api->refreshes);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'stripe_transaction_refresh_ready', StripeTransactionRefreshWait::class . '::beforeListing');
    }

    public function testRequestsARefreshWhenNoneIsRunning(): void
    {
        $api = new ScriptedRefreshStripe(['failed', 'succeeded']);
        (new StripeTransactionRefreshWait($api, pollMicros: 0, timeoutSeconds: 2))->beforeListing('fca_1');
        $this->assertSame(['fca_1'], $api->refreshed);
        $this->assertSame(1, $api->refreshes);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'stripe_transaction_refresh_requested', StripeTransactionRefreshWait::class . '::beforeListing');
    }

    public function testTimesOutWhenTheRefreshNeverFinishes(): void
    {
        $api = new ScriptedRefreshStripe(['pending']);
        $wait = new StripeTransactionRefreshWait($api, pollMicros: 0, timeoutSeconds: 0);
        $this->assertThrows(\RuntimeException::class, fn () => $wait->beforeListing('fca_1'));
        $this->assertSame(0, $api->reads);
    }
}

final class ScriptedRefreshStripe implements StripeApi
{
    public int $reads = 0;

    public int $refreshes = 0;

    /** @var list<string> */
    public array $refreshed = [];

    /**
     * @param list<string> $statuses
     */
    public function __construct(private readonly array $statuses)
    {
    }

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
        $status = $this->statuses[min($this->reads, count($this->statuses) - 1)];
        ++$this->reads;

        return $status === 'failed' ? ['transaction_refresh' => 'none'] : ['transaction_refresh' => ['status' => $status]];
    }

    public function refreshTransactions(string $accountId): array
    {
        ++$this->refreshes;
        $this->refreshed[] = $accountId;

        return [];
    }

    public function transactions(string $accountId, int $startsAt, int $endsAt): array
    {
        return [];
    }
}
