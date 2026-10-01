<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Principal;

use Amtgard\Denarius\Persistence\Record\PrincipalRecord;

interface PrincipalRepositoryInterface
{
    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord;

    public function findByEmail(string $email): ?PrincipalRecord;

    public function save(PrincipalRecord $principal): PrincipalRecord;

    /**
     * @return list<PrincipalRecord>
     */
    public function searchByEmail(string $term): array;

    /**
     * Distinct ORK kingdom ids seen on synced principals (home kingdom from IDP).
     *
     * @return list<array{id: int, name: string}>
     */
    public function listOrkKingdomHints(): array;
}
