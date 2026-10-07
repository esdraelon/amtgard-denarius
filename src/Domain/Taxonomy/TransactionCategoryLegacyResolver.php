<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: maps legacy transaction category strings to taxonomy slugs and provider hints. */
final class TransactionCategoryLegacyResolver
{
    private function __construct(private readonly TransactionCategoryLegacyNormalizer $normalizer)
    {
        DenariusLog::enter(__METHOD__);
    }

    public static function fromTaxonomyJson(string $path): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self(
            TransactionCategoryLegacyNormalizer::fromTaxonomyJson($path),
        ));
    }

    /**
     * @return array{category: string, providerCategory: ?string, categorySource: string}
     */
    public function normalizeStoredCategory(string $stored): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->normalizer->normalize($stored));
    }
}
