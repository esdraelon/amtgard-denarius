<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategoryPicker;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Resolves manager category input to a persisted {@see CategoryCatalog} category id. */
final class KingdomCategoryAssigner
{
    public function __construct(
        private readonly TaxonomyCategoryPicker $picker,
        private readonly TaxonomyCatalog $catalog,
        private readonly CategoryCatalog $categories,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function resolveForPattern(KingdomRecord $kingdom, array $body): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->resolve($kingdom, $body, null));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function resolveForReview(KingdomRecord $kingdom, array $body, int $amountCents): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->resolve($kingdom, $body, $amountCents));
    }

    public function displayFor(int $categoryId, TransactionFlow $ruleFlowHint): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->categories->displayFor($categoryId, $ruleFlowHint));
    }

    public function labelFor(int $categoryId): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->categories->labelFor($categoryId));
    }

    public function flowFor(int $categoryId, TransactionFlow $amountFlowHint): TransactionFlow
    {
        return DenariusLog::trace(__METHOD__, fn (): TransactionFlow => $this->categories->flowFor($categoryId, $amountFlowHint));
    }

    public function permitsFlow(int $categoryId, TransactionFlow $flow): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->categories->permitsFlow($categoryId, $flow));
    }

    public function isUncategorized(int $categoryId): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $categoryId === $this->categories->uncategorizedId());
    }

    public function lineageKeyFor(int $categoryId): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->categories->lineageKeyForId($categoryId));
    }

    public function isKingdomScoped(int $categoryId, int $kingdomId): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($categoryId, $kingdomId): bool {
            $key = $this->categories->lineageKeyForId($categoryId);

            return str_starts_with($key, 'k' . $kingdomId . '.');
        });
    }

    public function resolvedLabel(int $categoryId): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->categories->labelFor($categoryId));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function anchorAmountCents(array $body): ?int
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): ?int {
            if (! isset($body['pattern_anchor_amount_cents']) || $body['pattern_anchor_amount_cents'] === '') {
                return null;
            }

            return (int) $body['pattern_anchor_amount_cents'];
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolve(KingdomRecord $kingdom, array $body, ?int $amountCents): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $amountCents): int {
            $kingdomId = (int) $kingdom->getId();
            $categoryId = $this->parseCategoryId($body);
            $display = trim((string) ($body['category_display'] ?? ''));
            $legacySlug = trim((string) ($body['category'] ?? ''));

            if ($categoryId !== null && $categoryId > 0) {
                return $this->finalizeExisting($categoryId, $display);
            }

            if ($display !== '') {
                try {
                    $slug = $amountCents !== null
                        ? $this->picker->resolveForReview($legacySlug, $display, $amountCents)
                        : $this->picker->resolveForPattern($legacySlug, $display);
                    $id = $this->categories->currentIdForLineageKey($this->catalog->resolveSlug($slug));
                    if (! $this->displayLabelMatchesSlug($display, $slug)) {
                        $flow = $amountCents !== null
                            ? TransactionFlow::defaultFromSignedCents($amountCents)
                            : $this->flowFromBody($body);

                        return $this->forkOrCreateFromLabel($kingdomId, $display, $flow, $id);
                    }

                    return $id;
                } catch (\InvalidArgumentException) {
                    $flow = $amountCents !== null
                        ? TransactionFlow::defaultFromSignedCents($amountCents)
                        : $this->flowFromBody($body);

                    return $this->createCustomLineage($kingdomId, $body, $flow);
                }
            }

            if ($legacySlug !== '') {
                if ($amountCents !== null) {
                    $slug = $this->picker->resolveForReview($legacySlug, '', $amountCents);

                    return $this->categories->currentIdForLineageKey($this->catalog->resolveSlug($slug));
                }

                return $this->categories->resolveStoredSlugToId($legacySlug, $kingdomId);
            }

            throw new \InvalidArgumentException(
                $amountCents !== null
                    ? 'Choose a category from the list or type a new label.'
                    : 'Enter a category name for this pattern.',
            );
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parseCategoryId(array $body): ?int
    {
        if (isset($body['category_id']) && $body['category_id'] !== '') {
            return (int) $body['category_id'];
        }

        return null;
    }

    private function finalizeExisting(int $categoryId, string $display): int
    {
        if ($display === '') {
            return $categoryId;
        }
        $label = $this->labelFromDisplay($display);
        if ($label === '' || strcasecmp($this->categories->labelFor($categoryId), $label) === 0) {
            return $categoryId;
        }

        return $this->categories->forkLabel($categoryId, $label);
    }

    private function forkOrCreateFromLabel(int $kingdomId, string $display, TransactionFlow $flow, int $existingId): int
    {
        $label = $this->labelFromDisplay($display);
        if ($label === '') {
            return $existingId;
        }

        return $this->categories->forkLabel($existingId, $label);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createCustomLineage(int $kingdomId, array $body, TransactionFlow $flow): int
    {
        $label = $this->labelFromBody($body);
        if ($label === '') {
            throw new \InvalidArgumentException('Enter a category name.');
        }
        foreach ($this->categories->search($label, $flow, $kingdomId) as $match) {
            if (strcasecmp($match['label'], $label) === 0) {
                return (int) $match['id'];
            }
        }
        $lineageKey = $this->allocateLineageKey($kingdomId, $label);

        return $this->categories->createWithLabel($flow, $label, $lineageKey);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function labelFromBody(array $body): string
    {
        $display = trim((string) ($body['category_display'] ?? ''));
        if ($display !== '') {
            return $this->labelFromDisplay($display);
        }

        return trim((string) ($body['category'] ?? ''));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function flowFromBody(array $body): TransactionFlow
    {
        $anchor = $this->anchorAmountCents($body);
        if ($anchor !== null) {
            if ($anchor > 0) {
                return TransactionFlow::Income;
            }

            return TransactionFlow::Expense;
        }
        $display = trim((string) ($body['category_display'] ?? ''));
        if (preg_match('/^Income:/i', $display)) {
            return TransactionFlow::Income;
        }

        return TransactionFlow::Expense;
    }

    private function displayLabelMatchesSlug(string $display, string $slug): bool
    {
        $label = $this->labelFromDisplay($display);
        if ($label === '') {
            return true;
        }
        if (! $this->catalog->hasSlug($slug)) {
            return true;
        }

        return strcasecmp($this->catalog->label($slug), $label) === 0;
    }

    private function labelFromDisplay(string $display): string
    {
        $display = trim($display);
        if ($display === '') {
            return '';
        }
        if (preg_match('/^(?:Expense|Income):\s*(.+)$/i', $display, $matches)) {
            return trim($matches[1]);
        }

        return $display;
    }

    private function allocateLineageKey(int $kingdomId, string $label): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $label): string {
            $normalized = strtolower(trim($label));
            $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
            $normalized = trim($normalized, '_');
            if ($normalized === '') {
                $normalized = 'category';
            }
            $prefix = 'k' . $kingdomId . '.';
            $base = substr($normalized, 0, max(1, 64 - strlen($prefix)));
            $candidate = $prefix . $base;
            try {
                $this->categories->currentIdForLineageKey($candidate);

                return $this->allocateLineageKeyWithSuffix($kingdomId, $base, 2);
            } catch (\InvalidArgumentException) {
                return $candidate;
            }
        });
    }

    private function allocateLineageKeyWithSuffix(int $kingdomId, string $base, int $suffix): string
    {
        $prefix = 'k' . $kingdomId . '.';
        for (; $suffix < 1000; ++$suffix) {
            $tail = '_' . $suffix;
            $trimmed = substr($base, 0, max(1, 64 - strlen($prefix) - strlen($tail)));
            $candidate = $prefix . $trimmed . $tail;
            try {
                $this->categories->currentIdForLineageKey($candidate);
            } catch (\InvalidArgumentException) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not allocate a custom category lineage key.');
    }
}
