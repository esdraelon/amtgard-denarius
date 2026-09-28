<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Persistence\Entity\SecretEntity;
use Amtgard\Denarius\Persistence\Repository\SecretRepository;

final class AaroSecretStore implements SecretStore
{
    use StoreSupport;

    public function findCiphertext(int $kingdomId): ?string
    {
        $entity = $this->secrets()->fetchBy('kingdom_id', $kingdomId);

        return $entity instanceof SecretEntity ? $entity->getCiphertext() : null;
    }

    public function saveCiphertext(int $kingdomId, string $ciphertext): void
    {
        $repo = $this->secrets();
        $existing = $repo->fetchBy('kingdom_id', $kingdomId);
        $entity = $existing instanceof SecretEntity ? $existing : $repo->newRepositoryEntity();
        if (!$entity instanceof SecretEntity) {
            throw new \RuntimeException('Secret entity was not created.');
        }
        $entity->setKingdomId($kingdomId);
        $entity->setCiphertext($ciphertext);
        $repo->persist($entity);
    }

    private function secrets(): SecretRepository
    {
        return $this->repo(SecretRepository::class);
    }
}
