<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Domain\Kingdom\KingdomSlug;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RoleAdmin
{
    public function __construct(
        private readonly PolicyGateway $policies,
        private readonly PermissionService $permissions,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly RoleGrantRepositoryInterface $grants,
        private readonly string $actorIdpUserId,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function grantAdmin(string $targetIdpUserId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($targetIdpUserId): mixed {
            $this->change($targetIdpUserId, 'grant', ClaimOrn::admin(), ClaimOrn::ADMIN, null);

            return null;
        });
    }

    public function revokeAdmin(string $targetIdpUserId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($targetIdpUserId): mixed {
            $this->change($targetIdpUserId, 'revoke', ClaimOrn::admin(), ClaimOrn::ADMIN, null);

            return null;
        });
    }

    public function grantManager(string $targetIdpUserId, int $orkKingdomId, string $name): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($targetIdpUserId, $orkKingdomId, $name): KingdomRecord {
            $this->change(
                $targetIdpUserId,
                'grant',
                ClaimOrn::manage($orkKingdomId),
                ClaimOrn::MANAGE,
                $orkKingdomId,
            );

            $existing = $this->kingdoms->findByOrkId($orkKingdomId);
            if ($existing !== null) {
                return $existing;
            }

            $slug = KingdomSlug::fromName($name);
            if ($slug === '' || KingdomSlug::isReserved($slug)) {
                throw new \InvalidArgumentException('Kingdom name does not produce a usable public slug.');
            }

            return $this->kingdoms->save(KingdomRecord::builder()
                ->orkKingdomId($orkKingdomId)
                ->name($name)
                ->slug($slug)
                ->build());
        });
    }

    public function revokeManager(string $targetIdpUserId, int $orkKingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($targetIdpUserId, $orkKingdomId): mixed {
            $this->change(
                $targetIdpUserId,
                'revoke',
                ClaimOrn::manage($orkKingdomId),
                ClaimOrn::MANAGE,
                $orkKingdomId,
            );

            return null;
        });
    }

    private function change(
        string $targetIdpUserId,
        string $action,
        string $orn,
        string $resource,
        ?int $orkKingdomId,
    ): void {
        DenariusLog::trace(__METHOD__, function () use ($targetIdpUserId, $action, $orn, $resource, $orkKingdomId): mixed {
            if ($action === 'grant') {
                $this->policies->grant($targetIdpUserId, $orn);
            } else {
                $this->policies->revoke($targetIdpUserId, $orn);
            }

            $this->permissions->forget($targetIdpUserId);
            $this->grants->append(RoleGrantRecord::builder()
                ->actorIdpUserId($this->actorIdpUserId)
                ->targetIdpUserId($targetIdpUserId)
                ->action($action)
                ->resource($resource)
                ->orkKingdomId($orkKingdomId)
                ->createdAt((new \DateTimeImmutable('now'))->format('c'))
                ->build());

            return null;
        });
    }
}
