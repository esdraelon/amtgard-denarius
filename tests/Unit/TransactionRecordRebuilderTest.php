<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionRecordRebuilderTest extends AmtgardTestCase
{
    public function testRebuilderRoundTripsEveryConstructorProperty(): void
    {
        $expected = TransactionRecord::builder()
            ->id(9)
            ->kingdomId(2)
            ->tellerTransactionId('txn-9')
            ->tellerAccountId('acc-9')
            ->postedOn('2026-09-02')
            ->amountCents(-425)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.site_rental'))
            ->providerCategory('RENT')
            ->categorySource(CategorySource::ProviderHint->value)
            ->categoryRuleId('hint.plaid.rent')
            ->categoryConfidence(62)
            ->taxonomyVersion('taxonomy/v1')
            ->description('Site fee')
            ->counterparty('Landlord')
            ->status('posted')
            ->publishedAt('2026-09-04T00:00:00+00:00')
            ->publishableAfter('2026-09-05T23:59:59+00:00')
            ->publicationFlags('{"hard":[]}')
            ->build();

        $roundTrip = TransactionRecordRebuilder::from($expected)->build();

        $this->assertSame($expected->getId(), $roundTrip->getId());
        $this->assertSame($expected->getKingdomId(), $roundTrip->getKingdomId());
        $this->assertSame($expected->getTellerTransactionId(), $roundTrip->getTellerTransactionId());
        $this->assertSame($expected->getTellerAccountId(), $roundTrip->getTellerAccountId());
        $this->assertSame($expected->getPostedOn(), $roundTrip->getPostedOn());
        $this->assertSame($expected->getAmountCents(), $roundTrip->getAmountCents());
        $this->assertSame($expected->getCategoryId(), $roundTrip->getCategoryId());
        $this->assertSame($expected->getProviderCategory(), $roundTrip->getProviderCategory());
        $this->assertSame($expected->getCategorySource(), $roundTrip->getCategorySource());
        $this->assertSame($expected->getCategoryRuleId(), $roundTrip->getCategoryRuleId());
        $this->assertSame($expected->getCategoryConfidence(), $roundTrip->getCategoryConfidence());
        $this->assertSame($expected->getTaxonomyVersion(), $roundTrip->getTaxonomyVersion());
        $this->assertSame($expected->getDescription(), $roundTrip->getDescription());
        $this->assertSame($expected->getCounterparty(), $roundTrip->getCounterparty());
        $this->assertSame($expected->getStatus(), $roundTrip->getStatus());
        $this->assertSame($expected->getPublishedAt(), $roundTrip->getPublishedAt());
        $this->assertSame($expected->getPublishableAfter(), $roundTrip->getPublishableAfter());
        $this->assertSame($expected->getPublicationFlags(), $roundTrip->getPublicationFlags());
    }
}
