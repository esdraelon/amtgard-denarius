<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Auth\CurrentActor;
use Amtgard\Denarius\Persistence\Repository\AccountRepositoryInterface;
use Amtgard\Denarius\Queue\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\Visibility;
use Amtgard\Denarius\Http\CsrfToken;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Service\BankConnect;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ManagerController
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly PermissionService $permissions,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly AccountRepositoryInterface $accounts,
        private readonly KingdomSettings $settings,
        private readonly EnrollmentService $enrollments,
        private readonly KingdomRefreshQueue $queue,
        private readonly TwigHtmlRenderer $html,
        private readonly BankConnect $connects,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }

        return $this->page($response, $kingdom, $this->connects->blank($this->remembered($kingdom)));
    }

    public function connect(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }
        $body = (array) $request->getParsedBody();
        if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        }

        return $this->page($response, $kingdom, $this->connects->offer($kingdom->getSlug(), $body));
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }
        $body = (array) $request->getParsedBody();
        if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        }
        CurrentActor::set((string) $this->auth->get()->profile->id);
        $this->settings->update(
            $kingdom,
            Visibility::fromStored((string) ($body['visibility'] ?? '')),
            DisplayMode::fromStored((string) ($body['display_mode'] ?? '')),
        );

        return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
    }

    public function enrollment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }
        $body = (array) $request->getParsedBody();
        if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        }
        $payload = json_decode((string) ($body['enrollment'] ?? ''), true);
        if (!is_array($payload)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Invalid enrollment', 'message' => 'Teller did not return an enrollment.'], 400);
        }
        CurrentActor::set((string) $this->auth->get()->profile->id);
        $this->enrollments->connect($kingdom, $payload);

        return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
    }

    public function accounts(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }
        $body = (array) $request->getParsedBody();
        if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        }
        $selected = $body['published'] ?? [];
        $flags = [];
        if (is_array($selected)) {
            foreach ($selected as $id) {
                $flags[(string) $id] = true;
            }
        }
        CurrentActor::set((string) $this->auth->get()->profile->id);
        $this->enrollments->setPublished($kingdom, $flags);

        return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->managed($response, (string) ($args['slug'] ?? ''));
        if ($kingdom instanceof ResponseInterface) {
            return $kingdom;
        }
        $body = (array) $request->getParsedBody();
        if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        }
        $this->queue->publishLedger($kingdom->getOrkKingdomId());

        return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
    }

    /**
     * @param array<string, mixed> $connect
     */
    private function page(ResponseInterface $response, KingdomRecord $kingdom, array $connect): ResponseInterface
    {
        return $this->html->html($response, 'manage.twig', [
            'csrf' => CsrfToken::issue(),
            'kingdom' => $kingdom->view(),
            'accounts' => $this->accountViews((int) $kingdom->getId()),
            'connect' => $connect,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountViews(int $kingdomId): array
    {
        return array_map(static fn ($account) => $account->view(), $this->accounts->forKingdom($kingdomId));
    }

    private function remembered(KingdomRecord $kingdom): string
    {
        return Optional::ofNullable($kingdom->getInstitutionName())->orElse('');
    }

    private function managed(ResponseInterface $response, string $slug): mixed
    {
        $session = $this->auth->get();
        if ($session === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $kingdom = $this->kingdoms->findBySlug($slug);
        if ($kingdom === null) {
            return $this->html->html($response, 'message.twig', ['title' => 'Not found', 'message' => 'That kingdom is not assigned.'], 404);
        }
        $userId = (string) $session->profile->id;
        $allowed = $this->permissions->isAdmin($userId)
            || in_array($kingdom->getOrkKingdomId(), $this->permissions->managedKingdomIds($userId), true);
        if (!$allowed) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'You do not manage this kingdom.'], 403);
        }

        return $kingdom;
    }
}
