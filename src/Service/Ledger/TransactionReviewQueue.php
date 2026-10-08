<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSelection;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionReviewRow;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager publication review queue from ledger candidates. */
final class TransactionReviewQueue
{
    public function __construct(
        private readonly KingdomPublicationLineSource $lines,
        private readonly TaxonomyCatalog $catalog,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsForManage(KingdomRecord $kingdom, MonthWindow $month, bool $uncategorizedOnly = false): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom, $month, $uncategorizedOnly): array {
            $asOf = $this->now;
            $built = [];
            foreach ($this->lines->candidates($kingdom) as $line) {
                if (!$month->contains($line->getPostedOn())) {
                    continue;
                }
                if ($uncategorizedOnly && $line->getCategory() !== 'uncategorized') {
                    continue;
                }
                $status = $this->status($line, $asOf);
                $built[] = $this->row($line, $status)->view();
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
            DenariusLog::debugBranch('transaction_review_queue_loaded', $method, [
                'kingdom_id' => $kingdom->getId(),
                'month' => $month->key(),
                'row_count' => count($built),
                'uncategorized_only' => $uncategorizedOnly,
            ]);

            return $built;
        });
    }

    /** Requested `YYYY-MM` when present; otherwise the latest month with review rows. */
    public function reviewMonth(KingdomRecord $kingdom, string $requested): MonthWindow
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $requested): MonthWindow {
            if ($requested === '') {
                return $this->latestReviewMonth($kingdom);
            }

            return MonthWindow::fromQuery($requested, $this->now);
        });
    }

    public function latestReviewMonth(KingdomRecord $kingdom): MonthWindow
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom): MonthWindow {
            $latest = '';
            foreach ($this->lines->candidates($kingdom) as $line) {
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
        });
    }

    private function row(PublicationCandidateLine $line, string $status): TransactionReviewRow
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $status): TransactionReviewRow {
            $category = $line->getCategory();
            $suggested = $line->getCategorySuggested();
            $prefill = $category !== 'uncategorized' ? $category : ($suggested ?? 'uncategorized');
            $flow = TransactionFlow::defaultFromSignedCents($line->getAmountCents());
            $prefillDisplay = ucfirst($flow->value) . ' · ' . $this->catalog->label($prefill);
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
                $category,
                $this->catalog->label($category),
                $line->getCategorySource(),
                $suggested,
                $line->getCategoryConfidence(),
                $prefill,
                $prefillDisplay,
                $selection,
            );
        });
    }

    private function status(PublicationCandidateLine $line, \DateTimeImmutable $asOf): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $asOf): string {
            if ($this->isPublished($line)) {
                return 'published';
            }
            if ($this->embargoOpen($line, $asOf)) {
                return 'pending';
            }

            return 'embargoed';
        });
    }

    private function isPublished(PublicationCandidateLine $line): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line): bool {
            $publishedAt = $line->getPublishedAt();

            return $publishedAt !== null && $publishedAt !== '';
        });
    }

    private function embargoOpen(PublicationCandidateLine $line, \DateTimeImmutable $asOf): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $asOf): bool {
            $after = $line->getPublishableAfter();
            if ($after === null || $after === '') {
                return true;
            }

            return $asOf >= new \DateTimeImmutable($after);
        });
    }
}
