<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Service\Ledger\KingdomPatternService;
use Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback;
use Amtgard\Denarius\Service\Ledger\PatternAutomaticCategoryReview;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Psr\Http\Message\ResponseInterface;

/** Facade: assemble manage page Twig models. */
final class ManagePagePresenter
{
    public function __construct(
        private readonly TwigHtmlRenderer $html,
        private readonly AccountRepositoryInterface $accounts,
        private readonly TransactionReviewQueue $reviewQueue,
        private readonly PatternAutomaticCategoryReview $patternAutomaticReview,
        private readonly KingdomPatternService $patterns,
        private readonly ManagerLedgerSyncFeedback $ledgerSyncFeedback,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $connect
     */
    public function renderManagePage(
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
            $patternViews = $manageTab === 'patterns' ? $this->patterns->listViews($kingdom) : [];

            return $this->html->html($response, 'manage.twig', [
                'csrf' => CsrfToken::issue(),
                'kingdom' => $kingdom->view(),
                'categorySearchUrl' => '/manage/' . $kingdom->getSlug() . '/taxonomy/categories',
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
                'patterns' => $patternViews,
            ]);
        });
    }

    public function manageRedirect(
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
     * @param array<string, mixed> $params
     */
    public function resolveManageTab(array $params): string
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
     * @return list<array<string, mixed>>
     */
    private function accountViews(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return array_map(static fn ($account) => $account->view(), $this->accounts->forKingdom($kingdomId));
        });
    }
}
