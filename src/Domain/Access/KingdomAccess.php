<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicyRegistry;

final class KingdomAccess
{
    public function __construct(private readonly VisibilityPolicyRegistry $policies)
    {
    }

    public static function standard(): self
    {
        return new self(VisibilityPolicyRegistry::standard());
    }

    public function decide(Visibility $visibility, ?Viewer $viewer, int $kingdomId): AccessResult
    {
        return $this->policies->for($visibility)->decide($viewer, $kingdomId);
    }
}
