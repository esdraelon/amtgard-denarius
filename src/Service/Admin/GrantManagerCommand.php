<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Ork\OrkKingdom;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\RoleAdmin;

final class GrantManagerCommand implements AdminCommand
{
    public function __construct(private readonly CachedKingdomDirectory $directory)
    {
    }

    public function name(): string
    {
        return 'grant-manager';
    }

    public function execute(RoleAdmin $roles, array $body): void
    {
        $roles->grantManager($this->target($body), $this->kingdom($body));
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
    private function kingdom(array $body): OrkKingdom
    {
        $orkId = (int) ($body['ork_kingdom_id'] ?? 0);
        $name = trim((string) ($body['kingdom_name'] ?? ''));
        foreach ($this->directory->list() as $kingdom) {
            if ($kingdom->id === $orkId) {
                return new OrkKingdom($orkId, $kingdom->name);
            }
        }

        return new OrkKingdom($orkId, $name);
    }
}
