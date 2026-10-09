<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternValidator;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
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
        private readonly TransactionRecategorizer $recategorizer,
    ) {
        DenariusLog::enter(__METHOD__);
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
                $views[] = $rule->manageView($this->catalog);
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
            $record = $this->recordFromBody($kingdom, null, $body);
            $saved = $this->rules->save($record);
            DenariusLog::infoBranch('kingdom_pattern_saved', self::class . '::saveNew', [
                'kingdom_id' => (int) $kingdom->getId(),
                'rule_id' => $saved->publicRuleId(),
            ]);
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom);
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
    private function recordFromBody(KingdomRecord $kingdom, ?int $ruleId, array $body): KingdomCategoryRuleRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $ruleId, $body): KingdomCategoryRuleRecord {
            $kingdomId = (int) $kingdom->getId();
            $category = $this->validator->assertCategorySlug((string) ($body['category'] ?? ''));
            $matchType = (string) ($body['match_type'] ?? 'token');
            $token = strtoupper(trim((string) ($body['token'] ?? '')));
            $regexPattern = trim((string) ($body['regex_pattern'] ?? ''));
            $anyOfRaw = (string) ($body['any_of'] ?? '');
            $draftRuleId = $ruleId === null ? 'draft' : 'kr.' . $ruleId;
            $this->validator->assertMatch($matchType, $token, $regexPattern, $anyOfRaw, $draftRuleId);
            $fields = $this->fieldsFromBody($body);
            $flows = $this->flowsFromBody($body);
            $confidence = (int) ($body['confidence'] ?? 100);
            if ($confidence < 70) {
                $confidence = 100;
            }
            $anyOfTokens = $matchType === 'anyOf'
                ? array_values(array_filter(array_map(
                    static fn (string $part): string => strtoupper(trim($part)),
                    explode(',', $anyOfRaw),
                ), static fn (string $part): bool => $part !== ''))
                : [];

            $builder = KingdomCategoryRuleRecord::builder()
                ->kingdomId($kingdomId)
                ->category($category)
                ->fields($fields)
                ->matchType($matchType)
                ->regexPattern($regexPattern)
                ->token($token)
                ->anyOfTokens($anyOfTokens)
                ->flows($flows)
                ->confidence($confidence);
            if ($ruleId !== null) {
                $builder->id($ruleId);
            }

            return $builder->build();
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
    private function flowsFromBody(array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): array {
            $raw = $body['flows'] ?? ['expense'];
            if (! is_array($raw)) {
                return [TransactionFlow::Expense];
            }
            $flows = [];
            foreach ($raw as $item) {
                if (is_string($item) && $item !== '') {
                    $flows[] = TransactionFlow::fromStored($item);
                }
            }

            return $flows !== [] ? $flows : [TransactionFlow::Expense];
        });
    }
}
