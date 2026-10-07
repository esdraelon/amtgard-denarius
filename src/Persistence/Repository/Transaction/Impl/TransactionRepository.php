<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Transaction\Impl;

use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\TransactionEntity;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

#[RepositoryOf('transactions', TransactionEntity::class)]
class TransactionRepository extends Repository implements EntityRepositoryInterface, TransactionRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return 'transactions';
        });
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return TransactionEntity::class;
        });
    }

    public function upsert(TransactionRecord $transaction): void
    {
        DenariusLog::trace(__METHOD__, function () use ($transaction): mixed {
            $existing = $this->fetchBy('teller_transaction_id', $transaction->getTellerTransactionId());
            $entity = $existing instanceof TransactionEntity ? $existing : $this->newRepositoryEntity();
            if (!$entity instanceof TransactionEntity) {
                throw new \RuntimeException('Transaction entity was not created.');
            }
            $this->fill($entity, $transaction, $existing instanceof TransactionEntity);
            $this->persist($entity);

            return null;
        });
    }

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->record($this->fetchBy('teller_transaction_id', $tellerTransactionId));
        });
    }

    public function forKingdomPublished(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return array_values(array_filter(
                $this->forKingdom($kingdomId),
                static fn (TransactionRecord $row): bool => $row->getPublishedAt() !== null && $row->getPublishedAt() !== '',
            ));
        });
    }

    public function markPublished(int $kingdomId, string $tellerTransactionId, string $publishedAt): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $tellerTransactionId, $publishedAt): mixed {
            $record = $this->findByTellerTransactionId($tellerTransactionId);
            if ($record === null || $record->getKingdomId() !== $kingdomId) {
                throw new \InvalidArgumentException('Transaction not found.');
            }
            $entity = $this->fetchBy('teller_transaction_id', $tellerTransactionId);
            if (!$entity instanceof TransactionEntity) {
                throw new \RuntimeException('Transaction entity was not loaded.');
            }
            $entity->setPublishedAt($publishedAt);
            $this->persist($entity);

            return null;
        });
    }

    public function markUnpublished(int $kingdomId, string $tellerTransactionId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $tellerTransactionId): mixed {
            $record = $this->findByTellerTransactionId($tellerTransactionId);
            if ($record === null || $record->getKingdomId() !== $kingdomId) {
                throw new \InvalidArgumentException('Transaction not found.');
            }
            $entity = $this->fetchBy('teller_transaction_id', $tellerTransactionId);
            if (!$entity instanceof TransactionEntity) {
                throw new \RuntimeException('Transaction entity was not loaded.');
            }
            $entity->setPublishedAt(null);
            $this->persist($entity);

            return null;
        });
    }

    public function forKingdom(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            $this->clear();
            $this->kingdom_id = $kingdomId;
            $this->orderBy('posted_on', OrderBy::ASC);
            $this->orderBy('id', OrderBy::ASC);

            return $this->collected();
        });
    }

    private function fill(TransactionEntity $entity, TransactionRecord $transaction, bool $existing): void
    {
        DenariusLog::trace(__METHOD__, function () use ($entity, $transaction, $existing): mixed {
            $entity->setKingdomId($transaction->getKingdomId());
            $entity->setTellerTransactionId($transaction->getTellerTransactionId());
            $entity->setTellerAccountId($transaction->getTellerAccountId());
            $entity->setPostedOn($transaction->getPostedOn());
            $entity->setAmountCents($transaction->getAmountCents());
            $entity->setCategory($transaction->getCategory());
            $entity->setProviderCategory($transaction->getProviderCategory());
            $entity->setCategorySource($transaction->getCategorySource());
            $entity->setCategoryRuleId($transaction->getCategoryRuleId());
            $entity->setCategoryConfidence($transaction->getCategoryConfidence());
            $entity->setCategorySuggested($transaction->getCategorySuggested());
            $entity->setTaxonomyVersion($transaction->getTaxonomyVersion());
            $entity->setDescription($transaction->getDescription());
            $entity->setCounterparty($transaction->getCounterparty());
            $entity->setStatus($transaction->getStatus());
            $entity->setPublishableAfter($transaction->getPublishableAfter());
            if (!$existing) {
                $entity->setPublishedAt($transaction->getPublishedAt());
                $entity->setPublicationFlags($transaction->getPublicationFlags());
            } elseif ($transaction->getPublishedAt() !== null) {
                $entity->setPublishedAt($transaction->getPublishedAt());
            }
            if ($transaction->getPublicationFlags() !== null) {
                $entity->setPublicationFlags($transaction->getPublicationFlags());
            }

            return null;
        });
    }

    private function record(mixed $entity): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($entity): ?TransactionRecord {
            if (!$entity instanceof TransactionEntity) {
                return null;
            }

            return TransactionRecord::builder()
                ->id($entity->getId())
                ->kingdomId((int) $entity->getKingdomId())
                ->tellerTransactionId((string) $entity->getTellerTransactionId())
                ->tellerAccountId((string) $entity->getTellerAccountId())
                ->postedOn((string) $entity->getPostedOn())
                ->amountCents((int) $entity->getAmountCents())
                ->category((string) ($entity->getCategory() ?? 'uncategorized'))
                ->providerCategory($entity->getProviderCategory())
                ->categorySource((string) ($entity->getCategorySource() ?? 'fallback'))
                ->categoryRuleId($entity->getCategoryRuleId())
                ->categoryConfidence((int) ($entity->getCategoryConfidence() ?? 0))
                ->categorySuggested($entity->getCategorySuggested())
                ->taxonomyVersion($entity->getTaxonomyVersion())
                ->description((string) $entity->getDescription())
                ->counterparty((string) $entity->getCounterparty())
                ->status((string) $entity->getStatus())
                ->publishedAt($entity->getPublishedAt())
                ->publishableAfter($entity->getPublishableAfter())
                ->publicationFlags($entity->getPublicationFlags())
                ->build();
        });
    }

    /**
     * @return list<TransactionRecord>
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
}
