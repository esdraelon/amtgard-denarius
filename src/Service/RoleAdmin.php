<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Auth\ClaimOrn;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Auth\PolicyGateway;
use Amtgard\Denarius\Contract\RoleGrantStore;
use Amtgard\Denarius\Domain\KingdomSlug;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Record\RoleGrantRecord;

final class RoleAdmin
{
    public function __construct(
        private readonly PolicyGateway $policies,
        private readonly PermissionService $permissions,
        private readonly KingdomStore $kingdoms,
        private readonly RoleGrantStore $grants,
        private readonly string $actorIdpUserId,
    ) {
    }

    public function grantAdmin(string $targetIdpUserId): void
    {
        $this->change($targetIdpUserId, 'grant', ClaimOrn::admin(), ClaimOrn::ADMIN, null);
    }

    public function revokeAdmin(string $targetIdpUserId): void
    {
        $this->change($targetIdpUserId, 'revoke', ClaimOrn::admin(), ClaimOrn::ADMIN, null);
    }

    public function grantManager(string $targetIdpUserId, int $orkKingdomId, string $name): KingdomRecord
    {
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
    }

    public function revokeManager(string $targetIdpUserId, int $orkKingdomId): void
    {
        $this->change(
            $targetIdpUserId,
            'revoke',
            ClaimOrn::manage($orkKingdomId),
            ClaimOrn::MANAGE,
            $orkKingdomId,
        );
    }

    private function change(
        string $targetIdpUserId,
        string $action,
        string $orn,
        string $resource,
        ?int $orkKingdomId,
    ): void {
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
    }
}
