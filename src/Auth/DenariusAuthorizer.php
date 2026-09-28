<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Auth;

final class DenariusAuthorizer
{
    /**
     * @param list<string> $orns
     */
    public function isAdmin(string $idpUserId, array $orns, BootstrapAdmins $bootstrap): bool
    {
        if ($bootstrap->contains($idpUserId)) {
            return true;
        }

        foreach ($orns as $orn) {
            $parsed = ClaimOrn::parse($orn);
            if ($parsed !== null && $parsed->resource === ClaimOrn::ADMIN) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $orns
     * @return list<int>
     */
    public function managedKingdomIds(array $orns): array
    {
        $ids = [];
        foreach ($orns as $orn) {
            $parsed = ClaimOrn::parse($orn);
            if ($parsed !== null && $parsed->resource === ClaimOrn::MANAGE && $parsed->kingdomId > 0) {
                $ids[$parsed->kingdomId] = $parsed->kingdomId;
            }
        }

        $values = array_values($ids);
        sort($values);

        return $values;
    }
}
