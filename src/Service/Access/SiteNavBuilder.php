<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Access;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\Session\SessionAuthStore;

/** Builds primary site navigation links for Twig (home, privacy, roles, sign in/out). */
final class SiteNavBuilder
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly AccountNavBuilder $accountNav,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{label: string, href: string, primary?: bool}>
     */
    public function links(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $links = [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Privacy', 'href' => '/privacy-policy'],
            ];
            $session = $this->auth->get();
            if ($session === null) {
                $links[] = ['label' => 'Sign in', 'href' => '/login', 'primary' => true];

                return $links;
            }

            foreach ($this->accountNav->actionsFor((string) $session->profile->id) as $action) {
                $links[] = $action;
            }
            $links[] = ['label' => 'Log out', 'href' => '/logout'];

            return $links;
        });
    }
}
