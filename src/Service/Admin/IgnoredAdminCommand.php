<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Service\RoleAdmin;

final class IgnoredAdminCommand implements AdminCommand
{
    public function name(): string
    {
        return '';
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
    }
}
