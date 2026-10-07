<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Factory: builds ordered public and manager publication pipelines. */
final class PublicationPipelineFactory
{
    public function __construct(
        private readonly ?TaxonomyCatalog $catalog = null,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public static function standard(?TaxonomyCatalog $catalog = null): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self($catalog));
    }

    public function forPublicRead(): PublicationPipeline
    {
        return DenariusLog::trace(__METHOD__, function (): PublicationPipeline {
            if ($this->catalog === null) {
                throw new \RuntimeException('TaxonomyCatalog is required for the public publication pipeline.');
            }

            return new PublicationPipeline([
                new EmbargoStage(),
                new HardRedactionStage(),
                new PublicationStatusStage(),
                new PatternRegistryStage(),
                new CategoryLabelStage($this->catalog),
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
