<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Persistence\Entity\AccountEntity;
use Amtgard\Denarius\Persistence\Repository\AccountRepository;
use Amtgard\Denarius\Record\AccountRecord;

final class AaroAccountStore implements AccountStore
{
    use StoreSupport;

    public function forKingdom(int $kingdomId): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM published_accounts WHERE kingdom_id = :kingdom_id ORDER BY id');
        $statement->execute(['kingdom_id' => $kingdomId]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->record($this->accounts()->fetch((int) $row['id']));
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    public function save(AccountRecord $account): AccountRecord
    {
        $repo = $this->accounts();
        $entity = $account->getId() === null ? $repo->newRepositoryEntity() : $repo->fetch($account->getId());
        if (!$entity instanceof AccountEntity) {
            throw new \RuntimeException('Account entity was not created.');
        }
        $entity->setKingdomId($account->getKingdomId());
        $entity->setTellerAccountId($account->getTellerAccountId());
        $entity->setName($account->getName());
        $entity->setAccountType($account->getType());
        $entity->setLastFour($account->getLastFour());
        $entity->setPublished($account->getPublished() ? 1 : 0);
        $saved = $repo->persist($entity);
        $record = $this->record($saved);
        if ($record === null) {
            throw new \RuntimeException('Account was not saved.');
        }

        return $record;
    }

    private function accounts(): AccountRepository
    {
        return $this->repo(AccountRepository::class);
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
}
