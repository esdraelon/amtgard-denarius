<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\EnrollmentService;

final class DisconnectLedgerNotice implements LedgerNotice
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function action(): string
    {
        return ProviderNotice::DISCONNECT;
    }

    public function apply(KingdomRecord $kingdom): void
    {
        $this->enrollments->markDisconnected($kingdom);
    }
}
