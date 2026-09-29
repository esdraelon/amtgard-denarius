<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Auth;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class BootstrapAdmins
{
    /**
     * @param list<string> $idpUserIds
     */
    public function __construct(
        private readonly array $idpUserIds,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function fromEnv(?string $raw): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($raw): self {
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
        });
    }

    public function contains(string $idpUserId): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): bool {
            return in_array($idpUserId, $this->idpUserIds, true);
        });
    }
}
