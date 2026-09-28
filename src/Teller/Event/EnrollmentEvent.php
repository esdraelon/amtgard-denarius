<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller\Event;

use Amtgard\Denarius\Record\KingdomRecord;

interface EnrollmentEvent
{
    public function type(): string;

    public function apply(KingdomRecord $kingdom): void;
}
