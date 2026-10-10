<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Factory: builds ordered public and manager publication pipelines. */
final class PublicationPipelineFactory
{
    public function __construct(
        private readonly ?TaxonomyCatalog $catalog = null,
        private readonly ?CategoryCatalog $categories = null,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public static function standard(
        ?TaxonomyCatalog $catalog = null,
        ?CategoryCatalog $categories = null,
    ): self {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self($catalog, $categories));
    }

    public function forPublicRead(): PublicationPipeline
    {
        return DenariusLog::trace(__METHOD__, function (): PublicationPipeline {
            if ($this->catalog === null || $this->categories === null) {
                throw new \RuntimeException('TaxonomyCatalog and CategoryCatalog are required for the public publication pipeline.');
            }

            return new PublicationPipeline([
                new EmbargoStage(),
                new HardRedactionStage(),
                new PublicationStatusStage(),
                new PatternRegistryStage(),
                new ManagerDescriptionRedactStage(),
                new CategoryLabelStage($this->catalog, $this->categories),
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
