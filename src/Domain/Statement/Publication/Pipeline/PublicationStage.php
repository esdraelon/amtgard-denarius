<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

/** Chain of responsibility: one transformation on a publication envelope. */
interface PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope;
}
