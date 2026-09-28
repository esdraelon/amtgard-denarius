<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Ork;

final class OrkKingdom
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
