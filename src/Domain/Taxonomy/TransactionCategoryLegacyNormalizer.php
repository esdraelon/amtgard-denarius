<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object helper: legacy category normalization without logging (safe for Phinx). */
final class TransactionCategoryLegacyNormalizer
{
    /** @param array<string, true> $knownSlugs */
    private function __construct(private readonly array $knownSlugs)
    {
    }

    public static function fromTaxonomyJson(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Taxonomy pack could not be read.');
        }
        /** @var array{categories: list<array{slug: string}>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $known = [];
        foreach ($data['categories'] as $category) {
            $known[$category['slug']] = true;
        }

        return new self($known);
    }

    /**
     * @return array{category: string, providerCategory: ?string, categorySource: string}
     */
    public function normalize(string $stored): array
    {
        if ($stored !== 'general' && isset($this->knownSlugs[$stored])) {
            return [
                'category' => $stored,
                'providerCategory' => null,
                'categorySource' => CategorySource::Fallback->value,
            ];
        }

        $providerCategory = $stored === 'general' || $stored === '' ? null : $stored;

        return [
            'category' => 'uncategorized',
            'providerCategory' => $providerCategory,
            'categorySource' => CategorySource::Fallback->value,
        ];
    }
}
