<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicyRegistry;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class KingdomAccess
{
    public function __construct(private readonly VisibilityPolicyRegistry $policies)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function standard(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self(VisibilityPolicyRegistry::standard());
        });
    }

    public function decide(Visibility $visibility, ?Viewer $viewer, int $kingdomId): AccessResult
    {
        return DenariusLog::trace(__METHOD__, function () use ($visibility, $viewer, $kingdomId): AccessResult {
            return $this->policies->for($visibility)->decide($viewer, $kingdomId);
        });
    }
}
