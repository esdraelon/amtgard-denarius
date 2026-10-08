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
use Amtgard\Denarius\Service\Ledger\KingdomPatternService;
use Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternPrefill;
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
        private readonly KingdomPatternService $patterns,
        private readonly KingdomPatternPrefill $patternPrefill,
        private readonly ManagerLedgerSyncFeedback $ledgerSyncFeedback,
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
            $params = $request->getQueryParams();
            $uncategorizedOnly = ($params['uncategorized'] ?? '') === '1';

            return $this->page($response, $kingdom, $this->connects->idle(), trim((string) ($params['review_month'] ?? '')), $uncategorizedOnly);
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

            return $this->manageRedirect($response, $kingdom, '');
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

            return $this->manageRedirect($response, $kingdom, '');
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

            return $this->manageRedirect($response, $kingdom, '');
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

            return $this->manageRedirect($response, $kingdom, '');
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

    public function patterns(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }

            return $this->html->html($response, 'manage-patterns.twig', [
                'csrf' => CsrfToken::issue(),
                'kingdom' => $kingdom->view(),
                'patterns' => $this->patterns->listViews($kingdom),
            ]);
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
            $prefill = $this->patternPrefill->fromReviewQuery(
                (string) ($params['counterparty'] ?? ''),
                (string) ($params['description'] ?? ''),
                (string) ($params['category'] ?? ''),
            );

            return $this->html->html($response, 'pattern-form.twig', [
                'csrf' => CsrfToken::issue(),
                'kingdom' => $kingdom->view(),
                'prefill' => $prefill,
                'ruleId' => null,
                'formAction' => '/manage/' . $kingdom->getSlug() . '/patterns',
            ]);
        });
    }

    public function patternCreate(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return $this->patternPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
            $this->patterns->saveNew($kingdom, $body);
        });
    }

    public function patternUpdate(ServerRequestInterface $request, ResponseInterface $response, string $slug, string $ruleId): ResponseInterface
    {
        return $this->patternPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body) use ($ruleId): void {
            $this->patterns->update($kingdom, (int) $ruleId, $body);
        });
    }

    public function patternDelete(ServerRequestInterface $request, ResponseInterface $response, string $slug, string $ruleId): ResponseInterface
    {
        return $this->patternPost($request, $response, $slug, function (KingdomRecord $kingdom) use ($ruleId): void {
            $this->patterns->delete($kingdom, (int) $ruleId);
        });
    }

    public function patternBulk(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return $this->patternPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
            $rows = $body['patterns'] ?? [];
            if (! is_array($rows)) {
                return;
            }
            $this->patterns->bulkSave($kingdom, $rows);
        });
    }

    /**
     * @param callable(KingdomRecord, array<string, mixed>): void $action
     */
    private function patternPost(
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
            if (! CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }
            try {
                CurrentActor::set((string) $this->auth->get()->profile->id);
                $action($kingdom, $body);
            } catch (\InvalidArgumentException $exception) {
                return $this->html->html($response, 'message.twig', ['title' => 'Cannot save pattern', 'message' => $exception->getMessage()], 400);
            }

            return $response->withHeader('Location', '/manage/' . $kingdom->getSlug() . '/patterns')->withStatus(302);
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

            return $this->manageRedirect($response, $kingdom, trim((string) ($body['review_month'] ?? '')));
        });
    }

    /**
     * @param array<string, mixed> $connect
     */
    private function page(
        ResponseInterface $response,
        KingdomRecord $kingdom,
        array $connect,
        string $requestedMonth = '',
        bool $uncategorizedOnly = false,
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use ($response, $kingdom, $connect, $requestedMonth, $uncategorizedOnly): ResponseInterface {
            $reviewMonth = $this->reviewQueue->reviewMonth($kingdom, $requestedMonth);
            $reviewQueue = $this->reviewQueue->rowsForManage($kingdom, $reviewMonth, $uncategorizedOnly);

            return $this->html->html($response, 'manage.twig', [
                'csrf' => CsrfToken::issue(),
                'kingdom' => $kingdom->view(),
                'accounts' => $this->accountViews((int) $kingdom->getId()),
                'connect' => $connect,
                'reviewQueue' => $reviewQueue,
                'reviewMonth' => $reviewMonth->key(),
                'reviewPrevious' => $reviewMonth->previous()->key(),
                'reviewNext' => $reviewMonth->next()->key(),
                'uncategorizedOnly' => $uncategorizedOnly,
                'ledgerSync' => $this->ledgerSyncFeedback->forManage($kingdom, $reviewQueue !== []),
            ]);
        });
    }

    private function manageRedirect(ResponseInterface $response, KingdomRecord $kingdom, string $reviewMonth): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $kingdom, $reviewMonth): ResponseInterface {
            $url = '/manage/' . $kingdom->getSlug();
            if ($reviewMonth !== '') {
                $url .= '?review_month=' . rawurlencode($reviewMonth);
            }

            return $response->withHeader('Location', $url)->withStatus(302);
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
