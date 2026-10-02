<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin\Impl;

use Amtgard\Denarius\Service\Admin\AdminCommand;
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RevokeManagerCommand implements AdminCommand
{
    public function name(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'revoke-manager';
        });
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
        DenariusLog::trace(__METHOD__, function () use ($roles, $body): mixed {
            $roles->revokeManager($this->target($body), $this->kingdomId($body));

            return null;
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function target(array $body): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): string {
            return trim((string) ($body['idp_user_id'] ?? ''));
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function kingdomId(array $body): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): int {
            return (int) ($body['ork_kingdom_id'] ?? 0);
        });
    }
}
