<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Manage-page copy for background import timing and last sync attempt. */
final class ManagerLedgerSyncFeedback
{
    public const IMPORT_NOTICE = 'Denarius imports transactions in the background after you connect. '
        . 'That usually finishes within a few minutes; some banks can take up to an hour. '
        . 'Reload this page to see new rows.';

    public function __construct()
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array{showImportNotice: bool, importNotice: ?string, lastCheckedAt: ?string, statusLabel: ?string, statusDetail: ?string}
     */
    public function forManage(KingdomRecord $kingdom, bool $hasReviewRows): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $hasReviewRows): array {
            if ($kingdom->getEnrollmentStatus() !== 'connected') {
                return [
                    'showImportNotice' => false,
                    'importNotice' => null,
                    'lastCheckedAt' => null,
                    'statusLabel' => null,
                    'statusDetail' => null,
                ];
            }

            $attempted = $this->formatWhen($kingdom->getLastSyncAttemptedAt());
            $status = $kingdom->getLastSyncStatus();

            return [
                'showImportNotice' => true,
                'importNotice' => self::IMPORT_NOTICE,
                'lastCheckedAt' => $attempted,
                'statusLabel' => $this->statusLabel($status, $hasReviewRows, $attempted),
                'statusDetail' => $this->statusDetail($status, $kingdom->getLastSyncError()),
            ];
        });
    }

    private function statusLabel(?string $status, bool $hasReviewRows, ?string $attempted): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($status, $hasReviewRows, $attempted): string {
            if ($attempted === null) {
                return 'Waiting for first import check';
            }
            if ($status === 'failed') {
                return 'Last check failed — retrying in the background';
            }
            if ($status === 'succeeded' && !$hasReviewRows) {
                return 'Last check succeeded — no transactions in the import window yet';
            }
            if ($status === 'succeeded') {
                return 'Last check succeeded';
            }

            return 'Import check in progress';
        });
    }

    private function statusDetail(?string $status, ?string $error): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($status, $error): ?string {
            if ($status !== 'failed' || $error === null || trim($error) === '') {
                return null;
            }

            $trimmed = trim($error);
            if (strlen($trimmed) > 160) {
                $trimmed = substr($trimmed, 0, 157) . '...';
            }

            return $trimmed;
        });
    }

    private function formatWhen(?string $iso): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($iso): ?string {
            if ($iso === null || trim($iso) === '') {
                return null;
            }
            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $iso)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $iso);
            if ($parsed === false) {
                return $iso;
            }

            return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        });
    }
}
