<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin\Impl;

use Amtgard\Denarius\Service\Admin\AdminCommand;
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class IgnoredAdminCommand implements AdminCommand
{
    public function name(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return '';
        });
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            return null;
        });
    }
}
