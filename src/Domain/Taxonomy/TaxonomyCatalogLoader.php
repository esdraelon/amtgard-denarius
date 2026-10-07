<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Builder: load and validate taxonomy JSON packs from disk. */
final class TaxonomyCatalogLoader
{
    private const TAXONOMY_FILE = 'taxonomy.json';
    private const KEYWORDS_FILE = 'matchers/keywords.json';
    private const PROVIDER_HINTS_FILE = 'matchers/provider-hints.json';

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $relativePackPath,
        private readonly RegexPatternGuard $regexGuard = new RegexPatternGuard(),
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function load(): TaxonomyCatalog
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): TaxonomyCatalog {
            try {
                $packRoot = $this->packRoot();
                $taxonomyPayload = $this->readJson($packRoot . '/' . self::TAXONOMY_FILE);
                $keywordsPayload = $this->readJson($packRoot . '/' . self::KEYWORDS_FILE);
                $hintsPayload = $this->readJson($packRoot . '/' . self::PROVIDER_HINTS_FILE);

                $version = $this->requireString($taxonomyPayload, 'taxonomyVersion', 'taxonomy.json');
                $this->assertSameVersion($version, $keywordsPayload, 'keywords.json');
                $this->assertSameVersion($version, $hintsPayload, 'provider-hints.json');

                $categories = $this->parseCategories($taxonomyPayload);
                $retired = $this->parseRetired($taxonomyPayload, $categories);
                $keywordRules = $this->parseKeywordRules($keywordsPayload, $categories);
                $providerHints = $this->parseProviderHints($hintsPayload, $categories);

                $catalog = new TaxonomyCatalog($version, $categories, $retired, $keywordRules, $providerHints);
                DenariusLog::infoBranch('taxonomy_catalog_loaded', $method, [
                    'taxonomy_version' => $version,
                    'category_count' => count($categories),
                    'keyword_rule_count' => count($keywordRules),
                    'provider_hint_count' => count($providerHints),
                ]);

                return $catalog;
            } catch (TaxonomyCatalogValidationException $e) {
                DenariusLog::infoBranch('taxonomy_catalog_rejected', $method, [
                    'reason' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    private function packRoot(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            $relative = trim($this->relativePackPath);
            if ($relative === '') {
                throw new TaxonomyCatalogValidationException('Taxonomy pack path is empty.');
            }
            if ($relative[0] === '/') {
                return rtrim($relative, '/');
            }

            return rtrim($this->projectRoot, '/') . '/' . $relative;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($path): array {
            if (! is_readable($path)) {
                throw new TaxonomyCatalogValidationException(sprintf('Taxonomy file not readable: %s', $path));
            }
            $json = file_get_contents($path);
            if ($json === false || trim($json) === '') {
                throw new TaxonomyCatalogValidationException(sprintf('Taxonomy file is empty: %s', $path));
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new TaxonomyCatalogValidationException(sprintf('Taxonomy file is not an object: %s', $path));
            }

            return $payload;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key, string $fileLabel): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new TaxonomyCatalogValidationException(sprintf('Missing %s in %s.', $key, $fileLabel));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSameVersion(string $expected, array $payload, string $fileLabel): void
    {
        $version = $this->requireString($payload, 'taxonomyVersion', $fileLabel);
        if ($version !== $expected) {
            throw new TaxonomyCatalogValidationException(
                sprintf('taxonomyVersion mismatch in %s (expected %s, got %s).', $fileLabel, $expected, $version),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, TaxonomyCategoryDefinition>
     */
    private function parseCategories(array $payload): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): array {
            $rows = $payload['categories'] ?? null;
            if (! is_array($rows)) {
                throw new TaxonomyCatalogValidationException('taxonomy.json categories must be an array.');
            }

            $categories = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new TaxonomyCatalogValidationException('taxonomy.json category row must be an object.');
                }
                $slug = $this->requireString($row, 'slug', 'taxonomy.json category');
                $label = $this->requireString($row, 'label', 'taxonomy.json category');
                $flowRows = $row['flows'] ?? null;
                if (! is_array($flowRows) || $flowRows === []) {
                    throw new TaxonomyCatalogValidationException(sprintf('Category %s must declare flows.', $slug));
                }
                $flows = $this->parseFlowList($flowRows, sprintf('category %s', $slug));
                if (isset($categories[$slug])) {
                    throw new TaxonomyCatalogValidationException(sprintf('Duplicate category slug %s.', $slug));
                }
                $categories[$slug] = new TaxonomyCategoryDefinition($slug, $label, $flows);
            }

            foreach (['uncategorized', 'system.bank_verification'] as $required) {
                if (! isset($categories[$required])) {
                    throw new TaxonomyCatalogValidationException(sprintf('Missing required category %s.', $required));
                }
            }

            return $categories;
        });
    }

    /**
     * @param array<string, TaxonomyCategoryDefinition> $categories
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function parseRetired(array $payload, array $categories): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload, $categories): array {
            $block = $payload['retired'] ?? [];
            if (! is_array($block)) {
                throw new TaxonomyCatalogValidationException('taxonomy.json retired must be an object.');
            }
            $retired = [];
            foreach ($block as $from => $to) {
                if (! is_string($from) || ! is_string($to) || trim($to) === '') {
                    throw new TaxonomyCatalogValidationException('taxonomy.json retired keys and values must be strings.');
                }
                if (! isset($categories[$to])) {
                    throw new TaxonomyCatalogValidationException(
                        sprintf('Retired mapping %s => %s targets unknown category.', $from, $to),
                    );
                }
                $retired[$from] = $to;
            }

            return $retired;
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, TaxonomyCategoryDefinition> $categories
     * @return list<TaxonomyKeywordRule>
     */
    private function parseKeywordRules(array $payload, array $categories): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload, $categories): array {
            $rows = $payload['rules'] ?? null;
            if (! is_array($rows)) {
                throw new TaxonomyCatalogValidationException('keywords.json rules must be an array.');
            }

            $rules = [];
            $seenIds = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new TaxonomyCatalogValidationException('keywords.json rule must be an object.');
                }
                $id = $this->requireString($row, 'id', 'keywords.json rule');
                if (isset($seenIds[$id])) {
                    throw new TaxonomyCatalogValidationException(sprintf('Duplicate rule id %s.', $id));
                }
                $seenIds[$id] = true;
                $category = $this->requireString($row, 'category', $id);
                $this->assertKnownCategory($categories, $category, $id);
                $this->assertMatcherTargetAllowed($category, $id);
                $fields = $row['fields'] ?? null;
                if (! is_array($fields) || $fields === []) {
                    throw new TaxonomyCatalogValidationException(sprintf('Rule %s must declare fields.', $id));
                }
                $fieldList = [];
                foreach ($fields as $field) {
                    if (! is_string($field) || ($field !== 'description' && $field !== 'counterparty')) {
                        throw new TaxonomyCatalogValidationException(sprintf('Rule %s has invalid field.', $id));
                    }
                    $fieldList[] = $field;
                }
                $flowRows = $row['flows'] ?? null;
                if (! is_array($flowRows) || $flowRows === []) {
                    throw new TaxonomyCatalogValidationException(sprintf('Rule %s must declare flows.', $id));
                }
                $flows = $this->parseFlowList($flowRows, $id);
                $this->assertFlowsAllowedForCategory($categories, $category, $flows, $id);
                $confidence = $row['confidence'] ?? null;
                if (! is_int($confidence) || $confidence < 0 || $confidence > 100) {
                    throw new TaxonomyCatalogValidationException(sprintf('Rule %s confidence must be 0-100.', $id));
                }
                $match = $row['match'] ?? null;
                if (! is_array($match)) {
                    throw new TaxonomyCatalogValidationException(sprintf('Rule %s match must be an object.', $id));
                }
                [$matchType, $regexPattern, $anyOfTokens, $token] = $this->parseMatch($match, $id);

                $rules[] = new TaxonomyKeywordRule(
                    $id,
                    $category,
                    $fieldList,
                    $matchType,
                    $regexPattern,
                    $anyOfTokens,
                    $token,
                    $flows,
                    $confidence,
                );
            }

            return $rules;
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, TaxonomyCategoryDefinition> $categories
     * @return list<TaxonomyProviderHint>
     */
    private function parseProviderHints(array $payload, array $categories): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload, $categories): array {
            $rows = $payload['hints'] ?? null;
            if (! is_array($rows)) {
                throw new TaxonomyCatalogValidationException('provider-hints.json hints must be an array.');
            }

            $hints = [];
            $seenIds = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new TaxonomyCatalogValidationException('provider-hints.json hint must be an object.');
                }
                $id = $this->requireString($row, 'id', 'provider hint');
                if (isset($seenIds[$id])) {
                    throw new TaxonomyCatalogValidationException(sprintf('Duplicate rule id %s.', $id));
                }
                $seenIds[$id] = true;
                $provider = $this->requireString($row, 'provider', $id);
                $hint = $this->requireString($row, 'hint', $id);
                $category = $this->requireString($row, 'category', $id);
                $this->assertKnownCategory($categories, $category, $id);
                $this->assertMatcherTargetAllowed($category, $id);
                $flowRows = $row['flows'] ?? null;
                if (! is_array($flowRows) || $flowRows === []) {
                    throw new TaxonomyCatalogValidationException(sprintf('Hint %s must declare flows.', $id));
                }
                $flows = $this->parseFlowList($flowRows, $id);
                $this->assertFlowsAllowedForCategory($categories, $category, $flows, $id);
                $confidence = $row['confidence'] ?? null;
                if (! is_int($confidence) || $confidence < 0 || $confidence > 100) {
                    throw new TaxonomyCatalogValidationException(sprintf('Hint %s confidence must be 0-100.', $id));
                }

                $hints[] = new TaxonomyProviderHint($id, $provider, $hint, $category, $flows, $confidence);
            }

            return $hints;
        });
    }

    /**
     * @param list<string> $flowRows
     * @return list<TransactionFlow>
     */
    private function parseFlowList(array $flowRows, string $context): array
    {
        $flows = [];
        foreach ($flowRows as $flowValue) {
            if (! is_string($flowValue)) {
                throw new TaxonomyCatalogValidationException(sprintf('Invalid flow in %s.', $context));
            }
            $flow = TransactionFlow::fromStored($flowValue);
            if ($flow === null) {
                throw new TaxonomyCatalogValidationException(sprintf('Unknown flow %s in %s.', $flowValue, $context));
            }
            $flows[] = $flow;
        }

        return $flows;
    }

    /**
     * @param array<string, TaxonomyCategoryDefinition> $categories
     */
    private function assertKnownCategory(array $categories, string $category, string $ruleId): void
    {
        if (! isset($categories[$category])) {
            throw new TaxonomyCatalogValidationException(
                sprintf('Rule %s references unknown category %s.', $ruleId, $category),
            );
        }
    }

    private function assertMatcherTargetAllowed(string $category, string $ruleId): void
    {
        if (TaxonomyCatalog::isForbiddenMatcherTarget($category)) {
            throw new TaxonomyCatalogValidationException(
                sprintf('Rule %s must not target %s.', $ruleId, $category),
            );
        }
    }

    /**
     * @param array<string, TaxonomyCategoryDefinition> $categories
     * @param list<TransactionFlow> $flows
     */
    private function assertFlowsAllowedForCategory(
        array $categories,
        string $category,
        array $flows,
        string $ruleId,
    ): void {
        $definition = $categories[$category];
        foreach ($flows as $flow) {
            if (! $definition->permits($flow)) {
                throw new TaxonomyCatalogValidationException(
                    sprintf('Rule %s flow %s is not permitted for category %s.', $ruleId, $flow->value, $category),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $match
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function parseMatch(array $match, string $ruleId): array
    {
        $type = $match['type'] ?? null;
        if (! is_string($type)) {
            throw new TaxonomyCatalogValidationException(sprintf('Rule %s match.type is required.', $ruleId));
        }

        return match ($type) {
            'regex' => $this->parseRegexMatch($match, $ruleId),
            'anyOf' => $this->parseAnyOfMatch($match, $ruleId),
            'token' => $this->parseTokenMatch($match, $ruleId),
            default => throw new TaxonomyCatalogValidationException(
                sprintf('Rule %s has unknown match type %s.', $ruleId, $type),
            ),
        };
    }

    /**
     * @param array<string, mixed> $match
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function parseRegexMatch(array $match, string $ruleId): array
    {
        $pattern = $match['pattern'] ?? null;
        if (! is_string($pattern) || trim($pattern) === '') {
            throw new TaxonomyCatalogValidationException(sprintf('Rule %s regex pattern is required.', $ruleId));
        }
        $this->regexGuard->assertSafe($pattern, $ruleId);

        return ['regex', $pattern, [], ''];
    }

    /**
     * @param array<string, mixed> $match
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function parseAnyOfMatch(array $match, string $ruleId): array
    {
        $tokens = $match['tokens'] ?? null;
        if (! is_array($tokens) || $tokens === []) {
            throw new TaxonomyCatalogValidationException(sprintf('Rule %s anyOf tokens are required.', $ruleId));
        }
        $normalized = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || trim($token) === '') {
                throw new TaxonomyCatalogValidationException(sprintf('Rule %s anyOf token must be a string.', $ruleId));
            }
            $normalized[] = strtoupper(trim($token));
        }

        return ['anyOf', '', $normalized, ''];
    }

    /**
     * @param array<string, mixed> $match
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function parseTokenMatch(array $match, string $ruleId): array
    {
        $token = $match['token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            throw new TaxonomyCatalogValidationException(sprintf('Rule %s token is required.', $ruleId));
        }

        return ['token', '', [], strtoupper(trim($token))];
    }
}
