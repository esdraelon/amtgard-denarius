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
use Amtgard\Denarius\Domain\Taxonomy\KingdomScopedCategorySearch;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Service\Ledger\KingdomPatternReviewWizard;
use Amtgard\Denarius\Service\Ledger\KingdomPatternService;
use Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback;
use Amtgard\Denarius\Service\Ledger\PatternAutomaticCategoryReview;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternPrefill;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Utilities\Http\ReviewSelectionParser;
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
        private readonly KingdomScopedCategorySearch $categorySearch,
        private readonly KingdomPatternService $patterns,
        private readonly KingdomPatternReviewWizard $patternWizard,
        private readonly KingdomPatternPrefill $patternPrefill,
        private readonly ManagerLedgerSyncFeedback $ledgerSyncFeedback,
        private readonly PatternAutomaticCategoryReview $patternAutomaticReview,
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
            $connect = $kingdom->getEnrollmentStatus() === 'connected' ? $this->connects->linked() : $this->connects->idle();

            return $this->page(
                $response,
                $kingdom,
                $connect,
                trim((string) ($params['review_month'] ?? '')),
                $uncategorizedOnly,
                $this->resolveManageTab($params),
            );
        });
    }

    public function connectGet(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $slug): ResponseInterface {
            return $response->withHeader('Location', '/manage/' . $slug . '?tab=settings')->withStatus(302);
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

            return $this->page($response, $kingdom, $connect, '', false, 'settings');
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

            return $this->manageRedirect($response, $kingdom, '', 'settings');
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

            return $this->manageRedirect($response, $kingdom, '', 'settings');
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

            return $this->manageRedirect($response, $kingdom, '', 'settings');
        });
    }

    public function disconnectBank(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
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
            $this->enrollments->disconnectBank($kingdom);

            return $this->manageRedirect($response, $kingdom, '', 'settings');
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

            return $this->manageRedirect($response, $kingdom, '', 'review');
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
            return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): int {
                return $this->reviewActions->updateFromReviewBody($kingdom, $body);
            }, true);
        });
    }

    public function updateTransactionReview(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
                $this->reviewActions->applyPublicationSelections($kingdom, ...ReviewSelectionParser::fromBody($body));
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
            $flowRaw = trim((string) ($request->getQueryParams()['flow'] ?? ''));
            if ($flowRaw === '') {
                return JsonBody::write($response, ['results' => []]);
            }
            $flow = TransactionFlow::fromStored($flowRaw);
            if ($flow === null) {
                return JsonBody::write($response, ['results' => []]);
            }
            $results = $this->categorySearch->search($kingdom, $query, $flow);

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

            $connect = $kingdom->getEnrollmentStatus() === 'connected' ? $this->connects->linked() : $this->connects->idle();

            return $this->page($response, $kingdom, $connect, '', false, 'patterns');
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
            $prefill = $this->patterns->enrichFormPrefill($kingdom, $this->patternPrefill->fromReviewQuery(
                (string) ($params['counterparty'] ?? ''),
                (string) ($params['description'] ?? ''),
                (string) ($params['category'] ?? ''),
            ));

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
            if (($body['return_to'] ?? '') === 'review') {
                $this->patternWizard->complete(
                    $kingdom,
                    $body,
                    $this->patternApplyIds($body),
                );

                return;
            }
            $this->patterns->saveNew($kingdom, $body);
        });
    }

    public function applyPatternAutomaticCategories(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return $this->reviewPost($request, $response, $slug, function (KingdomRecord $kingdom, array $body): void {
            $this->patternAutomaticReview->applySelected(
                $kingdom,
                trim((string) ($body['review_month'] ?? '')),
                $this->patternApplyIds($body),
            );
        });
    }

    public function patternPreview(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $body = (array) $request->getParsedBody();
            if (! CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return JsonBody::write($response, ['error' => 'Forbidden'], 403);
            }
            try {
                $matches = $this->patternWizard->previewMatches($kingdom, $body);
            } catch (\InvalidArgumentException $exception) {
                return JsonBody::write($response, ['error' => $exception->getMessage()], 400);
            }

            return JsonBody::write($response, ['matches' => $matches]);
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

            if (($body['return_to'] ?? '') === 'review') {
                return $this->manageRedirect($response, $kingdom, trim((string) ($body['review_month'] ?? '')));
            }

            return $this->manageRedirect($response, $kingdom, '', 'patterns');
        });
    }

    /**
     * @param callable(KingdomRecord, array<string, mixed>): mixed $action
     */
    private function reviewPost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slug,
        callable $action,
        bool $jsonOnSuccess = false,
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response, $slug, $action, $jsonOnSuccess): ResponseInterface {
            $kingdom = $this->managed($response, $slug);
            if ($kingdom instanceof ResponseInterface) {
                return $kingdom;
            }
            $body = (array) $request->getParsedBody();
            $wantsJson = $jsonOnSuccess && $this->acceptsJson($request);
            if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                if ($wantsJson) {
                    return JsonBody::write($response, ['error' => 'The form token did not match.'], 403);
                }

                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }
            $result = null;
            try {
                CurrentActor::set((string) $this->auth->get()->profile->id);
                $result = $action($kingdom, $body);
            } catch (\InvalidArgumentException $exception) {
                if ($wantsJson) {
                    return JsonBody::write($response, ['error' => $exception->getMessage()], 400);
                }

                return $this->html->html($response, 'message.twig', ['title' => 'Cannot update', 'message' => $exception->getMessage()], 400);
            }

            if ($wantsJson) {
                return JsonBody::write($response, [
                    'ok' => true,
                    'categoryId' => is_int($result) ? $result : (int) ($body['category_id'] ?? 0),
                ]);
            }

            return $this->manageRedirect($response, $kingdom, trim((string) ($body['review_month'] ?? '')));
        });
    }

    private function acceptsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
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
        string $manageTab = 'review',
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use (
            $response,
            $kingdom,
            $connect,
            $requestedMonth,
            $uncategorizedOnly,
            $manageTab,
        ): ResponseInterface {
            [$reviewMonth, $reviewQueue] = $this->reviewQueue->manageReview($kingdom, $requestedMonth, $uncategorizedOnly);
            $patternAutomaticCandidates = $this->patternAutomaticReview->candidatesForMonth(
                $kingdom,
                $reviewMonth->key(),
            );
            $patterns = $manageTab === 'patterns' ? $this->patterns->listViews($kingdom) : [];

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
                'patternAutomaticCandidates' => $patternAutomaticCandidates,
                'manageTab' => $manageTab,
                'patterns' => $patterns,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveManageTab(array $params): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($params): string {
            $tab = trim((string) ($params['tab'] ?? ''));
            if ($tab === '') {
                return 'review';
            }
            if (! in_array($tab, ['settings', 'review', 'patterns'], true)) {
                return 'review';
            }

            return $tab;
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function patternApplyIds(array $body): array
    {
        $raw = $body['apply_transaction_ids'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function manageRedirect(
        ResponseInterface $response,
        KingdomRecord $kingdom,
        string $reviewMonth = '',
        string $tab = 'review',
        bool $uncategorizedOnly = false,
    ): ResponseInterface {
        return DenariusLog::trace(__METHOD__, function () use (
            $response,
            $kingdom,
            $reviewMonth,
            $tab,
            $uncategorizedOnly,
        ): ResponseInterface {
            $query = ['tab' => $tab];
            if ($tab === 'review') {
                if ($reviewMonth !== '') {
                    $query['review_month'] = $reviewMonth;
                }
                if ($uncategorizedOnly) {
                    $query['uncategorized'] = '1';
                }
            }
            $url = '/manage/' . $kingdom->getSlug() . '?' . http_build_query($query);

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
