<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pattern;

use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;

/** Strategy: versioned publication rule matched against a pipeline line. */
interface PublicationPattern
{
    public function id(): string;

    public function version(): int;

    public function tier(): string;

    public function matches(PublicationCandidateLine $line): bool;

    public function apply(PublicationCandidateLine $line): PublicationCandidateLine;
}
