<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

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
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountId, int $startsAt, int $endsAt): array;
}
