<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Record\PrincipalRecord;

interface PrincipalStore
{
    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord;

    public function save(PrincipalRecord $principal): PrincipalRecord;

    /**
     * @return list<PrincipalRecord>
     */
    public function searchByEmail(string $term): array;
}
