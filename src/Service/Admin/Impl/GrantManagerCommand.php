<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin\Impl;

use Amtgard\Denarius\Service\Admin\AdminCommand;
use Amtgard\Denarius\Service\Admin\RoleAdmin;

final class GrantManagerCommand implements AdminCommand
{
    public function name(): string
    {
        return 'grant-manager';
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
        $roles->grantManager($this->target($body), $this->kingdomId($body), $this->kingdomName($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function target(array $body): string
    {
        return trim((string) ($body['idp_user_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function kingdomId(array $body): int
    {
        return (int) ($body['ork_kingdom_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function kingdomName(array $body): string
    {
        return trim((string) ($body['kingdom_name'] ?? ''));
    }
}
