<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Domain\Access\AccessResult;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Utilities\Http\BuildInfo;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly TwigHtmlRenderer $html,
        private readonly SessionAuthStore $auth,
        private readonly AccountNavBuilder $accountNav,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly KingdomAccess $kingdomAccess,
        private readonly OrkKingdomDirectory $orkKingdoms,
        private readonly string $root,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response): ResponseInterface {
            $session = $this->auth->get();
            $accountActions = $session === null
                ? []
                : $this->accountNav->actionsFor((string) $session->profile->id);

            $viewer = $this->viewer($session);
            /** @var array<int, \Amtgard\Denarius\Persistence\Record\KingdomRecord> $denariusByOrkId */
            $denariusByOrkId = [];
            foreach ($this->kingdoms->all() as $kingdom) {
                $orkId = $kingdom->getOrkKingdomId();
                if ($orkId > 0) {
                    $denariusByOrkId[$orkId] = $kingdom;
                }
            }

            $kingdomLinks = [];
            foreach ($this->orkKingdoms->list() as $orkKingdom) {
                $name = $orkKingdom['name'];
                $denarius = $denariusByOrkId[$orkKingdom['id']] ?? null;
                $href = null;
                if ($denarius !== null) {
                    $slug = (string) $denarius->getSlug();
                    if ($slug !== '') {
                        $decision = $this->kingdomAccess->decide(
                            Visibility::fromStored($denarius->getVisibility()),
                            $viewer,
                            $denarius->getOrkKingdomId(),
                        );
                        if ($decision === AccessResult::Allow) {
                            $href = '/' . $slug;
                        }
                    }
                }
                $kingdomLinks[] = ['name' => $name, 'href' => $href];
            }
            usort($kingdomLinks, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $this->html->html($response, 'home.twig', [
                'authenticated' => $session !== null,
                'accountActions' => $accountActions,
                'kingdomLinks' => $kingdomLinks,
                'csrf' => CsrfToken::issue(),
                'version' => BuildInfo::version($this->root),
            ]);
        });
    }

    public function version(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response): ResponseInterface {
            return JsonBody::write($response, ['version' => BuildInfo::version($this->root)]);
        });
    }

    public function privacyPolicy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response): ResponseInterface {
            return $this->html->html($response, 'privacy-policy.twig', [
                'effectiveDate' => 'September 30, 2026',
                'contactEmail' => 'privacy@amtgard.com',
            ]);
        });
    }

    private function viewer(?AuthenticatedSession $session): ?Viewer
    {
        return DenariusLog::trace(__METHOD__, function () use ($session): ?Viewer {
            if ($session === null) {
                return null;
            }

            return new Viewer((string) $session->profile->id, $session->profile->orkProfile?->kingdomId);
        });
    }
}
