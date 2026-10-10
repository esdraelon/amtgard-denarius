<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\AmountQuantizer;
use Amtgard\Denarius\Domain\Statement\Publication\BalancePullRounder;
use Amtgard\Denarius\Domain\Statement\Publication\BalanceQuantumCalculator;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\AmountQuantizationStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\BalanceCoarseningStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationQuantizationTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
    }

    public function testAmountQuantizationStageRoundsToKingdomQuantum(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()
            ->orkKingdomId(1)
            ->name('K')
            ->slug('k')
            ->amountQuantumCents(100)
            ->build();
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-02')
            ->amountCents(-523)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
        );

        $result = (new AmountQuantizationStage())->process($envelope);
        $this->assertSame(-500, $result->lines()[0]->getAmountCents());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_amount_quantized', AmountQuantizationStage::class . '::process');
    }

    public function testBalanceCoarseningPullRoundsProviderBalance(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()
            ->orkKingdomId(1)
            ->name('K')
            ->slug('k')
            ->balanceQuantumFloorCents(500)
            ->balanceQuantumCeilingCents(500)
            ->build();
        $line = PublicationCandidateLine::builder()->postedOn('2026-09-02')->amountCents(-500)->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
            10_523,
            10_000,
        );

        $result = (new BalanceCoarseningStage())->process($envelope);
        $this->assertSame(500, $result->balanceQuantumCents());
        $this->assertSame(10_500, $result->publishedBalanceCents());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_balance_quantum', BalanceCoarseningStage::class . '::process');
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_balance_coarsened', BalanceCoarseningStage::class . '::process');
    }

    public function testPublicationSettingsValidatorClampsQuantumFields(): void
    {
        MethodLogAssert::reset();
        $validator = new PublicationSettingsValidator();
        $this->assertSame(PublicationPlatformLimits::MIN_AMOUNT_QUANTUM_CENTS, $validator->clampAmountQuantumCents(1));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'amount_quantum_clamped', PublicationSettingsValidator::class . '::clampAmountQuantumCents');

        MethodLogAssert::reset();
        $clamped = $validator->clampBalanceQuantumSettings(100, 200, 99_999);
        $this->assertSame(PublicationPlatformLimits::MIN_BALANCE_QUANTUM_FLOOR_CENTS, $clamped['floor']);
        $this->assertGreaterThanOrEqual($clamped['floor'], $clamped['ceiling']);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'balance_quantum_clamped', PublicationSettingsValidator::class . '::clampBalanceQuantumSettings');
    }

    public function testBalanceQuantumGrowsWithLineCountWhenStepConfigured(): void
    {
        $kingdom = KingdomRecord::builder()
            ->orkKingdomId(1)
            ->name('K')
            ->slug('k')
            ->balanceQuantumFloorCents(500)
            ->balanceQuantumCeilingCents(2000)
            ->balanceQuantumStepCents(500)
            ->build();
        $calculator = new BalanceQuantumCalculator();
        $this->assertSame(500, $calculator->forLineCount($kingdom, 1));
        $this->assertSame(1500, $calculator->forLineCount($kingdom, 3));
    }

    public function testAmountQuantizerPreservesSignForSubQuantumDebits(): void
    {
        $quantizer = new AmountQuantizer();
        $this->assertSame(-100, $quantizer->quantizeCents(-37, 100));
        $this->assertSame(100, $quantizer->quantizeCents(37, 100));
    }

    public function testBalancePullRounderUsesQuantumBuckets(): void
    {
        $rounder = new BalancePullRounder();
        $this->assertSame(10_500, $rounder->coarsenBalanceCents(10_000, 10_523, 500));
    }
}
