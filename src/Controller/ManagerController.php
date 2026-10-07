<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategorySearch;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\IdpClient\Session\SessionAuthStore;
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
        private readonly SimpleFinConnectSession $simplefinSession,
        private readonly TransactionReviewQueue $reviewQueue,
        private readonly TransactionReviewService $reviewActions,
        private readonly TaxonomyCategorySearch $categorySearch,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $uncategorizedOnly = ($request->getQueryParams()['uncategorized'] ?? '') === '1';

            return $this->page($response, $kingdom, $this->connects->idle(), $uncategorizedOnly);
        });
    }

    public function connectGet(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $slug): ResponseInterface {
            return $response->withHeader('Location', '/manage/' . $slug)->withStatus(302);
        });
    }

    public function connect(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $body = (array) $request->getParsedBody();
            if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }

            $connect = $this->connects->launch($kingdom->getSlug(), $body);
            if (($connect['provider'] ?? '') === 'simplefin') {
                $this->simplefinSession->remember($kingdom->getSlug());
            }

            return $this->page($response, $kingdom, $connect);
        });
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
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
                (int) ($body['embargo_days'] ?? 3),
            );

            return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
        });
    }

    public function enrollment(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
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
        });
    }

    public function accounts(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
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
        });
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $body = (array) $request->getParsedBody();
            if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }
            $this->queue->publishLedger($kingdom->getOrkKingdomId());

            return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
        });
    }

    public function publishTransaction(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
                $this->reviewActions->publish($kingdom, (string) ($body['teller_transaction_id'] ?? ''));
            });
        });
    }

    public function withholdTransaction(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
                $this->reviewActions->withhold($kingdom, (string) ($body['teller_transaction_id'] ?? ''));
            });
        });
    }

    public function updateTransaction(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
                $this->reviewActions->update(
                    $kingdom,
                    (string) ($body['teller_transaction_id'] ?? ''),
                    (string) ($body['category'] ?? ''),
                    ($body['publish'] ?? '') === '1',
                    ($body['bulk_counterparty'] ?? '') === '1',
                );
            });
        });
    }

    public function categorySearch(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $query = (string) ($request->getQueryParams()['q'] ?? '');
            $flowRaw = (string) ($request->getQueryParams()['flow'] ?? '');
            $flow = $flowRaw !== '' ? TransactionFlow::fromStored($flowRaw) : null;
            $results = $this->categorySearch->search($query, $flow);

            return JsonBody::write($response, ['results' => $results]);
        });
    }

    public function patternNew(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $params = $request->getQueryParams();

            $prefill = [
                'counterparty' => (string) ($params['counterparty'] ?? ''),
                'description' => (string) ($params['description'] ?? ''),
                'category' => (string) ($params['category'] ?? ''),
            ];

            return $this->html->html($response, 'message.twig', [
                'title' => 'Create pattern (preview)',
                'message' => sprintf(
                    'Pattern editor stub for %s. Counterparty: %s. Description: %s. Category: %s.',
                    $kingdom->getName(),
                    $prefill['counterparty'],
                    $prefill['description'],
                    $prefill['category'],
                ),
            ]);
        });
    }

    /**
     * @param callable(KingdomRecord, array<string, mixed>): void $action
     */
    private function reviewPost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slug,
        callable $action,
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug, $action): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $body = (array) $request->getParsedBody();
            if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }
            try {
                CurrentActor::set((string) $this->auth->get()->profile->id);
                $action($kingdom, $body);
            } catch (\InvalidArgumentException $exception) {
                return $this->html->html($response, 'message.twig', ['title' => 'Cannot update', 'message' => $exception->getMessage()], 400);
            }

            return $response->withHeader('Location', '/manage/' . $kingdom->getSlug())->withStatus(302);
        });
    }

    /**
     * @param array<string, mixed> $connect
     */
    /**
     * @param array<string, mixed> $connect
     */
    private function page(
        ResponseInterface $response,
        KingdomRecord $kingdom,
        array $connect,
        bool $uncategorizedOnly = false,
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use ($response, $kingdom, $connect, $uncategorizedOnly): ResponseInterface {
            return $this->html->html($response, 'manage.twig', [
                'csrf' => CsrfToken::issue(),
                'kingdom' => $kingdom->view(),
                'accounts' => $this->accountViews((int) $kingdom->getId()),
                'connect' => $connect,
                'reviewQueue' => $this->reviewQueue->rowsForManage($kingdom, $uncategorizedOnly),
                'uncategorizedOnly' => $uncategorizedOnly,
            ]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountViews(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return array_map(static fn ($account) => $account->view(), $this->accounts->forKingdom($kingdomId));
        });
    }

    private function managed(ResponseInterface $response, string $slug): mixed
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $slug): mixed {
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
        });
    }
}
