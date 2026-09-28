<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin\Impl;

use Amtgard\Denarius\Service\Admin\AdminCommand;
use Amtgard\Denarius\Service\Admin\RoleAdmin;

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
