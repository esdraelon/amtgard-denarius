<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;

final class StubOrkGetKingdomsGateway implements OrkGetKingdomsGateway
{
    public function __construct(private readonly ?string $json = null)
    {
    }

    public function getKingdomsJson(): ?string
    {
        return $this->json;
    }
}
