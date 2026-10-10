<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternValidator;
use Amtgard\Denarius\Domain\Taxonomy\PatternGlob;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyKeywordRule;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\KingdomCategoryRuleRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: CRUD kingdom category patterns and trigger recategorize. */
final class KingdomPatternService
{
    public function __construct(
        private readonly KingdomCategoryRuleRepositoryInterface $rules,
        private readonly KingdomPatternValidator $validator,
        private readonly TaxonomyCatalog $catalog,
        private readonly CategoryCatalog $categories,
        private readonly KingdomCategoryAssigner $categoryAssigner,
        private readonly TransactionRecategorizer $recategorizer,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $prefill
     * @return array<string, mixed>
     */
    public function enrichFormPrefill(KingdomRecord $kingdom, array $prefill): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $prefill): array {
            $categoryId = (int) ($prefill['categoryId'] ?? $prefill['category_id'] ?? 0);
            $prefill['categoryDisplay'] = $categoryId <= 0
                ? ''
                : $this->categoryAssigner->displayFor($categoryId, TransactionFlow::Expense);
            if (! isset($prefill['matchType'])) {
                $prefill['matchType'] = 'token';
            }

            return $prefill;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listViews(KingdomRecord $kingdom): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): array {
            $kingdomId = (int) $kingdom->getId();
            $views = [];
            foreach ($this->rules->forKingdom($kingdomId) as $rule) {
                $view = $rule->manageView($this->categories);
                $flows = $rule->getFlows();
                $flowHint = $flows[0] ?? TransactionFlow::Expense;
                $categoryId = (int) $view['categoryId'];
                $view['categoryDisplay'] = $this->categoryAssigner->displayFor($categoryId, $flowHint);
                $view['categoryFlow'] = $this->categoryAssigner->flowFor($categoryId, $flowHint)->value;
                $views[] = $view;
            }

            return $views;
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public function saveNew(KingdomRecord $kingdom, array $body): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $body): void {
            $this->persistNew($kingdom, $body);
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public function persistNew(KingdomRecord $kingdom, array $body, ?int $resolvedCategoryId = null): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $resolvedCategoryId): void {
            $saved = $this->rules->save($this->recordFromBody($kingdom, null, $body, $resolvedCategoryId));
            DenariusLog::infoBranch('kingdom_pattern_saved', self::class . '::saveNew', [
                'kingdom_id' => (int) $kingdom->getId(),
                'rule_id' => $saved->publicRuleId(),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    /**
     * @param array<string, mixed> $body
     */
    public function anchorAmountCents(array $body): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->categoryAssigner->anchorAmountCents($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function resolveCategoryForSave(KingdomRecord $kingdom, array $body): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->categoryAssigner->resolveForPattern($kingdom, $body));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function draftKeywordRule(KingdomRecord $kingdom, array $body, string $ruleId): TaxonomyKeywordRule
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $ruleId): TaxonomyKeywordRule {
            $parts = $this->validatedMatchParts($kingdom, $body, $ruleId);

            return $this->keywordRuleFromParts($ruleId, $parts);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public function draftKeywordRuleForPreview(KingdomRecord $kingdom, array $body, string $ruleId): TaxonomyKeywordRule
    {
        return DenariusLog::trace(__METHOD__, function () use ($body, $ruleId): TaxonomyKeywordRule {
            $parts = $this->previewMatchParts($body, $ruleId);

            return $this->keywordRuleFromParts($ruleId, $parts);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(KingdomRecord $kingdom, int $ruleId, array $body): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $ruleId, $body): void {
            $existing = $this->rules->findById((int) $kingdom->getId(), $ruleId);
            if ($existing === null) {
                throw new \InvalidArgumentException('That pattern was not found.');
            }
            $record = $this->recordFromBody($kingdom, $ruleId, $body);
            $saved = $this->rules->save($record);
            DenariusLog::infoBranch('kingdom_pattern_saved', self::class . '::update', [
                'kingdom_id' => (int) $kingdom->getId(),
                'rule_id' => $saved->publicRuleId(),
            ]);
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom);
        });
    }

    public function delete(KingdomRecord $kingdom, int $ruleId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $ruleId): void {
            $existing = $this->rules->findById((int) $kingdom->getId(), $ruleId);
            if ($existing === null) {
                return;
            }
            $publicId = $existing->publicRuleId();
            $this->rules->removeRule((int) $kingdom->getId(), $ruleId);
            DenariusLog::infoBranch('kingdom_pattern_deleted', self::class . '::delete', [
                'kingdom_id' => (int) $kingdom->getId(),
                'rule_id' => $publicId,
            ]);
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function bulkSave(KingdomRecord $kingdom, array $rows): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $rows): void {
            $kingdomId = (int) $kingdom->getId();
            foreach ($rows as $ruleId => $body) {
                if (! is_array($body)) {
                    continue;
                }
                $id = is_int($ruleId) ? $ruleId : (int) $ruleId;
                if ($id <= 0) {
                    continue;
                }
                $existing = $this->rules->findById($kingdomId, $id);
                if ($existing === null) {
                    continue;
                }
                $saved = $this->rules->save($this->recordFromBody($kingdom, $id, $body));
                DenariusLog::infoBranch('kingdom_pattern_saved', self::class . '::bulkSave', [
                    'kingdom_id' => $kingdomId,
                    'rule_id' => $saved->publicRuleId(),
                ]);
            }
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function recordFromBody(
        KingdomRecord $kingdom,
        ?int $ruleId,
        array $body,
        ?int $resolvedCategoryId = null,
    ): KingdomCategoryRuleRecord {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $ruleId, $body, $resolvedCategoryId): KingdomCategoryRuleRecord {
            $kingdomId = (int) $kingdom->getId();
            $draftRuleId = $ruleId === null ? 'draft' : 'kr.' . $ruleId;
            $parts = $resolvedCategoryId !== null
                ? $this->validatedMatchPartsWithCategory($body, $draftRuleId, $resolvedCategoryId)
                : $this->validatedMatchParts($kingdom, $body, $draftRuleId);

            $builder = KingdomCategoryRuleRecord::builder()
                ->kingdomId($kingdomId)
                ->categoryId($parts['categoryId'])
                ->fields($parts['fields'])
                ->matchType($parts['matchType'])
                ->regexPattern($parts['regexPattern'])
                ->token($parts['token'])
                ->anyOfTokens($parts['anyOfTokens'])
                ->flows($parts['flows'])
                ->confidence($parts['confidence']);
            if ($ruleId !== null) {
                $builder->id($ruleId);
            }

            return $builder->build();
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *     category: string,
     *     matchType: string,
     *     token: string,
     *     regexPattern: string,
     *     anyOfTokens: list<string>,
     *     fields: list<string>,
     *     flows: list<TransactionFlow>,
     *     confidence: int
     * }
     */
    /**
     * @param array<string, mixed> $body
     * @return array{
     *     category: string,
     *     matchType: string,
     *     token: string,
     *     regexPattern: string,
     *     anyOfTokens: list<string>,
     *     fields: list<string>,
     *     flows: list<TransactionFlow>,
     *     confidence: int
     * }
     */
    private function validatedMatchPartsWithCategory(array $body, string $draftRuleId, int $categoryId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body, $draftRuleId, $categoryId): array {
            $parts = $this->matchPartsWithoutCategory($body, $draftRuleId);
            $parts['categoryId'] = $categoryId;

            return $parts;
        });
    }

    private function validatedMatchParts(KingdomRecord $kingdom, array $body, string $draftRuleId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $draftRuleId): array {
            $categoryId = $this->categoryAssigner->resolveForPattern($kingdom, $body);
            $matchType = (string) ($body['match_type'] ?? 'token');
            $token = PatternGlob::normalizeToken((string) ($body['token'] ?? ''));
            $regexPattern = trim((string) ($body['regex_pattern'] ?? ''));
            $anyOfRaw = (string) ($body['any_of'] ?? '');
            $this->validator->assertMatch($matchType, $token, $regexPattern, $anyOfRaw, $draftRuleId);
            $fields = $this->fieldsFromBody($body);
            $flows = $this->resolvePatternFlows($body);
            $confidence = (int) ($body['confidence'] ?? 100);
            if ($confidence < 70) {
                $confidence = 100;
            }
            $anyOfTokens = $matchType === 'anyOf'
                ? array_values(array_filter(array_map(
                    static fn (string $part): string => PatternGlob::normalizeToken($part),
                    explode(',', $anyOfRaw),
                ), static fn (string $part): bool => $part !== ''))
                : [];

            return [
                'categoryId' => $categoryId,
                'matchType' => $matchType,
                'token' => $token,
                'regexPattern' => $regexPattern,
                'anyOfTokens' => $anyOfTokens,
                'fields' => $fields,
                'flows' => $flows,
                'confidence' => $confidence,
            ];
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function fieldsFromBody(array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): array {
            $selected = $body['fields'] ?? ['description', 'counterparty'];
            if (! is_array($selected)) {
                return ['description', 'counterparty'];
            }
            $allowed = ['description', 'counterparty'];
            $fields = [];
            foreach ($selected as $field) {
                if (is_string($field) && in_array($field, $allowed, true)) {
                    $fields[] = $field;
                }
            }

            return $fields !== [] ? $fields : ['description', 'counterparty'];
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return list<TransactionFlow>
     */
    private function parseFlowsFromBody(array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): array {
            $raw = $body['flows'] ?? [];
            if (! is_array($raw)) {
                return [];
            }
            $flows = [];
            foreach ($raw as $item) {
                if (! is_string($item) || $item === '') {
                    continue;
                }
                $flow = TransactionFlow::fromStored($item);
                if ($flow instanceof TransactionFlow) {
                    $flows[] = $flow;
                }
            }

            return $flows;
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *     category: string,
     *     matchType: string,
     *     token: string,
     *     regexPattern: string,
     *     anyOfTokens: list<string>,
     *     fields: list<string>,
     *     flows: list<TransactionFlow>,
     *     confidence: int
     * }
     */
    private function previewMatchParts(array $body, string $draftRuleId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body, $draftRuleId): array {
            $parts = $this->matchPartsWithoutCategory($body, $draftRuleId);
            $parts['categoryId'] = $this->categories->uncategorizedId();

            return $parts;
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *     matchType: string,
     *     token: string,
     *     regexPattern: string,
     *     anyOfTokens: list<string>,
     *     fields: list<string>,
     *     flows: list<TransactionFlow>,
     *     confidence: int
     * }
     */
    private function matchPartsWithoutCategory(array $body, string $draftRuleId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body, $draftRuleId): array {
            $matchType = (string) ($body['match_type'] ?? 'token');
            $token = PatternGlob::normalizeToken((string) ($body['token'] ?? ''));
            $regexPattern = trim((string) ($body['regex_pattern'] ?? ''));
            $anyOfRaw = (string) ($body['any_of'] ?? '');
            $this->validator->assertMatch($matchType, $token, $regexPattern, $anyOfRaw, $draftRuleId);
            $fields = $this->fieldsFromBody($body);
            $flows = $this->resolvePatternFlows($body);
            $confidence = (int) ($body['confidence'] ?? 100);
            if ($confidence < 70) {
                $confidence = 100;
            }
            $anyOfTokens = $matchType === 'anyOf'
                ? array_values(array_filter(array_map(
                    static fn (string $part): string => PatternGlob::normalizeToken($part),
                    explode(',', $anyOfRaw),
                ), static fn (string $part): bool => $part !== ''))
                : [];

            return [
                'matchType' => $matchType,
                'token' => $token,
                'regexPattern' => $regexPattern,
                'anyOfTokens' => $anyOfTokens,
                'fields' => $fields,
                'flows' => $flows,
                'confidence' => $confidence,
            ];
        });
    }

    /**
     * @param array{
     *     category: string,
     *     matchType: string,
     *     token: string,
     *     regexPattern: string,
     *     anyOfTokens: list<string>,
     *     fields: list<string>,
     *     flows: list<TransactionFlow>,
     *     confidence: int
     * } $parts
     */
    private function keywordRuleFromParts(string $ruleId, array $parts): TaxonomyKeywordRule
    {
        return DenariusLog::trace(__METHOD__, function () use ($ruleId, $parts): TaxonomyKeywordRule {
            return new TaxonomyKeywordRule(
                $ruleId,
                $this->categories->lineageKeyForId($parts['categoryId']),
                $parts['fields'],
                $parts['matchType'],
                $parts['regexPattern'],
                $parts['anyOfTokens'],
                $parts['token'],
                $parts['flows'],
                $parts['confidence'],
            );
        });
    }

    /**
     * Pattern direction follows the anchor row's amount sign (negative vs positive).
     *
     * @param array<string, mixed> $body
     * @return list<TransactionFlow>
     */
    private function resolvePatternFlows(array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): array {
            $anchor = $this->categoryAssigner->anchorAmountCents($body);
            if ($anchor !== null) {
                if ($anchor > 0) {
                    return [TransactionFlow::Income];
                }
                return [TransactionFlow::Expense];
            }
            $fromBody = array_values(array_filter(
                $this->parseFlowsFromBody($body),
                static fn (?TransactionFlow $flow): bool => $flow instanceof TransactionFlow,
            ));
            if (count($fromBody) === 1) {
                return $fromBody;
            }

            return [TransactionFlow::Expense];
        });
    }
}
