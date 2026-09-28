<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller\Event;

use Amtgard\Denarius\Record\KingdomRecord;

final class IgnoredEnrollmentEvent implements EnrollmentEvent
{
    public function type(): string
    {
        return '';
    }

    public function apply(KingdomRecord $kingdom): void
    {
    }
}
