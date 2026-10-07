<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\BalanceCoarseningStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\EnvelopeReviewStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationEnvelopeReviewTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
    }

    public function testEnvelopeReviewWithholdsBalanceWhenLineSumLeaks(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $line = PublicationCandidateLine::builder()->postedOn('2026-09-02')->amountCents(-500)->category('uncategorized')->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
            10_900,
            10_000,
        );
        $coarsened = (new BalanceCoarseningStage())->process($envelope);
        $reviewed = (new EnvelopeReviewStage())->process($coarsened);

        $this->assertNull($reviewed->publishedBalanceCents());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_envelope_leak', EnvelopeReviewStage::class . '::process');
    }

    public function testEnvelopeReviewPassesWhenLineSumMatchesBalanceDelta(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $line = PublicationCandidateLine::builder()->postedOn('2026-09-02')->amountCents(-500)->category('uncategorized')->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
            9_500,
            10_000,
        );
        $coarsened = (new BalanceCoarseningStage())->process($envelope);
        $reviewed = (new EnvelopeReviewStage())->process($coarsened);

        $this->assertSame(9_500, $reviewed->publishedBalanceCents());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_envelope_ok', EnvelopeReviewStage::class . '::process');
    }
}
