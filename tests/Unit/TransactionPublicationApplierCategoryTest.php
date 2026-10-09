<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Ledger\LedgerProviderIdResolver;
use Amtgard\Denarius\Service\Ledger\TransactionCategoryApplier;
use Amtgard\Denarius\Service\Ledger\TransactionPublicationApplier;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionPublicationApplierCategoryTest extends AmtgardTestCase
{
    public function testApplyPreservesStoredCategoryBlockOnResync(): void
    {
        $transactions = new MemoryTransactions();
        $existing = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('txn-resync')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->description('RENT PAYMENT')
            ->category('expense.site_rental')
            ->categorySource(CategorySource::Manager->value)
            ->categoryConfidence(100)
            ->build();
        $transactions->upsert($existing);

        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->provider('teller')->embargoDays(3)->build();
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('txn-resync')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->description('RENT PAYMENT')
            ->category('uncategorized')
            ->providerCategory('RENT')
            ->categorySource(CategorySource::Fallback->value)
            ->build();

        $providers = Strategies::providers(Strategies::teller());
        $categorized = (new TransactionCategoryApplier(
            CategorizationArrange::categorizer(),
            new LedgerProviderIdResolver($providers),
        ))->apply($kingdom, $incoming, $existing);

        $applied = Strategies::publicationApplier($transactions)->apply($kingdom, $categorized, false);
        $this->assertSame('expense.site_rental', $applied->getCategory());
        $this->assertSame(CategorySource::Manager->value, $applied->getCategorySource());
        $this->assertSame('RENT', $applied->getProviderCategory());
    }
}
