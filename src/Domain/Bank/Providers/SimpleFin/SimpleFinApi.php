<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\SimpleFin;

interface SimpleFinApi
{
    public function claim(string $claimUrl): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array;
}
