<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

enum AccessResult
{
    case Allow;
    case Login;
    case Deny;
}
