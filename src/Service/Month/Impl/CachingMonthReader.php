<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month\Impl;

use Amtgard\Denarius\Service\Month\MonthCacheKeys;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceReason;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CachingMonthReader implements MonthReader
{
    public function __construct(
        private readonly MonthReader $origin,
        private readonly KeyValueStore $store,
        private readonly MonthCacheKeys $keys = new MonthCacheKeys(),
        private readonly int $ttlSeconds = 86400,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month): MonthStatement {
            $mode = DisplayMode::fromStored($kingdom->getDisplayMode());
            $key = $this->key($kingdom, $month, $mode);
            $cached = $this->decode($this->store->get($key), $mode, $month);
            if ($cached !== null) {
                return $cached;
            }

            $statement = $this->origin->statement($kingdom, $month);
            $this->store->set($key, $this->encode($statement), $this->ttlSeconds);

            return $statement;
        });
    }

    private function key(KingdomRecord $kingdom, MonthWindow $month, DisplayMode $mode): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month, $mode): string {
            $kingdomId = (int) $kingdom->getId();
            $generation = (int) ($this->store->get($this->keys->generation($kingdomId)) ?? '0');

            return $this->keys->statement($kingdomId, $generation, $mode->value, $month->key());
        });
    }

    private function encode(MonthStatement $statement): string
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

            return json_encode($payload, JSON_THROW_ON_ERROR);
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
            return [
                'kind' => 'total',
                'category' => $row->category,
                'count' => $row->count,
                'amountCents' => $row->amountCents,
            ];
        });
    }

    private function decode(?string $payload, DisplayMode $mode, MonthWindow $month): ?MonthStatement
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

            return new MonthStatement($mode, $month, $this->rows($decoded['rows'] ?? []), $absence);
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
            return new CategoryTotal((string) ($row['category'] ?? ''), (int) ($row['count'] ?? 0), (int) ($row['amountCents'] ?? 0));
        });
    }
}
