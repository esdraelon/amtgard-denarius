<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Support;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class PreviousMonthWindow
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function startsAt(): int
    {
        return DenariusLog::trace(__METHOD__, function (): int {
            return $this->utc()->modify('first day of previous month')->setTime(0, 0, 0)->getTimestamp();
        });
    }

    public function endsAt(): int
    {
        return DenariusLog::trace(__METHOD__, function (): int {
            return $this->utc()->setTime(23, 59, 59)->getTimestamp();
        });
    }

    private function utc(): \DateTimeImmutable
    {
        return DenariusLog::trace(__METHOD__, function (): \DateTimeImmutable {
            return $this->now->setTimezone(new \DateTimeZone('UTC'));
        });
    }
}
