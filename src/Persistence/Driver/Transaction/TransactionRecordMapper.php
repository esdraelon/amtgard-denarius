<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Driver\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Maps SQL row arrays to {@see TransactionRecord}. */
final class TransactionRecordMapper
{
    public function __construct()
    {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function fromDatabaseRow(array $row): TransactionRecord
    {
        return TransactionRecord::builder()
            ->id(isset($row['id']) ? (int) $row['id'] : null)
            ->kingdomId((int) ($row['kingdom_id'] ?? 0))
            ->tellerTransactionId((string) ($row['teller_transaction_id'] ?? ''))
            ->tellerAccountId((string) ($row['teller_account_id'] ?? ''))
            ->postedOn((string) ($row['posted_on'] ?? ''))
            ->amountCents((int) ($row['amount_cents'] ?? 0))
            ->categoryId((int) ($row['category_id'] ?? 0))
            ->providerCategory(isset($row['provider_category']) ? (string) $row['provider_category'] : null)
            ->categorySource((string) ($row['category_source'] ?? 'fallback'))
            ->categoryRuleId(isset($row['category_rule_id']) ? (string) $row['category_rule_id'] : null)
            ->categoryConfidence((int) ($row['category_confidence'] ?? 0))
            ->taxonomyVersion(isset($row['taxonomy_version']) ? (string) $row['taxonomy_version'] : null)
            ->description((string) ($row['description'] ?? ''))
            ->counterparty((string) ($row['counterparty'] ?? ''))
            ->status((string) ($row['status'] ?? ''))
            ->publishedAt(isset($row['published_at']) ? (string) $row['published_at'] : null)
            ->publishableAfter(isset($row['publishable_after']) ? (string) $row['publishable_after'] : null)
            ->publicationFlags(isset($row['publication_flags']) ? (string) $row['publication_flags'] : null)
            ->build();
    }
}
