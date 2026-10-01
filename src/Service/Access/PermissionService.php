<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Access;

use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\IdpClient\Exception\ClientIamException;

final class PermissionService
{
    public function __construct(
        private readonly PolicyGateway $policies,
        private readonly KeyValueStore $cache,
        private readonly DenariusAuthorizer $authorizer,
        private readonly BootstrapAdmins $bootstrap,
        private readonly int $ttlSeconds = 60,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<string>
     */
    public function orns(string $idpUserId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): array {
            $key = $this->key($idpUserId);
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return array_values(array_map(static fn ($orn): string => (string) $orn, $decoded));
                }
            }

            try {
                $orns = $this->policies->listOrns($idpUserId);
            } catch (ClientIamException $exception) {
                DenariusLog::warnBranch('client_iam_unavailable', __METHOD__, [
                    'idp_user_id' => $idpUserId,
                    'error_code' => $exception->errorCode()->value,
                ]);
                $orns = [];
            }
            $this->cache->set($key, json_encode($orns, JSON_THROW_ON_ERROR), $this->ttlSeconds);

            return $orns;
        });
    }

    public function forget(string $idpUserId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($idpUserId): mixed {
            $this->cache->delete($this->key($idpUserId));

            return null;
        });
    }

    public function isAdmin(string $idpUserId): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): bool {
            if ($this->bootstrap->contains($idpUserId)) {
                return true;
            }

            return $this->authorizer->isAdmin($idpUserId, $this->orns($idpUserId), $this->bootstrap);
        });
    }

    /**
     * @return list<int>
     */
    public function managedKingdomIds(string $idpUserId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): array {
            return $this->authorizer->managedKingdomIds($this->orns($idpUserId));
        });
    }

    private function key(string $idpUserId): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): string {
            return 'denarius:claims:' . $idpUserId;
        });
    }
}
