<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceReason;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: JSON encode/decode for month statement cache blobs. */
final class MonthStatementCacheCodec
{
    public function encode(MonthStatement $statement): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($statement): string {
            $rows = [];
            foreach ($statement->rows as $row) {
                $rows[] = $row instanceof CategoryTotal ? $this->total($row) : $this->line($row);
            }

            $payload = [
                'mode' => $statement->mode->value,
                'month' => $statement->month->key(),
                'rows' => $rows,
            ];
            if ($statement->absenceReason !== null) {
                $payload['absence'] = $statement->absenceReason->toCache();
            }
            if ($statement->openingBalanceCents !== null) {
                $payload['openingBalanceCents'] = $statement->openingBalanceCents;
            }
            if ($statement->closingBalanceCents !== null) {
                $payload['closingBalanceCents'] = $statement->closingBalanceCents;
            }

            return json_encode($payload, JSON_THROW_ON_ERROR);
        });
    }

    public function decode(?string $payload, DisplayMode $mode, MonthWindow $month): ?MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload, $mode, $month): ?MonthStatement {
            if ($payload === null) {
                return null;
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded) || ($decoded['month'] ?? '') !== $month->key()) {
                return null;
            }

            $absence = is_array($decoded['absence'] ?? null)
                ? StatementAbsenceReason::fromCache($decoded['absence'])
                : null;

            $opening = isset($decoded['openingBalanceCents']) ? (int) $decoded['openingBalanceCents'] : null;
            $closing = isset($decoded['closingBalanceCents']) ? (int) $decoded['closingBalanceCents'] : null;

            return new MonthStatement($mode, $month, $this->rows($decoded['rows'] ?? []), $absence, $opening, $closing);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function line(LedgerLine $row): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): array {
            return [
                'kind' => 'line',
                'postedOn' => $row->getPostedOn(),
                'amountCents' => $row->getAmountCents(),
                'category' => $row->getCategory(),
                'categoryFlow' => $row->getCategoryFlow(),
                'description' => $row->getDescription(),
                'counterparty' => $row->getCounterparty(),
                'status' => $row->getStatus(),
                'accountName' => $row->getAccountName(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function total(CategoryTotal $row): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): array {
            $encoded = [
                'kind' => 'total',
                'category' => $row->category,
                'count' => $row->count,
                'amountCents' => $row->amountCents,
            ];
            if ($row->flowSection !== null) {
                $encoded['flowSection'] = $row->flowSection->value;
            }
            if ($row->isNetTotal) {
                $encoded['isNetTotal'] = true;
            }

            return $encoded;
        });
    }

    /**
     * @param mixed $rows
     * @return list<LedgerLine|CategoryTotal>
     */
    private function rows(mixed $rows): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($rows): array {
            if (!is_array($rows)) {
                return [];
            }
            $built = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $built[] = ($row['kind'] ?? '') === 'total' ? $this->totalFrom($row) : $this->lineFrom($row);
            }

            return $built;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function lineFrom(array $row): LedgerLine
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): LedgerLine {
            return LedgerLine::builder()
                ->postedOn((string) ($row['postedOn'] ?? ''))
                ->amountCents((int) ($row['amountCents'] ?? 0))
                ->category((string) ($row['category'] ?? ''))
                ->categoryFlow((string) ($row['categoryFlow'] ?? ''))
                ->description((string) ($row['description'] ?? ''))
                ->counterparty((string) ($row['counterparty'] ?? ''))
                ->status((string) ($row['status'] ?? ''))
                ->accountName((string) ($row['accountName'] ?? ''))
                ->build();
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function totalFrom(array $row): CategoryTotal
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): CategoryTotal {
            return new CategoryTotal(
                (string) ($row['category'] ?? ''),
                (int) ($row['count'] ?? 0),
                (int) ($row['amountCents'] ?? 0),
                TransactionFlow::fromStored((string) ($row['flowSection'] ?? '')),
                (bool) ($row['isNetTotal'] ?? false),
            );
        });
    }
}
