<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Persistence;

use Amtgard\Denarius\Persistence\Driver\Transaction\TransactionRecordMapper;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionRecordMapperTest extends AmtgardTestCase
{
    public function testFromDatabaseRowMapsTransactionColumns(): void
    {
        $record = (new TransactionRecordMapper())->fromDatabaseRow([
            'id' => '7',
            'kingdom_id' => '59',
            'teller_transaction_id' => 'txn-1',
            'teller_account_id' => 'acc-1',
            'posted_on' => '2026-10-01',
            'amount_cents' => '-1500',
            'category_id' => '42',
            'provider_category' => 'RENT',
            'category_source' => 'provider_hint',
            'category_rule_id' => 'rule-1',
            'category_confidence' => '80',
            'taxonomy_version' => 'taxonomy/v1',
            'description' => 'Rent',
            'counterparty' => 'Landlord',
            'status' => 'posted',
            'published_at' => '2026-10-02T00:00:00+00:00',
            'publishable_after' => '2026-10-05T23:59:59+00:00',
            'publication_flags' => '{}',
        ]);

        $this->assertSame(7, $record->getId());
        $this->assertSame(59, $record->getKingdomId());
        $this->assertSame('txn-1', $record->getTellerTransactionId());
        $this->assertSame(-1500, $record->getAmountCents());
        $this->assertSame(42, $record->getCategoryId());
        $this->assertSame('2026-10-02T00:00:00+00:00', $record->getPublishedAt());
    }
}
