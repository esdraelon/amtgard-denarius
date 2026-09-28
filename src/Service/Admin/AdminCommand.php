<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Service\Admin\RoleAdmin;

interface AdminCommand
{
    public function name(): string;

    /**
     * @param array<string, mixed> $body
     */
    public function execute(RoleAdmin $roles, array $body): void;
}
