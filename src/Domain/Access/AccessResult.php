<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

enum AccessResult
{
    case Allow;
    case Login;
    case Deny;
}
