<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Service;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback;
use Amtgard\PHPUnit\AmtgardTestCase;

final class ManagerLedgerSyncFeedbackTest extends AmtgardTestCase
{
    public function testDisconnectedKingdomHidesImportFeedback(): void
    {
        $kingdom = KingdomRecord::builder()->enrollmentStatus('disconnected')->build();
        $feedback = (new ManagerLedgerSyncFeedback())->forManage($kingdom, false);
        $this->assertFalse($feedback['showImportNotice']);
        $this->assertNull($feedback['statusLabel']);
    }

    public function testConnectedFailedSyncShowsRetryCopy(): void
    {
        $kingdom = KingdomRecord::builder()
            ->enrollmentStatus('connected')
            ->lastSyncAttemptedAt('2026-10-07T16:45:25+00:00')
            ->lastSyncStatus('failed')
            ->lastSyncError('Stripe request failed (HTTP 400).')
            ->build();
        $feedback = (new ManagerLedgerSyncFeedback())->forManage($kingdom, false);
        $this->assertTrue($feedback['showImportNotice']);
        $this->assertStringContainsString('2026-10-07 16:45 UTC', (string) $feedback['lastCheckedAt']);
        $this->assertStringContainsString('failed', (string) $feedback['statusLabel']);
        $this->assertStringContainsString('HTTP 400', (string) $feedback['statusDetail']);
    }
}
