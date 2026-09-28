<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank\SimpleFin;

interface SimpleFinApi
{
    public function claim(string $claimUrl): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array;
}
