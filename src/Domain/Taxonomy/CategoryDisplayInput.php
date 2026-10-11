<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: parse manager category display strings (flow prefix + label). */
final class CategoryDisplayInput
{
    public static function labelFromDisplay(string $display): string
    {
        $display = trim($display);
        if ($display === '') {
            return '';
        }
        if (preg_match('/^(?:Expense|Income|Transfer)\s*:\s*(.+)$/iu', $display, $matches) === 1) {
            return trim($matches[1]);
        }

        return $display;
    }

    /**
     * @return array{flow: TransactionFlow, label: string}|null
     */
    public static function parsePrefixedDisplay(string $display): ?array
    {
        $display = trim($display);
        if ($display === '') {
            return null;
        }
        if (preg_match('/^Expense:\s*(.+)$/iu', $display, $matches) === 1) {
            return ['flow' => TransactionFlow::Expense, 'label' => trim($matches[1])];
        }
        if (preg_match('/^Income:\s*(.+)$/iu', $display, $matches) === 1) {
            return ['flow' => TransactionFlow::Income, 'label' => trim($matches[1])];
        }
        if (preg_match('/^Transfer:\s*(.+)$/iu', $display, $matches) === 1) {
            return ['flow' => TransactionFlow::Transfer, 'label' => trim($matches[1])];
        }

        return null;
    }

    public static function flowHintFromDisplay(string $display): TransactionFlow
    {
        if (preg_match('/^Income:/iu', trim($display))) {
            return TransactionFlow::Income;
        }
        if (preg_match('/^Transfer:/iu', trim($display))) {
            return TransactionFlow::Transfer;
        }

        return TransactionFlow::Expense;
    }
}
