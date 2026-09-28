<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Service\Admin\Impl\IgnoredAdminCommand;

final class AdminCommandRegistry
{
    /** @var array<string, AdminCommand> */
    private array $commands;

    /**
     * @param list<AdminCommand> $commands
     */
    public function __construct(array $commands, private readonly IgnoredAdminCommand $ignored = new IgnoredAdminCommand())
    {
        $indexed = [];
        foreach ($commands as $command) {
            $indexed[$command->name()] = $command;
        }
        $this->commands = $indexed;
    }

    public function find(string $name): AdminCommand
    {
        return $this->commands[$name] ?? $this->ignored;
    }
}
