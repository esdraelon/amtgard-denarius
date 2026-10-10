<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Resolves manager typeahead input (hidden slug and/or display label) to a taxonomy slug. */
final class TaxonomyCategoryPicker
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function resolveForPattern(string $hiddenSlug, string $displayValue): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($hiddenSlug, $displayValue): string {
            $slug = $this->resolve($hiddenSlug, $displayValue, null);
            if (! $this->catalog->hasSlug($slug)) {
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            if (str_starts_with($slug, 'system.') || $slug === 'uncategorized') {
                throw new \InvalidArgumentException(
                    trim($displayValue) !== ''
                        ? 'Pick a category from the suggestions. Patterns cannot use Uncategorized.'
                        : 'Choose an assignable category for this pattern.',
                );
            }

            return $slug;
        });
    }

    public function resolveForReview(string $hiddenSlug, string $displayValue, int $amountCents): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($hiddenSlug, $displayValue, $amountCents): string {
            $flow = TransactionFlow::defaultFromSignedCents($amountCents);
            $slug = $this->resolve($hiddenSlug, $displayValue, $flow);
            if (! $this->catalog->hasSlug($slug)) {
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            if (str_starts_with($slug, 'system.')) {
                throw new \InvalidArgumentException('System categories cannot be assigned manually.');
            }
            if (! $this->catalog->permitsFlow($slug, $flow)) {
                throw new \InvalidArgumentException('That category does not match this transaction\'s direction.');
            }

            return $slug;
        });
    }

    private function resolve(string $hiddenSlug, string $displayValue, ?TransactionFlow $flowHint): string
    {
        $displayValue = trim($displayValue);
        $hiddenSlug = trim($hiddenSlug);
        if ($hiddenSlug !== '') {
            $resolvedHidden = $this->catalog->resolveSlug($hiddenSlug);
            $displayOverridesBlockedHidden = $displayValue !== ''
                && ($resolvedHidden === 'uncategorized' || str_starts_with($resolvedHidden, 'system.'));
            if (! $displayOverridesBlockedHidden) {
                return $resolvedHidden;
            }
        }

        if ($displayValue === '') {
            throw new \InvalidArgumentException('Choose a category from the list.');
        }

        if (str_contains($displayValue, '.')) {
            $candidate = $this->catalog->resolveSlug($displayValue);

            return $candidate;
        }

        $parsed = $this->parseDisplay($displayValue);
        if ($parsed !== null) {
            $slug = $this->matchLabel($parsed['label'], $parsed['flow']);
            if ($slug !== null) {
                return $slug;
            }
        }

        $slug = $this->matchLabel($displayValue, $flowHint);
        if ($slug !== null) {
            return $slug;
        }

        throw new \InvalidArgumentException(
            'Pick a category from the suggestions, or type Expense: followed by an exact taxonomy label.',
        );
    }

    /**
     * @return array{flow: TransactionFlow, label: string}|null
     */
    private function parseDisplay(string $displayValue): ?array
    {
        if (! preg_match('/^(Expense|Income):\s*(.+)$/i', $displayValue, $matches)) {
            return null;
        }

        $flow = TransactionFlow::fromStored(strtolower($matches[1]));

        return [
            'flow' => $flow ?? TransactionFlow::Expense,
            'label' => trim($matches[2]),
        ];
    }

    private function matchLabel(string $label, ?TransactionFlow $flowHint): ?string
    {
        $needle = strtolower(trim($label));
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach ($this->catalog->assignableDefinitions() as $definition) {
            if (strtolower($definition->label) !== $needle) {
                continue;
            }
            foreach ($definition->flows as $flow) {
                if ($flowHint !== null && $flow !== $flowHint) {
                    continue;
                }
                $matches[$definition->slug] = true;
            }
        }

        if (count($matches) === 1) {
            return array_key_first($matches);
        }

        return null;
    }
}
