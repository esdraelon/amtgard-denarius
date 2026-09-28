<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\AccessResult;
use Amtgard\Denarius\Domain\CategoryTotal;
use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\KingdomAccess;
use Amtgard\Denarius\Domain\LedgerLine;
use Amtgard\Denarius\Domain\MonthWindow;
use Amtgard\Denarius\Domain\Viewer;
use Amtgard\Denarius\Domain\Visibility;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class KingdomPageController
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly MonthReader $pages,
        private readonly KingdomAccess $access,
        private readonly SessionAuthStore $auth,
        private readonly TwigHtmlRenderer $html,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kingdom = $this->kingdoms->findBySlug((string) ($args['slug'] ?? ''));
        if ($kingdom === null) {
            return $this->html->html($response, 'message.twig', ['title' => 'Not found', 'message' => 'That kingdom is not published.'], 404);
        }

        $viewer = $this->viewer();
        $decision = $this->access->decide(
            Visibility::fromStored($kingdom->getVisibility()),
            $viewer,
            $kingdom->getOrkKingdomId(),
        );
        if ($decision === AccessResult::Login) {
            $path = $request->getUri()->getPath();
            return $response->withHeader('Location', '/login?return_to=' . rawurlencode($path))->withStatus(302);
        }
        if ($decision === AccessResult::Deny) {
            return $this->html->html($response, 'message.twig', ['title' => 'Restricted', 'message' => 'This kingdom statement is limited to members of that kingdom.'], 403);
        }

        $month = MonthWindow::fromQuery($request->getQueryParams()['month'] ?? null, new \DateTimeImmutable('now'));
        $statement = $this->pages->statement($kingdom, $month);

        return $this->html->html($response, 'kingdom.twig', [
            'kingdom' => $kingdom->view(),
            'month' => $month->key(),
            'previous' => $month->previous()->key(),
            'next' => $month->next()->key(),
            'mode' => DisplayMode::fromStored($kingdom->getDisplayMode())->value,
            'rows' => $this->rows($statement->rows),
            'disconnected' => $kingdom->getEnrollmentStatus() === 'disconnected',
            'syncedAt' => $kingdom->getLastSyncedAt(),
        ]);
    }

    /**
     * @param list<LedgerLine|CategoryTotal> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        $presented = [];
        foreach ($rows as $row) {
            $presented[] = $row instanceof CategoryTotal ? $this->total($row) : $this->line($row);
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(LedgerLine $row): array
    {
        return [
            'kind' => 'line',
            'postedOn' => $row->getPostedOn(),
            'category' => $row->getCategory(),
            'description' => $row->getDescription(),
            'counterparty' => $row->getCounterparty(),
            'amount' => \Amtgard\Denarius\Domain\Money::format($row->getAmountCents()),
            'account' => $row->getAccountName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function total(CategoryTotal $row): array
    {
        return [
            'kind' => 'total',
            'category' => $row->category,
            'count' => $row->count,
            'amount' => \Amtgard\Denarius\Domain\Money::format($row->amountCents),
        ];
    }

    private function viewer(): ?Viewer
    {
        $session = $this->auth->get();
        if ($session === null) {
            return null;
        }

        return new Viewer((string) $session->profile->id, $session->profile->orkProfile?->kingdomId);
    }
}
