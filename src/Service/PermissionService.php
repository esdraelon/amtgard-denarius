<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Auth\BootstrapAdmins;
use Amtgard\Denarius\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Queue\KeyValueStore;
use Amtgard\Denarius\Auth\PolicyGateway;

final class PermissionService
{
    public function __construct(
        private readonly PolicyGateway $policies,
        private readonly KeyValueStore $cache,
        private readonly DenariusAuthorizer $authorizer,
        private readonly BootstrapAdmins $bootstrap,
        private readonly int $ttlSeconds = 60,
    ) {
    }

    /**
     * @return list<string>
     */
    public function orns(string $idpUserId): array
    {
        $key = $this->key($idpUserId);
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return array_values(array_map(static fn ($orn): string => (string) $orn, $decoded));
            }
        }

        $orns = $this->policies->listOrns($idpUserId);
        $this->cache->set($key, json_encode($orns, JSON_THROW_ON_ERROR), $this->ttlSeconds);

        return $orns;
    }

    public function forget(string $idpUserId): void
    {
        $this->cache->delete($this->key($idpUserId));
    }

    public function isAdmin(string $idpUserId): bool
    {
        return $this->authorizer->isAdmin($idpUserId, $this->orns($idpUserId), $this->bootstrap);
    }

    /**
     * @return list<int>
     */
    public function managedKingdomIds(string $idpUserId): array
    {
        return $this->authorizer->managedKingdomIds($this->orns($idpUserId));
    }

    private function key(string $idpUserId): string
    {
        return 'denarius:claims:' . $idpUserId;
    }
}
