<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Auth;

final class BootstrapAdmins
{
    /**
     * @param list<string> $idpUserIds
     */
    public function __construct(
        private readonly array $idpUserIds,
    ) {
    }

    public static function fromEnv(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return new self([]);
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return new self($ids);
    }

    public function contains(string $idpUserId): bool
    {
        return in_array($idpUserId, $this->idpUserIds, true);
    }
}
