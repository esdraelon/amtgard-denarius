<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class SimpleFinConnectSession
{
    private const KEY = 'simplefin_kingdom_slug';

    public function remember(string $kingdomSlug): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomSlug): void {
            $_SESSION[self::KEY] = trim($kingdomSlug);
        });
    }

    public function pullKingdomSlug(): ?string
    {
        return DenariusLog::trace(__METHOD__, function (): ?string {
            $slug = trim((string) ($_SESSION[self::KEY] ?? ''));
            unset($_SESSION[self::KEY]);
            if ($slug === '') {
                return null;
            }

            return $slug;
        });
    }

    public function peekKingdomSlug(): ?string
    {
        return DenariusLog::trace(__METHOD__, function (): ?string {
            $slug = trim((string) ($_SESSION[self::KEY] ?? ''));

            return Optional::ofNullable($slug === '' ? null : $slug)->orElse(null);
        });
    }
}
