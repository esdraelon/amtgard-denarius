<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenterRegistry;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\LineRedactionStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class DisplayModeDisclosureTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
    }

    public function testDisplayModeLabelIsTraced(): void
    {
        $this->assertSame('Less redacted (pipeline)', DisplayMode::LessRedacted->label());
    }

    public function testFromStoredMapsLegacyAllToLessRedacted(): void
    {
        MethodLogAssert::reset();
        $this->assertSame(DisplayMode::LessRedacted, DisplayMode::fromStored('all'));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'display_mode_legacy_all', DisplayMode::class . '::fromStored');
    }

    public function testCanonicalDisplayModePersistsLessRedacted(): void
    {
        MethodLogAssert::reset();
        $validator = new PublicationSettingsValidator();
        $this->assertSame(DisplayMode::LessRedacted, $validator->canonicalDisplayMode(DisplayMode::All));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'display_mode_legacy_all', PublicationSettingsValidator::class . '::canonicalDisplayMode');
    }

    public function testLineRedactionStageStripsFieldsForRedactedTier(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->category('uncategorized')
            ->description('supplies')
            ->counterparty('Shop')
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01'),
            [$line],
        );

        $result = (new LineRedactionStage())->process($envelope);
        $ledger = $result->toLedgerLines()[0];
        $this->assertSame('', $ledger->getDescription());
        $this->assertSame('', $ledger->getCounterparty());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_line_redacted_tier', LineRedactionStage::class . '::process');
    }

    public function testLessRedactedPresenterUsesPipelineLines(): void
    {
        $line = LedgerLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->category('uncategorized')
            ->description('from pipeline')
            ->counterparty('Shop')
            ->accountName('Checking')
            ->build();
        $presented = StatementPresenterRegistry::standard()->for(DisplayMode::LessRedacted)->present([$line]);
        $this->assertSame('from pipeline', $presented[0]->getDescription());
    }
}
