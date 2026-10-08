<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe;

interface StripeApi
{
    /**
     * @return array<string, mixed>
     */
    public function createCustomer(string $kingdomKey): array;

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $customerId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $customerId): array;

    public function subscribe(string $accountId): void;

    /**
     * @return array<string, mixed>
     */
    public function account(string $accountId): array;

    /**
     * @return array<string, mixed>
     */
    public function refreshTransactions(string $accountId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountId, int $startsAt, int $endsAt): array;
}
