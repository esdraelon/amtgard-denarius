<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final readonly class ProviderAccount
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $lastFour,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
