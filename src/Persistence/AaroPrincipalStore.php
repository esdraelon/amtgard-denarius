<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\PrincipalStore;
use Amtgard\Denarius\Persistence\Entity\PrincipalEntity;
use Amtgard\Denarius\Persistence\Repository\PrincipalRepository;
use Amtgard\Denarius\Record\PrincipalRecord;

final class AaroPrincipalStore implements PrincipalStore
{
    use StoreSupport;

    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord
    {
        return $this->record($this->principals()->fetchBy('idp_user_id', $idpUserId));
    }

    public function save(PrincipalRecord $principal): PrincipalRecord
    {
        $repo = $this->principals();
        $entity = $principal->getId() === null ? $repo->newRepositoryEntity() : $repo->fetch($principal->getId());
        if (!$entity instanceof PrincipalEntity) {
            throw new \RuntimeException('Principal entity was not created.');
        }
        $entity->setIdpUserId($principal->getIdpUserId());
        $entity->setEmail($principal->getEmail());
        $entity->setOrkKingdomId($principal->getOrkKingdomId());
        $entity->setOrkKingdomName($principal->getOrkKingdomName());
        $entity->setUpdatedAt((new \DateTimeImmutable('now'))->format('c'));
        $saved = $repo->persist($entity);
        $record = $this->record($saved);
        if ($record === null) {
            throw new \RuntimeException('Principal was not saved.');
        }

        return $record;
    }

    public function searchByEmail(string $term): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM principals WHERE email LIKE :email ORDER BY email LIMIT 20');
        $statement->execute(['email' => '%' . $term . '%']);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->record($this->principals()->fetch((int) $row['id']));
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    private function principals(): PrincipalRepository
    {
        return $this->repo(PrincipalRepository::class);
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
}
