<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http\Twig;

use Amtgard\Denarius\Service\Access\SiteNavBuilder;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/** Twig globals: per-render site navigation from the current session. */
final class SiteNavTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteNavBuilder $siteNav,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => [
            'siteNav' => $this->siteNav->links(),
        ]);
    }
}
