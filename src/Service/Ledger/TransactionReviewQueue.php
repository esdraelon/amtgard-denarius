<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionReviewRow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager publication review queue from ledger candidates. */
final class TransactionReviewQueue
{
    public function __construct(
        private readonly KingdomPublicationLineSource $lines,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array<string, string>>
     */
    public function rowsForManage(KingdomRecord $kingdom): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom): array {
            $asOf = $this->now;
            $built = [];
            foreach ($this->lines->candidates($kingdom) as $line) {
                $status = $this->status($line, $asOf);
                $built[] = $this->row($line, $status)->view();
            }
            usort($built, static fn (array $a, array $b): int => strcmp($b['postedOn'], $a['postedOn']));
            DenariusLog::debugBranch('transaction_review_queue_loaded', $method, [
                'kingdom_id' => $kingdom->getId(),
                'row_count' => count($built),
            ]);

            return $built;
        });
    }

    private function row(PublicationCandidateLine $line, string $status): TransactionReviewRow
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $status): TransactionReviewRow {
            return new TransactionReviewRow(
                $line->getTellerTransactionId(),
                $line->getPostedOn(),
                Money::format($line->getAmountCents()),
                $line->getDescription(),
                $line->getAccountName(),
                $status,
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
