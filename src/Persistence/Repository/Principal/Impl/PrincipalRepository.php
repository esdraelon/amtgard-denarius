<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Principal\Impl;

use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\PrincipalEntity;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;

#[RepositoryOf('principals', PrincipalEntity::class)]
class PrincipalRepository extends Repository implements EntityRepositoryInterface, PrincipalRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return 'principals';
        });
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return PrincipalEntity::class;
        });
    }

    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): ?PrincipalRecord {
            return $this->record($this->fetchBy('idp_user_id', $idpUserId));
        });
    }

    public function findByEmail(string $email): ?PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($email): ?PrincipalRecord {
            $email = trim($email);
            if ($email === '') {
                return null;
            }

            return $this->record($this->fetchBy('email', $email));
        });
    }

    public function save(PrincipalRecord $principal): PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($principal): PrincipalRecord {
            $entity = $this->entity($principal);
            $entity->setIdpUserId($principal->getIdpUserId());
            $entity->setEmail($principal->getEmail());
            $entity->setOrkKingdomId($principal->getOrkKingdomId());
            $entity->setOrkKingdomName($principal->getOrkKingdomName());
            $entity->setUpdatedAt((new \DateTimeImmutable('now'))->format('c'));
            return Optional::ofNullable($this->record($this->persist($entity)))
                ->orElseThrow(new \RuntimeException('Principal was not saved.'));
        });
    }

    public function searchByEmail(string $term): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($term): array {
            $this->clear();
            $this->getTable()->like('email', '%' . $term . '%');
            $this->orderBy('email', OrderBy::ASC);
            $this->limit(0, 20);

            return $this->collected();
        });
    }

    public function listOrkKingdomHints(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $this->clear();
            $this->orderBy('ork_kingdom_name', OrderBy::ASC);

            /** @var array<int, array{id: int, name: string}> $byId */
            $byId = [];
            foreach ($this->collected() as $principal) {
                $id = $principal->getOrkKingdomId();
                $name = $principal->getOrkKingdomName();
                if ($id === null || $id <= 0 || ! is_string($name) || trim($name) === '') {
                    continue;
                }
                $byId[$id] = ['id' => $id, 'name' => trim($name)];
            }

            return array_values($byId);
        });
    }

    private function entity(PrincipalRecord $principal): PrincipalEntity
    {
        return DenariusLog::trace(__METHOD__, function () use ($principal): PrincipalEntity {
            $entity = $principal->getId() === null ? $this->newRepositoryEntity() : $this->fetch($principal->getId());
            if (!$entity instanceof PrincipalEntity) {
                throw new \RuntimeException('Principal entity was not created.');
            }

            return $entity;
        });
    }

    private function record(mixed $entity): ?PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($entity): ?PrincipalRecord {
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
        });
    }

    /**
     * @return list<PrincipalRecord>
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
