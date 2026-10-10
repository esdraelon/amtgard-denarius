<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Taxonomy\CategoryConfidence;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\TransactionCategorizer;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Ledger\LedgerProviderIdResolver;
use Amtgard\Denarius\Service\Ledger\TransactionCategoryApplier;
use Amtgard\Denarius\Service\Ledger\TransactionRecategorizer;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionCategorizerTest extends AmtgardTestCase
{
    private TransactionCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = CategorizationArrange::categorizer();
    }

    public function testGoldenCorpusMeetsPrecisionFloor(): void
    {
        $path = dirname(__DIR__, 2) . '/data/taxonomy/fixtures/golden.json';
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $hits = 0;
        $total = 0;
        foreach ($payload['cases'] as $case) {
            ++$total;
            $incoming = $this->row(
                (string) $case['description'],
                (string) ($case['counterparty'] ?? ''),
                '-10.00',
                '',
            );
            $decision = $this->categorizer->decide('teller', $incoming, null);
            if ($decision->categoryId === CategoryCatalogFixture::id((string) $case['expected'])) {
                ++$hits;
            }
        }
        $this->assertSame($total, $hits);
        $this->assertGreaterThanOrEqual(0.75, $hits / max(1, $total));
    }

    public function testAutoAcceptKeywordAndSuggestProviderHint(): void
    {
        $incoming = $this->row('CHECKCARD K&K INSURANCE GROUP', '', '-40.00', '');
        $auto = $this->categorizer->decide('teller', $incoming, null);
        $this->assertSame(CategoryCatalogFixture::id('expense.insurance'), $auto->categoryId);
        $this->assertGreaterThanOrEqual(CategoryConfidence::AUTO_ACCEPT, $auto->confidence);

        $suggest = $this->categorizer->decide('teller', TransactionRecord::builder()
            ->amountCents(-1200)
            ->description('SHOP')
            ->providerCategory('groceries')
            ->build(), null);
        $this->assertSame(CategoryCatalogFixture::id('uncategorized'), $suggest->categoryId);
        $this->assertSame('expense.feast_groceries', $suggest->suggestedSlug);
        $this->assertLessThan(CategoryConfidence::AUTO_ACCEPT, $suggest->confidence);
    }

    public function testFlowMismatchDoesNotAutoAssignExpenseOnRefundCredit(): void
    {
        $incoming = TransactionRecord::builder()
            ->amountCents(2500)
            ->description('AMAZON REFUND')
            ->providerCategory('FOOD_AND_DRINK')
            ->build();
        $decision = $this->categorizer->decide('plaid', $incoming, null);
        $this->assertSame(CategoryCatalogFixture::id('uncategorized'), $decision->categoryId);
        $this->assertNotSame(CategoryCatalogFixture::id('expense.feast_groceries'), $decision->categoryId);
    }

    public function testManagerLockSurvivesSecondSyncWithChangedProviderHint(): void
    {
        $kingdom = KingdomRecord::builder()->provider('teller')->build();
        $providers = Strategies::providers(Strategies::teller());
        $applier = new TransactionCategoryApplier($this->categorizer, new LedgerProviderIdResolver($providers));
        $existing = TransactionRecord::builder()
            ->tellerTransactionId('txn-1')
            ->description('STAY')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.site_rental'))
            ->categorySource(CategorySource::Manager->value)
            ->categoryConfidence(100)
            ->build();
        $incoming = TransactionRecord::builder()
            ->tellerTransactionId('txn-1')
            ->description('STAY')
            ->amountCents(-100)
            ->providerCategory('groceries')
            ->build();
        $second = $applier->apply($kingdom, $incoming, $existing);
        $this->assertSame(CategoryCatalogFixture::id('expense.site_rental'), $second->getCategoryId());
        $this->assertSame(CategorySource::Manager->value, $second->getCategorySource());
    }

    public function testPendingToPostedKeepsCategoryUntilDescriptionChanges(): void
    {
        $kingdom = KingdomRecord::builder()->provider('teller')->build();
        $providers = Strategies::providers(Strategies::teller());
        $applier = new TransactionCategoryApplier($this->categorizer, new LedgerProviderIdResolver($providers));
        $existing = TransactionRecord::builder()
            ->description('POS DEBIT RECREATION.GOV RESERVATION')
            ->status('pending')
            ->amountCents(-5000)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.site_rental'))
            ->categorySource(CategorySource::SharedRule->value)
            ->categoryConfidence(85)
            ->taxonomyVersion('taxonomy/v1')
            ->build();
        $postedSame = TransactionRecord::builder()
            ->description('POS DEBIT RECREATION.GOV RESERVATION')
            ->status('posted')
            ->amountCents(-5000)
            ->build();
        $kept = $applier->apply($kingdom, $postedSame, $existing);
        $this->assertSame(CategoryCatalogFixture::id('expense.site_rental'), $kept->getCategoryId());

        $postedChanged = TransactionRecord::builder()
            ->description('CHECKCARD K&K INSURANCE GROUP')
            ->status('posted')
            ->amountCents(-5000)
            ->build();
        $rematched = $applier->apply($kingdom, $postedChanged, $existing);
        $this->assertSame(CategoryCatalogFixture::id('expense.insurance'), $rematched->getCategoryId());
    }

    public function testRecategorizeIsIdempotent(): void
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(2)->name('K')->slug('k')->provider('teller')->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('txn-a')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-5000)
            ->description('POS DEBIT RECREATION.GOV RESERVATION')
            ->status('posted')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->categorySource(CategorySource::Fallback->value)
            ->build());
        $providers = Strategies::providers(Strategies::teller());
        $months = Strategies::months();
        $recategorizer = new TransactionRecategorizer(
            $kingdoms,
            $transactions,
            $this->categorizer,
            new LedgerProviderIdResolver($providers),
            CategorizationArrange::bundledCatalog(),
            CategoryCatalogFixture::asInterface(),
            $months,
        );
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        MethodLogAssert::reset();
        $first = $recategorizer->recategorizeKingdom($kingdom);
        $second = $recategorizer->recategorizeKingdom($kingdom);
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'transaction_recategorize_completed',
            TransactionRecategorizer::class . '::recategorizeKingdom',
        );
    }

    public function testCategorizationLogsExcludeDescriptionAndCounterparty(): void
    {
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        MethodLogAssert::reset();
        $incoming = $this->row('POS DEBIT RECREATION.GOV RESERVATION', 'STATE PARKS DESK', '-10.00', '');
        $this->categorizer->decide('teller', $incoming, null);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'transaction_categorized',
            TransactionCategorizer::class . '::decide',
        );
        MethodLogAssert::assertBranchContextExcludes(
            'transaction_categorized',
            TransactionCategorizer::class . '::decide',
            'description',
            'counterparty',
            'normalized_description',
            'normalized_counterparty',
        );

    }

    public function testManagerLockBranchOmitsDescriptionFromLogContext(): void
    {
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        MethodLogAssert::reset();
        $input = \Amtgard\Denarius\Domain\Taxonomy\Categorization\CategorizationInput::builder()
            ->existingCategoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->existingSource(CategorySource::Manager->value)
            ->build();
        $match = (new \Amtgard\Denarius\Domain\Taxonomy\Categorization\ManagerLockMatcher(
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
        ))->match($input);
        $this->assertNotNull($match);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'transaction_category_locked',
            \Amtgard\Denarius\Domain\Taxonomy\Categorization\ManagerLockMatcher::class . '::match',
        );
        MethodLogAssert::assertBranchContextExcludes(
            'transaction_category_locked',
            \Amtgard\Denarius\Domain\Taxonomy\Categorization\ManagerLockMatcher::class . '::match',
            'description',
            'counterparty',
        );
    }

    public function testKeywordTieBreakPrefersHigherConfidenceThenLongerPattern(): void
    {
        $dir = sys_get_temp_dir() . '/denarius-tax-tie-' . uniqid('', true);
        \Amtgard\Denarius\Tests\Support\TaxonomyPackFixture::writeMinimalPack($dir);
        $keywords = [
            'taxonomyVersion' => 'taxonomy/v1',
            'rules' => [
                [
                    'id' => 'kw.short',
                    'category' => 'expense.bank_fees',
                    'fields' => ['description'],
                    'match' => ['type' => 'token', 'token' => 'FEE'],
                    'flows' => ['expense'],
                    'confidence' => 80,
                ],
                [
                    'id' => 'kw.longer',
                    'category' => 'expense.bank_fees',
                    'fields' => ['description'],
                    'match' => ['type' => 'token', 'token' => 'SERVICE CHARGE FEE'],
                    'flows' => ['expense'],
                    'confidence' => 80,
                ],
            ],
        ];
        file_put_contents(
            $dir . '/matchers/keywords.json',
            json_encode($keywords, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        file_put_contents($dir . '/matchers/provider-hints.json', json_encode(['taxonomyVersion' => 'taxonomy/v1', 'hints' => []], JSON_THROW_ON_ERROR));
        $catalog = (new TaxonomyCatalogLoader($dir, '.'))->load();
        $categorizer = CategorizationArrange::categorizer($catalog);
        $incoming = $this->row('SERVICE CHARGE FEE', '', '-3.00', '');
        $decision = $categorizer->decide('teller', $incoming, null);
        $this->assertSame(CategoryCatalogFixture::id('expense.bank_fees'), $decision->categoryId);
        $this->assertSame('kw.longer', $decision->ruleId);
    }

    private function row(string $description, string $counterparty, string $amount, string $providerCategory): TransactionRecord
    {
        $cents = \Amtgard\Denarius\Domain\Statement\Line\Money::centsFromDecimal($amount);

        return TransactionRecord::builder()
            ->description($description)
            ->counterparty($counterparty)
            ->amountCents($cents)
            ->providerCategory($providerCategory === '' ? null : $providerCategory)
            ->build();
    }
}
