<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Support;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class InstitutionSupport
{
    private const YES = 'yes';

    private const NO = 'no';

    private const UNKNOWN = 'unknown';

    private function __construct(private readonly string $verdict)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function yes(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self(self::YES);
        });
    }

    public static function no(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self(self::NO);
        });
    }

    public static function unknown(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self(self::UNKNOWN);
        });
    }

    public function rejected(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return $this->verdict === self::NO;
        });
    }

    public function verdict(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->verdict;
        });
    }
}
