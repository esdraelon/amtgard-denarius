<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\PrincipalEntity;
use Optional\Optional;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;

#[RepositoryOf('principals', PrincipalEntity::class)]
class PrincipalRepository extends Repository implements EntityRepositoryInterface, PrincipalRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'principals';
    }

    public static function getEntityClass(): string
    {
        return PrincipalEntity::class;
    }

    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord
    {
        return $this->record($this->fetchBy('idp_user_id', $idpUserId));
    }

    public function save(PrincipalRecord $principal): PrincipalRecord
    {
        $entity = $this->entity($principal);
        $entity->setIdpUserId($principal->getIdpUserId());
        $entity->setEmail($principal->getEmail());
        $entity->setOrkKingdomId($principal->getOrkKingdomId());
        $entity->setOrkKingdomName($principal->getOrkKingdomName());
        $entity->setUpdatedAt((new \DateTimeImmutable('now'))->format('c'));
        return Optional::ofNullable($this->record($this->persist($entity)))
            ->orElseThrow(new \RuntimeException('Principal was not saved.'));
    }

    public function searchByEmail(string $term): array
    {
        $this->clear();
        $this->getTable()->like('email', '%' . $term . '%');
        $this->orderBy('email', OrderBy::ASC);
        $this->limit(0, 20);

        return $this->collected();
    }

    private function entity(PrincipalRecord $principal): PrincipalEntity
    {
        $entity = $principal->getId() === null ? $this->newRepositoryEntity() : $this->fetch($principal->getId());
        if (!$entity instanceof PrincipalEntity) {
            throw new \RuntimeException('Principal entity was not created.');
        }

        return $entity;
    }

    private function record(mixed $entity): ?PrincipalRecord
    {
        if (!$entity instanceof PrincipalEntity) {
            return null;
        }

        return PrincipalRecord::builder()
            ->id($entity->getId())
            ->idpUserId((string) $entity->getIdpUserId())
            ->email((string) $entity->getEmail())
            ->orkKingdomId($entity->getOrkKingdomId())
            ->orkKingdomName($entity->getOrkKingdomName())
            ->build();
    }

    /**
     * @return list<PrincipalRecord>
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
