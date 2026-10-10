<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSelection;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionReviewRow;
use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternPrefill;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager publication review queue from ledger candidates. */
final class TransactionReviewQueue
{
    public function __construct(
        private readonly KingdomPublicationLineSource $lines,
        private readonly CategoryCatalog $categories,
        private readonly KingdomCategoryAssigner $categoryAssigner,
        private readonly KingdomPatternPrefill $patternPrefill,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * Single-pass manage review: resolve month and rows from one candidate load.
     *
     * @return array{0: MonthWindow, 1: list<array<string, mixed>>}
     */
    public function manageReview(KingdomRecord $kingdom, string $requestedMonth, bool $uncategorizedOnly): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom, $requestedMonth, $uncategorizedOnly): array {
            $candidates = $this->lines->candidates($kingdom);
            $month = $this->resolveReviewMonth($candidates, $kingdom, $requestedMonth);
            $rows = $this->buildManageRows($candidates, $kingdom, $month, $uncategorizedOnly, $method);

            return [$month, $rows];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsForManage(KingdomRecord $kingdom, MonthWindow $month, bool $uncategorizedOnly = false): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom, $month, $uncategorizedOnly): array {
            return $this->buildManageRows($this->lines->candidates($kingdom), $kingdom, $month, $uncategorizedOnly, $method);
        });
    }

    /** Requested `YYYY-MM` when present; otherwise the latest month with review rows. */
    public function reviewMonth(KingdomRecord $kingdom, string $requested): MonthWindow
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $requested): MonthWindow {
            return $this->resolveReviewMonth($this->lines->candidates($kingdom), $kingdom, $requested);
        });
    }

    public function latestReviewMonth(KingdomRecord $kingdom): MonthWindow
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): MonthWindow {
            return $this->resolveReviewMonth($this->lines->candidates($kingdom), $kingdom, '');
        });
    }

    /**
     * @param list<PublicationCandidateLine> $candidates
     */
    private function resolveReviewMonth(array $candidates, KingdomRecord $kingdom, string $requested): MonthWindow
    {
        if ($requested !== '') {
            return MonthWindow::fromQuery($requested, $this->now);
        }

        $method = self::class . '::latestReviewMonth';
        $latest = '';
        foreach ($candidates as $line) {
            $key = substr($line->getPostedOn(), 0, 7);
            if (strcmp($key, $latest) > 0) {
                $latest = $key;
            }
        }
        if ($latest === '') {
            DenariusLog::debugBranch('transaction_review_month_current', $method, [
                'kingdom_id' => $kingdom->getId(),
            ]);
        }

        return MonthWindow::fromQuery($latest, $this->now);
    }

    /**
     * @param list<PublicationCandidateLine> $candidates
     * @return list<array<string, mixed>>
     */
    private function buildManageRows(
        array $candidates,
        KingdomRecord $kingdom,
        MonthWindow $month,
        bool $uncategorizedOnly,
        string $logMethod,
    ): array {
        $asOf = $this->now;
        $built = [];
        foreach ($candidates as $line) {
            if (!$month->contains($line->getPostedOn())) {
                continue;
            }
            if ($uncategorizedOnly && $line->getCategoryId() !== $this->categories->uncategorizedId()) {
                continue;
            }
            $built[] = $this->buildRow($kingdom, $line, $this->publicationStatus($line, $asOf))->view();
        }
        if ($uncategorizedOnly) {
            usort($built, static function (array $a, array $b): int {
                $confidence = ($a['categoryConfidence'] <=> $b['categoryConfidence']);
                if ($confidence !== 0) {
                    return $confidence;
                }

                return strcmp($b['postedOn'], $a['postedOn']);
            });
        } else {
            usort($built, static fn (array $a, array $b): int => strcmp($b['postedOn'], $a['postedOn']));
        }
        DenariusLog::debugBranch('transaction_review_queue_loaded', $logMethod, [
            'kingdom_id' => $kingdom->getId(),
            'month' => $month->key(),
            'row_count' => count($built),
            'uncategorized_only' => $uncategorizedOnly,
        ]);

        return $built;
    }

    private function buildRow(KingdomRecord $kingdom, PublicationCandidateLine $line, string $status): TransactionReviewRow
    {
        $categoryId = $line->getCategoryId();
        $flow = TransactionFlow::defaultFromSignedCents($line->getAmountCents());
        $storedCategoryId = $categoryId !== $this->categories->uncategorizedId() ? $categoryId : 0;
        $storedDisplay = $storedCategoryId === 0
            ? ''
            : $this->categoryAssigner->displayFor($storedCategoryId, $flow);
        $lineage = $storedCategoryId === 0
            ? 'uncategorized'
            : $this->categories->lineageKeyForId($storedCategoryId);
        $pattern = $this->patternPrefill->fromReviewQuery(
            $line->getCounterparty(),
            $line->getDescription(),
            $lineage,
        );
        $redact = PublicationFlags::parse($line->getPublicationFlags())->isManagerRedactDescription();
        $selection = new PublicationSelection(
            $line->getTellerTransactionId(),
            $this->isPublished($line),
            $redact,
            !$this->embargoOpen($line, $this->now),
        );

        return new TransactionReviewRow(
            $line->getTellerTransactionId(),
            $line->getPostedOn(),
            Money::format($line->getAmountCents()),
            $line->getAmountCents(),
            $line->getDescription(),
            $line->getCounterparty(),
            $line->getAccountName(),
            $status,
            $categoryId,
            $this->categoryAssigner->labelFor($categoryId),
            $line->getCategorySource(),
            $line->getCategoryConfidence(),
            $storedCategoryId,
            $storedDisplay,
            $pattern['token'],
            $pattern['category'],
            $selection,
        );
    }

    private function publicationStatus(PublicationCandidateLine $line, \DateTimeImmutable $asOf): string
    {
        if ($this->isPublished($line)) {
            return 'published';
        }
        if (!$this->embargoOpen($line, $asOf)) {
            return 'embargoed';
        }
        if ($this->needsCategory($line)) {
            return 'needs_category';
        }

        return 'pending';
    }

    private function needsCategory(PublicationCandidateLine $line): bool
    {
        if ($line->getCategoryId() !== $this->categories->uncategorizedId()) {
            return false;
        }

        return !PublicationFlags::parse($line->getPublicationFlags())->isHard();
    }

    private function isPublished(PublicationCandidateLine $line): bool
    {
        $publishedAt = $line->getPublishedAt();

        return $publishedAt !== null && $publishedAt !== '';
    }

    private function embargoOpen(PublicationCandidateLine $line, \DateTimeImmutable $asOf): bool
    {
        $after = $line->getPublishableAfter();
        if ($after === null || $after === '') {
            return true;
        }

        return $asOf >= new \DateTimeImmutable($after);
    }
}
