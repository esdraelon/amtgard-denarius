<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final class InstitutionSupport
{
    private const YES = 'yes';

    private const NO = 'no';

    private const UNKNOWN = 'unknown';

    private function __construct(private readonly string $verdict)
    {
    }

    public static function yes(): self
    {
        return new self(self::YES);
    }

    public static function no(): self
    {
        return new self(self::NO);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public function rejected(): bool
    {
        return $this->verdict === self::NO;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }
}
