<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Service\RoleAdmin;

final class RevokeAdminCommand implements AdminCommand
{
    public function name(): string
    {
        return 'revoke-admin';
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
        $roles->revokeAdmin($this->target($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function target(array $body): string
    {
        return trim((string) ($body['idp_user_id'] ?? ''));
    }
}
