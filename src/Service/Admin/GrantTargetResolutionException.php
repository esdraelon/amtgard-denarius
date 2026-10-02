<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

final class GrantTargetResolutionException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
