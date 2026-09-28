<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

final class Viewer
{
    public function __construct(
        public readonly string $idpUserId,
        public readonly ?int $orkKingdomId,
    ) {
    }
}
