<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access\Policy;

use Amtgard\Denarius\Domain\Access\Policy\Impl\KingdomVisibilityPolicy;
use Amtgard\Denarius\Domain\Access\Policy\Impl\PublicVisibilityPolicy;
use Amtgard\Denarius\Domain\Access\Policy\Impl\RegisteredVisibilityPolicy;
use Amtgard\Denarius\Domain\Access\Visibility;

final class VisibilityPolicyRegistry
{
    /** @var array<string, VisibilityPolicy> */
    private array $policies;

    /**
     * @param list<VisibilityPolicy> $policies
     */
    public function __construct(array $policies)
    {
        $indexed = [];
        foreach ($policies as $policy) {
            $indexed[$policy->visibility()->value] = $policy;
        }
        $this->policies = $indexed;
    }

    public static function standard(): self
    {
        return new self([
            new PublicVisibilityPolicy(),
            new RegisteredVisibilityPolicy(),
            new KingdomVisibilityPolicy(),
        ]);
    }

    public function for(Visibility $visibility): VisibilityPolicy
    {
        return $this->policies[$visibility->value];
    }
}
