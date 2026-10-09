<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Ingest\PublicationHardPatternIds;
use Amtgard\Denarius\Domain\Statement\Publication\Pattern\PublicationSoftPatternIds;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PatternRegistryStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationPatternRegistryTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
    }

    public function testSoftPatternStripsPayrollDescriptorAndMergesFlags(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $hardFlags = PublicationFlags::empty()->withHardPattern(PublicationHardPatternIds::VERIFY_KEYWORD)->encode();
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-02')
            ->amountCents(-500)
            ->category('uncategorized')
            ->description('ADP Payroll deposit')
            ->counterparty('ADP')
            ->publicationFlags($hardFlags)
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::LessRedacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
        );

        $result = (new PatternRegistryStage())->process($envelope);
        $row = $result->lines()[0];
        $this->assertSame('', $row->getDescription());
        $flags = PublicationFlags::parse($row->getPublicationFlags());
        $this->assertTrue($flags->isHard());
        $this->assertContains(PublicationSoftPatternIds::PROFESSIONAL_SERVICES_KEYWORD, $flags->patternIds());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_soft_pattern_applied', PatternRegistryStage::class . '::process');
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'publication_treasurer_alert', PatternRegistryStage::class . '::process');
    }
}
