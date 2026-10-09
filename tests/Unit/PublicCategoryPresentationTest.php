<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Presentation\Impl\SummarizedPresenter;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\CategoryLabelStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipelineFactory;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\PublicationForbiddenMetadataKeys;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Tests\Support\TaxonomyCatalogFixture;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicCategoryPresentationTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
    }

    public function testUnknownSlugsBecomeUncategorizedLabels(): void
    {
        MethodLogAssert::reset();
        $envelope = $this->envelope(DisplayMode::Redacted, [
            PublicationCandidateLine::builder()->postedOn('2026-09-01')->amountCents(-100)->category('general')->build(),
            PublicationCandidateLine::builder()->postedOn('2026-09-02')->amountCents(-200)->category('FOOD_AND_DRINK')->build(),
        ]);
        $result = (new CategoryLabelStage(TaxonomyCatalogFixture::load()))->process($envelope);
        $labels = array_map(static fn ($line) => $line->getCategory(), $result->lines());
        $this->assertSame(['Uncategorized', 'Uncategorized'], $labels);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_category_unknown_slug', CategoryLabelStage::class . '::process');
    }

    public function testHardRowsForceBankVerificationLabel(): void
    {
        $flags = PublicationFlags::empty()->withHardPattern('ingest.micro_deposit')->encode();
        $envelope = $this->envelope(DisplayMode::LessRedacted, [
            PublicationCandidateLine::builder()
                ->postedOn('2026-09-01')
                ->amountCents(0)
                ->category('income.dues')
                ->publicationFlags($flags)
                ->build(),
        ]);
        $result = (new CategoryLabelStage(TaxonomyCatalogFixture::load()))->process($envelope);
        $this->assertSame('Bank verification (withheld)', $result->lines()[0]->getCategory());
    }

    public function testCategoryLabelStagePreservesLineCountsAndAmounts(): void
    {
        $envelope = $this->envelope(DisplayMode::Summarized, [
            PublicationCandidateLine::builder()->postedOn('2026-09-01')->amountCents(-500)->category('expense.bank_fees')->build(),
            PublicationCandidateLine::builder()->postedOn('2026-09-02')->amountCents(1200)->category('income.dues')->build(),
        ]);
        $before = $envelope->quantizedLineCentsSum();
        $result = (new CategoryLabelStage(TaxonomyCatalogFixture::load()))->process($envelope);
        $this->assertCount(2, $result->lines());
        $this->assertSame($before, $result->quantizedLineCentsSum());
    }

    public function testSummarizedRollupThresholds(): void
    {
        $presenter = new SummarizedPresenter();
        $single = [$this->labeledLine('2026-09-01', -100, 'Bank fees', TransactionFlow::Expense)];
        $this->assertSame('Other expenses', $this->categoryNames($presenter->present($single, 2))[0]);
        $this->assertSame('Bank fees', $this->categoryNames($presenter->present($single, 1))[0]);

        $pair = [
            $this->labeledLine('2026-09-01', -100, 'Bank fees', TransactionFlow::Expense),
            $this->labeledLine('2026-09-02', -100, 'Bank fees', TransactionFlow::Expense),
        ];
        $this->assertSame('Bank fees', $this->categoryNames($presenter->present($pair, 2))[0]);
    }

    public function testSummarizedNetExcludesTransfers(): void
    {
        $presenter = new SummarizedPresenter();
        $lines = [
            $this->labeledLine('2026-09-01', 1000, 'Dues and memberships', TransactionFlow::Income),
            $this->labeledLine('2026-09-02', -400, 'Bank fees', TransactionFlow::Expense),
            $this->labeledLine('2026-09-03', -200, 'Transfer between kingdom accounts', TransactionFlow::Transfer),
            $this->labeledLine('2026-09-04', -100, 'Bank fees', TransactionFlow::Expense),
        ];
        $totals = $presenter->present($lines, 2);
        $net = $totals[array_key_last($totals)];
        $this->assertInstanceOf(CategoryTotal::class, $net);
        $this->assertTrue($net->isNetTotal);
        $this->assertSame(500, $net->amountCents);
    }

    public function testSoftCategoryLabelMatrixByDisclosureTier(): void
    {
        $catalog = TaxonomyCatalogFixture::load();
        $stage = new CategoryLabelStage($catalog);
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->category('expense.professional_services')
            ->build();

        $summarized = $stage->process($this->envelope(DisplayMode::Summarized, [$line]))->lines()[0]->getCategory();
        $redacted = $stage->process($this->envelope(DisplayMode::Redacted, [$line]))->lines()[0]->getCategory();
        $less = $stage->process($this->envelope(DisplayMode::LessRedacted, [$line]))->lines()[0]->getCategory();

        $this->assertSame('Services', $summarized);
        $this->assertSame('Services', $redacted);
        $this->assertSame('Professional services', $less);
    }

    public function testPublicationSettingsValidatorClampsSummarizedCategoryMinLines(): void
    {
        MethodLogAssert::reset();
        $validator = new PublicationSettingsValidator();
        $this->assertSame(PublicationPlatformLimits::MIN_SUMMARIZED_CATEGORY_MIN_LINES, $validator->clampSummarizedCategoryMinLines(1));
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'summarized_category_min_lines_clamped',
            PublicationSettingsValidator::class . '::clampSummarizedCategoryMinLines',
        );
    }

    public function testPublicPayloadOmitsManagerCategoryMetadata(): void
    {
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->displayMode('redacted')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-500)
            ->category('expense.bank_fees')
            ->providerCategory('BANK_FEES')
            ->categorySource('manager')
            ->categoryConfidence(100)
            ->categorySuggested('expense.bank_fees')
            ->description('fee')
            ->counterparty('Bank')
            ->status('posted')
            ->publishedAt('2026-09-03T00:00:00+00:00')
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());

        $origin = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $store = new ArrayStore();
        $reader = new CachingMonthReader($origin, $store, new \Amtgard\Denarius\Service\Month\MonthCacheWriter($store, TaxonomyCatalogFixture::load()));

        $statement = $reader->statement($kingdom, new MonthWindow(2026, 9));
        $this->assertNotEmpty($statement->rows);
        PublicationForbiddenMetadataKeys::assertAbsent($statement->rows);

        $this->assertNotEmpty($store->data);
        $cached = json_decode((string) reset($store->data), true, 512, JSON_THROW_ON_ERROR);
        PublicationForbiddenMetadataKeys::assertAbsent($cached);
    }

    public function testPublicPipelineIncludesCategoryLabelStage(): void
    {
        $pipeline = PublicationPipelineFactory::standard(TaxonomyCatalogFixture::load())->forPublicRead();
        $stages = (new \ReflectionClass($pipeline))->getProperty('stages');
        $stages->setAccessible(true);
        $chain = $stages->getValue($pipeline);
        $found = false;
        foreach ($chain as $stage) {
            if ($stage instanceof CategoryLabelStage) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @param list<PublicationCandidateLine> $lines
     */
    private function envelope(DisplayMode $mode, array $lines): PublicationEnvelope
    {
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();

        return new PublicationEnvelope($kingdom, new MonthWindow(2026, 9), $mode, new \DateTimeImmutable('2026-10-01'), $lines);
    }

    private function labeledLine(string $postedOn, int $amount, string $label, TransactionFlow $flow): LedgerLine
    {
        return LedgerLine::builder()
            ->postedOn($postedOn)
            ->amountCents($amount)
            ->category($label)
            ->categoryFlow($flow->value)
            ->build();
    }

    /**
     * @param list<CategoryTotal> $totals
     * @return list<string>
     */
    private function categoryNames(array $totals): array
    {
        return array_map(static fn (CategoryTotal $total) => $total->category, $totals);
    }
}
