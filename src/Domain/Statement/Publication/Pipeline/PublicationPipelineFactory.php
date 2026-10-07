<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Factory: builds ordered public and manager publication pipelines. */
final class PublicationPipelineFactory
{
    public static function standard(): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self());
    }

    public function forPublicRead(): PublicationPipeline
    {
        return DenariusLog::trace(__METHOD__, function (): PublicationPipeline {
            return new PublicationPipeline([
                new EmbargoStage(),
                new HardRedactionStage(),
                new PublicationStatusStage(),
                new PatternRegistryStage(),
                new AmountQuantizationStage(),
                new LineRedactionStage(),
                new DeferredPublicationStage('aggregate'),
                new BalanceCoarseningStage(),
                new EnvelopeReviewStage(),
            ]);
        });
    }

    public function forManagerReview(): PublicationPipeline
    {
        return DenariusLog::trace(__METHOD__, function (): PublicationPipeline {
            return new PublicationPipeline([
                new HardRedactionStage(),
            ]);
        });
    }
}
