<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class Viewer
{
    public function __construct(
        public readonly string $idpUserId,
        public readonly ?int $orkKingdomId,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
