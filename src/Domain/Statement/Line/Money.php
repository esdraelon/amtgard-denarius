<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Line;

final class Money
{
    public static function centsFromDecimal(string $amount): int
    {
        $normalized = trim($amount);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        if (!preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            throw new \InvalidArgumentException('Amount is not a decimal string.');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $fraction = substr(str_pad($fraction, 3, '0'), 0, 3);
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        $roundUp = ((int) $fraction[2]) >= 5;
        if ($roundUp) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }
}
