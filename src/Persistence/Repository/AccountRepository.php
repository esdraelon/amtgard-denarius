<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\AccountEntity;
use Optional\Optional;
use Amtgard\Denarius\Persistence\Record\AccountRecord;

#[RepositoryOf('published_accounts', AccountEntity::class)]
class AccountRepository extends Repository implements EntityRepositoryInterface, AccountRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'published_accounts';
    }

    public static function getEntityClass(): string
    {
        return AccountEntity::class;
    }

    public function forKingdom(int $kingdomId): array
    {
        $this->clear();
        $this->kingdom_id = $kingdomId;
        $this->orderBy('id', OrderBy::ASC);

        return $this->collected();
    }

    public function save(AccountRecord $account): AccountRecord
    {
        $entity = $this->entity($account);
        $this->fill($entity, $account);
        return Optional::ofNullable($this->record($this->persist($entity)))
            ->orElseThrow(new \RuntimeException('Account was not saved.'));
    }

    private function entity(AccountRecord $account): AccountEntity
    {
        $entity = $account->getId() === null ? $this->newRepositoryEntity() : $this->fetch($account->getId());
        if (!$entity instanceof AccountEntity) {
            throw new \RuntimeException('Account entity was not created.');
        }

        return $entity;
    }

    private function fill(AccountEntity $entity, AccountRecord $account): void
    {
        $entity->setKingdomId($account->getKingdomId());
        $entity->setTellerAccountId($account->getTellerAccountId());
        $entity->setName($account->getName());
        $entity->setAccountType($account->getType());
        $entity->setLastFour($account->getLastFour());
        $entity->setPublished($account->getPublished() ? 1 : 0);
    }

    private function record(mixed $entity): ?AccountRecord
    {
        if (!$entity instanceof AccountEntity) {
            return null;
        }

        return AccountRecord::builder()
            ->id($entity->getId())
            ->kingdomId((int) $entity->getKingdomId())
            ->tellerAccountId((string) $entity->getTellerAccountId())
            ->name((string) $entity->getName())
            ->type((string) $entity->getAccountType())
            ->lastFour($entity->getLastFour())
            ->published(((int) $entity->getPublished()) === 1)
            ->build();
    }

    /**
     * @return list<AccountRecord>
     */
    private function collected(): array
    {
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
    }
}
