<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Secret\Impl;

use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\SecretEntity;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

#[RepositoryOf('enrollment_secrets', SecretEntity::class)]
class SecretRepository extends Repository implements EntityRepositoryInterface, SecretRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return 'enrollment_secrets';
        });
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return SecretEntity::class;
        });
    }

    public function findCiphertext(int $kingdomId): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): ?string {
            $entity = $this->fetchBy('kingdom_id', $kingdomId);

            return $entity instanceof SecretEntity ? $entity->getCiphertext() : null;
        });
    }

    public function saveCiphertext(int $kingdomId, string $ciphertext): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $ciphertext): mixed {
            $existing = $this->fetchBy('kingdom_id', $kingdomId);
            $entity = $existing instanceof SecretEntity ? $existing : $this->newRepositoryEntity();
            if (!$entity instanceof SecretEntity) {
                throw new \RuntimeException('Secret entity was not created.');
            }
            $entity->setKingdomId($kingdomId);
            $entity->setCiphertext($ciphertext);
            $this->persist($entity);

            return null;
        });
    }
}
