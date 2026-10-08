<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

/** Fetches raw JSON from ORK {@see Kingdom/GetKingdoms}. */
interface OrkGetKingdomsGateway
{
    public function getKingdomsJson(): ?string;
}
