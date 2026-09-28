<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Ork\OrkKingdom;

interface OrkKingdomClient
{
    /**
     * @return list<OrkKingdom>
     */
    public function listKingdoms(): array;
}
