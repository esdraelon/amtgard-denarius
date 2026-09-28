<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

final class MonthWindow
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Month must be between 1 and 12.');
        }
    }

    public static function current(\DateTimeImmutable $now): self
    {
        return new self((int) $now->format('Y'), (int) $now->format('n'));
    }

    public static function fromQuery(?string $value, \DateTimeImmutable $now): self
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            return self::current($now);
        }

        $month = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            return self::current($now);
        }

        return new self((int) $matches[1], $month);
    }

    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function startDate(): string
    {
        return $this->key() . '-01';
    }

    public function contains(string $isoDate): bool
    {
        return str_starts_with($isoDate, $this->key());
    }

    public function previous(): self
    {
        if ($this->month === 1) {
            return new self($this->year - 1, 12);
        }

        return new self($this->year, $this->month - 1);
    }

    public function next(): self
    {
        if ($this->month === 12) {
            return new self($this->year + 1, 1);
        }

        return new self($this->year, $this->month + 1);
    }
}
