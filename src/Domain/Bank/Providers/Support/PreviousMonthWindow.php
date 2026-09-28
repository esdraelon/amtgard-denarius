<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Support;

final class PreviousMonthWindow
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public function startsAt(): int
    {
        return $this->utc()->modify('first day of previous month')->setTime(0, 0, 0)->getTimestamp();
    }

    public function endsAt(): int
    {
        return $this->utc()->setTime(23, 59, 59)->getTimestamp();
    }

    private function utc(): \DateTimeImmutable
    {
        return $this->now->setTimezone(new \DateTimeZone('UTC'));
    }
}
