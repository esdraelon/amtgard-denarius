<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Domain\Statement\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\DisplayMode;
use Amtgard\Denarius\Domain\Statement\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

final class CachingMonthReader implements MonthReader
{
    public function __construct(
        private readonly MonthReader $origin,
        private readonly KeyValueStore $store,
        private readonly MonthCacheKeys $keys = new MonthCacheKeys(),
        private readonly int $ttlSeconds = 86400,
    ) {
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        $mode = DisplayMode::fromStored($kingdom->getDisplayMode());
        $key = $this->key($kingdom, $month, $mode);
        $cached = $this->decode($this->store->get($key), $mode, $month);
        if ($cached !== null) {
            return $cached;
        }

        $statement = $this->origin->statement($kingdom, $month);
        $this->store->set($key, $this->encode($statement), $this->ttlSeconds);

        return $statement;
    }

    private function key(KingdomRecord $kingdom, MonthWindow $month, DisplayMode $mode): string
    {
        $kingdomId = (int) $kingdom->getId();
        $generation = (int) ($this->store->get($this->keys->generation($kingdomId)) ?? '0');

        return $this->keys->statement($kingdomId, $generation, $mode->value, $month->key());
    }

    private function encode(MonthStatement $statement): string
    {
        $rows = [];
        foreach ($statement->rows as $row) {
            $rows[] = $row instanceof CategoryTotal ? $this->total($row) : $this->line($row);
        }

        return json_encode([
            'mode' => $statement->mode->value,
            'month' => $statement->month->key(),
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(LedgerLine $row): array
    {
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
    }

    /**
     * @return array<string, mixed>
     */
    private function total(CategoryTotal $row): array
    {
        return [
            'kind' => 'total',
            'category' => $row->category,
            'count' => $row->count,
            'amountCents' => $row->amountCents,
        ];
    }

    private function decode(?string $payload, DisplayMode $mode, MonthWindow $month): ?MonthStatement
    {
        if ($payload === null) {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || ($decoded['month'] ?? '') !== $month->key()) {
            return null;
        }

        return new MonthStatement($mode, $month, $this->rows($decoded['rows'] ?? []));
    }

    /**
     * @param mixed $rows
     * @return list<LedgerLine|CategoryTotal>
     */
    private function rows(mixed $rows): array
    {
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
    }

    /**
     * @param array<string, mixed> $row
     */
    private function lineFrom(array $row): LedgerLine
    {
        return LedgerLine::builder()
            ->postedOn((string) ($row['postedOn'] ?? ''))
            ->amountCents((int) ($row['amountCents'] ?? 0))
            ->category((string) ($row['category'] ?? ''))
            ->description((string) ($row['description'] ?? ''))
            ->counterparty((string) ($row['counterparty'] ?? ''))
            ->status((string) ($row['status'] ?? ''))
            ->accountName((string) ($row['accountName'] ?? ''))
            ->build();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function totalFrom(array $row): CategoryTotal
    {
        return new CategoryTotal((string) ($row['category'] ?? ''), (int) ($row['count'] ?? 0), (int) ($row['amountCents'] ?? 0));
    }
}
