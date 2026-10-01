<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SimpleFinReturnException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function reason(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->reason;
        });
    }
}
