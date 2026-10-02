<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final readonly class ConnectedEnrollment
{
    public function __construct(
        public string $accessToken,
        public string $enrollmentId,
        public string $institutionName,
        public string $provider = '',
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
