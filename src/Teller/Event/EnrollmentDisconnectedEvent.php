<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller\Event;

use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Service\EnrollmentService;

final class EnrollmentDisconnectedEvent implements EnrollmentEvent
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function type(): string
    {
        return 'enrollment.disconnected';
    }

    public function apply(KingdomRecord $kingdom): void
    {
        $this->enrollments->markDisconnected($kingdom);
    }
}
