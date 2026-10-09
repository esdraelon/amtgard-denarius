<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationSelection;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Http\ReviewSelectionParser;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class ReviewSelectionParserTest extends AmtgardTestCase
{
    public function testMapsListedIdsToCheckboxSelections(): void
    {
        $selections = ReviewSelectionParser::fromBody([
            'review_id' => ['tx-a', ' tx-b ', 42, '', ['nested'], 'tx-c'],
            'review' => [
                'tx-a' => ['publish' => '1', 'embargo' => '1'],
                'tx-b' => ['redact' => '1'],
                '42' => 'not-an-array',
                'tx-ignored' => ['publish' => '1'],
            ],
        ]);

        $this->assertSame(['tx-a', 'tx-b', '42', 'tx-c'], array_map(static fn (PublicationSelection $s): string => $s->tellerTransactionId(), $selections));
        $this->assertSame(['publish' => true, 'redact' => false, 'embargo' => true], $selections[0]->view());
        $this->assertSame(['publish' => false, 'redact' => true, 'embargo' => false], $selections[1]->view());
        $this->assertSame(['publish' => false, 'redact' => false, 'embargo' => false], $selections[2]->view());
        $this->assertSame(['publish' => false, 'redact' => false, 'embargo' => false], $selections[3]->view());
    }

    public function testMissingReviewMapLeavesEveryChoiceCleared(): void
    {
        $selections = ReviewSelectionParser::fromBody(['review_id' => ['tx-a'], 'review' => 'oops']);
        $this->assertCount(1, $selections);
        $this->assertFalse($selections[0]->wantsPublished());
        $this->assertSame([], ReviewSelectionParser::fromBody([]));
    }

    public function testNonListIdsLogAndReturnNothing(): void
    {
        MethodLogAssert::reset();
        $this->assertSame([], ReviewSelectionParser::fromBody(['review_id' => 'tx-a']));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'review_selection_ids_invalid', ReviewSelectionParser::class . '::fromBody');
    }
}
