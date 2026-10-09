<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Null object stage: reserved for a later milestone, passes the envelope through. */
final class DeferredPublicationStage implements PublicationStage
{
    public function __construct(private readonly string $stageId)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            DenariusLog::debugBranch('publication_stage_deferred', $method, ['stage' => $this->stageId]);

            return $envelope;
        });
    }
}
