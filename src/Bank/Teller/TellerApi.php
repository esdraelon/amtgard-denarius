<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank\Teller;

interface TellerApi
{
    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $accessToken): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accessToken, string $accountId, ?string $fromId): array;
}
