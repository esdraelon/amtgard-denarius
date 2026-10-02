<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class DisconnectLedgerNotice implements LedgerNotice
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function action(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return ProviderNotice::DISCONNECT;
        });
    }

    public function apply(KingdomRecord $kingdom): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom): mixed {
            $this->enrollments->markDisconnected($kingdom);

            return null;
        });
    }
}
