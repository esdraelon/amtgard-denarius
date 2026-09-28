<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

interface PolicyGateway
{
    public function ensureFormat(): void;

    /**
     * @return list<string>
     */
    public function listOrns(string $idpUserId): array;

    public function grant(string $idpUserId, string $orn): void;

    public function revoke(string $idpUserId, string $orn): void;
}
