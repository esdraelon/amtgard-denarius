<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final readonly class ProviderNotice
{
    public const REFRESH = 'refresh';

    public const DISCONNECT = 'disconnect';

    public function __construct(
        public bool $accepted,
        public string $enrollmentId = '',
        public string $action = '',
    ) {
    }

    public static function rejected(): self
    {
        return new self(false);
    }

    public static function acknowledged(): self
    {
        return new self(true);
    }

    public static function of(string $enrollmentId, string $action): self
    {
        return new self(true, $enrollmentId, $action);
    }
}
