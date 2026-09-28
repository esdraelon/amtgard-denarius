<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

interface PlaidApi
{
    /**
     * @return list<array<string, mixed>>
     */
    public function institutions(string $query): array;

    /**
     * @return array<string, mixed>
     */
    public function linkToken(string $kingdomKey): array;

    /**
     * @return array<string, mixed>
     */
    public function exchange(string $publicToken): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $accessToken): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accessToken): array;

    /**
     * @return array<string, mixed>
     */
    public function verificationKey(string $keyId): array;
}
