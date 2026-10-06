<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of responsibility: ordered publication stages. */
final class PublicationPipeline
{
    /**
     * @param list<PublicationStage> $stages
     */
    public function __construct(private readonly array $stages)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function run(PublicationEnvelope $envelope): PublicationEnvelope
    {
        return DenariusLog::trace(__METHOD__, function () use ($envelope): PublicationEnvelope {
            foreach ($this->stages as $stage) {
                $envelope = $stage->process($envelope);
            }

            return $envelope;
        });
    }
}
