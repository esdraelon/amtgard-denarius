<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Ledger\TransactionPublicationApplier;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionPublicationApplierCategoryTest extends AmtgardTestCase
{
    public function testApplyPreservesStoredCategoryBlockOnResync(): void
    {
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('txn-resync')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->category('expense.site_rental')
            ->categorySource(CategorySource::Manager->value)
            ->categoryConfidence(100)
            ->build());

        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->embargoDays(3)->build();
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('txn-resync')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->category('uncategorized')
            ->providerCategory('RENT')
            ->categorySource(CategorySource::Fallback->value)
            ->build();

        $applied = Strategies::publicationApplier($transactions)->apply($kingdom, $incoming, false);
        $this->assertSame('expense.site_rental', $applied->getCategory());
        $this->assertSame(CategorySource::Manager->value, $applied->getCategorySource());
        $this->assertSame('RENT', $applied->getProviderCategory());
    }
}
