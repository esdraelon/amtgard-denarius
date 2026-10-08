<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\Impl;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Entity\KingdomCategoryRuleEntity;
use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\KingdomCategoryRuleRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

#[RepositoryOf('kingdom_category_rules', KingdomCategoryRuleEntity::class)]
class KingdomCategoryRuleRepository extends Repository implements EntityRepositoryInterface, KingdomCategoryRuleRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => 'kingdom_category_rules');
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => KingdomCategoryRuleEntity::class);
    }

    public function forKingdom(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            $this->clear();
            $this->kingdom_id = $kingdomId;
            $this->orderBy('id', OrderBy::ASC);

            return $this->collected();
        });
    }

    public function findById(int $kingdomId, int $ruleId): ?KingdomCategoryRuleRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $ruleId): ?KingdomCategoryRuleRecord {
            $this->clear();
            $this->id = $ruleId;
            $this->kingdom_id = $kingdomId;
            if ($this->find() !== 1 || ! $this->next()) {
                return null;
            }

            return $this->record($this->getCurrent());
        });
    }

    public function save(KingdomCategoryRuleRecord $rule): KingdomCategoryRuleRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($rule): KingdomCategoryRuleRecord {
            $entity = $rule->getId() === null
                ? $this->newRepositoryEntity()
                : $this->fetch($rule->getId());
            if (! $entity instanceof KingdomCategoryRuleEntity) {
                throw new \RuntimeException('Kingdom category rule entity was not created.');
            }
            $this->fill($entity, $rule);
            $saved = Optional::ofNullable($this->record($this->persist($entity)))
                ->orElseThrow(new \RuntimeException('Kingdom category rule was not saved.'));

            return $saved;
        });
    }

    public function removeRule(int $kingdomId, int $ruleId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $ruleId): void {
            $existing = $this->findById($kingdomId, $ruleId);
            if ($existing === null) {
                return;
            }
            $entity = $this->fetch($ruleId);
            if ($entity instanceof KingdomCategoryRuleEntity) {
                $this->delete($entity);
            }
        });
    }

    private function fill(KingdomCategoryRuleEntity $entity, KingdomCategoryRuleRecord $rule): void
    {
        DenariusLog::trace(__METHOD__, function () use ($entity, $rule): mixed {
            $entity->setKingdomId($rule->getKingdomId());
            $entity->setCategory($rule->getCategory());
            $entity->setFieldsJson(json_encode($rule->getFields(), JSON_THROW_ON_ERROR));
            $entity->setMatchType($rule->getMatchType());
            $entity->setRegexPattern($rule->getRegexPattern() !== '' ? $rule->getRegexPattern() : null);
            $entity->setToken($rule->getToken() !== '' ? $rule->getToken() : null);
            $anyOf = $rule->getAnyOfTokens();
            $entity->setAnyOfJson($anyOf !== [] ? json_encode($anyOf, JSON_THROW_ON_ERROR) : null);
            $flows = array_map(static fn (TransactionFlow $flow): string => $flow->value, $rule->getFlows());
            $entity->setFlowsJson(json_encode($flows, JSON_THROW_ON_ERROR));
            $entity->setConfidence($rule->getConfidence());

            return null;
        });
    }

    private function record(mixed $entity): ?KingdomCategoryRuleRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($entity): ?KingdomCategoryRuleRecord {
            if (! $entity instanceof KingdomCategoryRuleEntity) {
                return null;
            }

            return KingdomCategoryRuleRecord::builder()
                ->id($entity->getId())
                ->kingdomId((int) $entity->getKingdomId())
                ->category((string) $entity->getCategory())
                ->fields($this->decodeStringList((string) $entity->getFieldsJson()))
                ->matchType((string) $entity->getMatchType())
                ->regexPattern((string) ($entity->getRegexPattern() ?? ''))
                ->token((string) ($entity->getToken() ?? ''))
                ->anyOfTokens($this->decodeStringList((string) ($entity->getAnyOfJson() ?? '[]')))
                ->flows($this->decodeFlows((string) $entity->getFlowsJson()))
                ->confidence((int) $entity->getConfidence())
                ->build();
        });
    }

    /**
     * @return list<KingdomCategoryRuleRecord>
     */
    private function collected(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            if ($this->find() === 0) {
                return [];
            }
            $rows = [];
            while ($this->next()) {
                $record = $this->record($this->getCurrent());
                if ($record !== null) {
                    $rows[] = $record;
                }
            }

            return $rows;
        });
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($json): array {
            if ($json === '') {
                return [];
            }
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return [];
            }
            $out = [];
            foreach ($decoded as $item) {
                if (is_string($item) && $item !== '') {
                    $out[] = $item;
                }
            }

            return $out;
        });
    }

    /**
     * @return list<TransactionFlow>
     */
    private function decodeFlows(string $json): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($json): array {
            $strings = $this->decodeStringList($json);
            $flows = [];
            foreach ($strings as $raw) {
                $flows[] = TransactionFlow::fromStored($raw);
            }

            return $flows !== [] ? $flows : [TransactionFlow::Expense];
        });
    }
}
