<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

require_once __DIR__ . '/ApplicationTest.php';

use Amtgard\Denarius\Controller\ManagePagePresenter;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ManagePagePresenterTest extends TestCase
{
    private const MANAGE_TWIG = '{{ categorySearchUrl }}|{{ manageTab }}|{{ reviewMonth }}|{{ reviewPrevious }}|{{ reviewNext }}|{{ patterns|length }}|{{ uncategorizedOnly ? "1" : "0" }}';

    public function testCategorySearchUrlForSlug(): void
    {
        $this->assertSame(
            '/manage/golden-plains/taxonomy/categories',
            ManagePagePresenter::categorySearchUrlForSlug('golden-plains'),
        );
    }

    public function testResolveManageTabDefaultsAndRejectsUnknown(): void
    {
        $presenter = $this->presenter(new MemoryKingdoms(), new MemoryAccounts(), new MemoryTransactions());

        $this->assertSame('review', $presenter->resolveManageTab([]));
        $this->assertSame('review', $presenter->resolveManageTab(['tab' => '']));
        $this->assertSame('settings', $presenter->resolveManageTab(['tab' => 'settings']));
        $this->assertSame('patterns', $presenter->resolveManageTab(['tab' => 'patterns']));
        $this->assertSame('review', $presenter->resolveManageTab(['tab' => 'unknown']));
    }

    public function testRenderManagePageIncludesCategorySearchUrlAndReviewMonth(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->id(1)->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->categoryId(CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $presenter = $this->presenter($kingdoms, $accounts, $transactions);

        $response = $presenter->renderManagePage(new Response(), $kingdom, ['provider' => 'idle'], '', false, 'review');
        $body = (string) $response->getBody();

        $this->assertStringContainsString('/manage/golden-plains/taxonomy/categories', $body);
        $this->assertStringStartsWith('/manage/golden-plains/taxonomy/categories|review|', $body);
        $this->assertStringContainsString('|0|0', $body);
    }

    public function testRenderManagePagePatternsTabLoadsPatternList(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->id(1)->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $patterns = Strategies::kingdomPatternService($kingdoms, $transactions);
        $patterns->saveNew($kingdom, [
            'category' => 'expense.event_supplies',
            'match_type' => 'token',
            'token' => 'COSTCO',
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['manage.twig' => self::MANAGE_TWIG])));
        $presenter = new ManagePagePresenter(
            $twig,
            $accounts,
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts),
            $patterns,
            Strategies::ledgerSyncFeedback(),
        );

        $response = $presenter->renderManagePage(new Response(), $kingdom, ['provider' => 'idle'], '', false, 'patterns');
        $body = (string) $response->getBody();

        $this->assertMatchesRegularExpression('/\|patterns\|[^|]+\|[^|]+\|[^|]+\|[1-9]\d*\|0/', $body);
    }

    public function testManageRedirectBuildsReviewQuery(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->slug('golden-plains')->build());
        $presenter = $this->presenter($kingdoms, new MemoryAccounts(), new MemoryTransactions());

        $response = $presenter->manageRedirect(new Response(), $kingdom, '2026-09', 'review', true);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('tab=review', $location);
        $this->assertStringContainsString('review_month=2026-09', $location);
        $this->assertStringContainsString('uncategorized=1', $location);
    }

    public function testManageRedirectPatternsTabOmitsReviewMonth(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->slug('golden-plains')->build());
        $presenter = $this->presenter($kingdoms, new MemoryAccounts(), new MemoryTransactions());

        $location = $presenter->manageRedirect(new Response(), $kingdom, '2026-09', 'patterns', true)->getHeaderLine('Location');

        $this->assertStringContainsString('tab=patterns', $location);
        $this->assertStringNotContainsString('review_month', $location);
        $this->assertStringNotContainsString('uncategorized', $location);
    }

    private function presenter(MemoryKingdoms $kingdoms, MemoryAccounts $accounts, MemoryTransactions $transactions): ManagePagePresenter
    {
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['manage.twig' => self::MANAGE_TWIG])));

        return Strategies::managePagePresenter($twig, $kingdoms, $accounts, $transactions);
    }
}
