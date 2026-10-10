<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Immutable row from `categories` (historical label frozen on the id). */
final class CategorySnapshot
{
    /**
     * @param list<TransactionFlow> $flows
     */
    public function __construct(
        public readonly int $id,
        public readonly string $lineageKey,
        public readonly string $label,
        public readonly array $flows,
        public readonly ?CategorySensitivity $sensitivity,
        public readonly ?string $summaryParentLabel,
        public readonly bool $assignable,
    ) {
    }

    public function permits(TransactionFlow $flow): bool
    {
        if ($this->flows === []) {
            return true;
        }

        return in_array($flow, $this->flows, true);
    }

    public function primaryFlow(): TransactionFlow
    {
        return $this->flows[0] ?? TransactionFlow::Expense;
    }
}
