<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Setup\HostEnv\HostEnvBootstrap;

HostEnvBootstrap::builder()->build()->applyFromProcessEnv();
