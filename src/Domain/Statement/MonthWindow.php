<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthWindow
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Month must be between 1 and 12.');
        }
    }

    public static function current(\DateTimeImmutable $now): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($now): self {
            return new self((int) $now->format('Y'), (int) $now->format('n'));
        });
    }

    public static function fromQuery(?string $value, \DateTimeImmutable $now): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($value, $now): self {
            if ($value === null || !preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
                return self::current($now);
            }

            $month = (int) $matches[2];
            if ($month < 1 || $month > 12) {
                return self::current($now);
            }

            return new self((int) $matches[1], $month);
        });
    }

    public function key(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return sprintf('%04d-%02d', $this->year, $this->month);
        });
    }

    public function startDate(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->key() . '-01';
        });
    }

    public function endDate(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));

            return $start->modify('last day of this month')->format('Y-m-d');
        });
    }

    public function contains(string $isoDate): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($isoDate): bool {
            return str_starts_with($isoDate, $this->key());
        });
    }

    public function previous(): self
    {
        return DenariusLog::trace(__METHOD__, function (): self {
            if ($this->month === 1) {
                return new self($this->year - 1, 12);
            }

            return new self($this->year, $this->month - 1);
        });
    }

    public function next(): self
    {
        return DenariusLog::trace(__METHOD__, function (): self {
            if ($this->month === 12) {
                return new self($this->year + 1, 1);
            }

            return new self($this->year, $this->month + 1);
        });
    }
}
