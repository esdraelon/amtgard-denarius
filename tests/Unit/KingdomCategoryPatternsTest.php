<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KeywordRuleMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KingdomRuleMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\TransactionCategorizer;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternPrefill;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternValidator;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\RegexPatternGuard;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategoryMatcherChain;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\FallbackMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\ManagerLockMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\ProviderHintMatcher;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Ledger\KingdomPatternService;
use Amtgard\Denarius\Service\Ledger\LedgerProviderIdResolver;
use Amtgard\Denarius\Service\Ledger\TransactionRecategorizer;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Tests\Support\MemoryKingdomCategoryRules;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class KingdomCategoryPatternsTest extends AmtgardTestCase
{
    public function testKingdomRuleOverridesSharedKeyword(): void
    {
        $catalog = CategorizationArrange::bundledCatalog();
        $rules = new MemoryKingdomCategoryRules();
        $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId(1)
            ->category('expense.storage')
            ->matchType('token')
            ->token('COSTCO')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords),
                $keywords,
                new FallbackMatcher(),
            ]),
        );
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->amountCents(-5000)
            ->description('COSTCO WHOLESALE')
            ->build();
        $decision = $categorizer->decide('teller', $incoming, null, 1);
        $this->assertSame('expense.storage', $decision->category);
        $this->assertSame(CategorySource::KingdomRule, $decision->source);
    }

    public function testRecategorizeAfterPatternSaveUpdatesNonManagerRows(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(2)->name('K')->slug('k')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-costco')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-5000)
            ->description('COSTCO WHOLESALE')
            ->status('posted')
            ->category('expense.feast_groceries')
            ->categorySource(CategorySource::SharedRule->value)
            ->categoryConfidence(75)
            ->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords),
                $keywords,
                new FallbackMatcher(),
            ]),
        );
        $recategorizer = new TransactionRecategorizer(
            $kingdoms,
            $transactions,
            $categorizer,
            new LedgerProviderIdResolver(Strategies::providers(Strategies::teller())),
            $catalog,
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            $recategorizer,
        );
        $patterns->saveNew($kingdom, [
            'category' => 'expense.storage',
            'match_type' => 'token',
            'token' => 'COSTCO',
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        $row = $transactions->findByTellerTransactionId('txn-costco');
        $this->assertNotNull($row);
        $this->assertSame('expense.storage', $row->getCategory());
        $this->assertSame(CategorySource::KingdomRule->value, $row->getCategorySource());
    }

    public function testDeletePatternStopsMatchingOnRecategorize(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(3)->name('K')->slug('k2')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $saved = $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId($kingdomId)
            ->category('expense.storage')
            ->matchType('token')
            ->token('JOES STORAGE')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-joe')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-5000)
            ->description('JOES STORAGE UNIT')
            ->status('posted')
            ->category('expense.storage')
            ->categorySource(CategorySource::KingdomRule->value)
            ->categoryRuleId($saved->publicRuleId())
            ->categoryConfidence(100)
            ->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            CategorizationArrange::matcherChain($catalog, new KingdomRuleMatcher($rules, $keywords)),
        );
        $recategorizer = new TransactionRecategorizer(
            $kingdoms,
            $transactions,
            $categorizer,
            new LedgerProviderIdResolver(Strategies::providers(Strategies::teller())),
            $catalog,
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            $recategorizer,
        );
        $patterns->delete($kingdom, (int) $saved->getId());
        $row = $transactions->findByTellerTransactionId('txn-joe');
        $this->assertNotNull($row);
        $this->assertSame('uncategorized', $row->getCategory());
    }

    public function testPatternLogsUseRuleIdOnly(): void
    {
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(5)->name('K')->slug('k5')->provider('teller')->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $recategorizer = new TransactionRecategorizer(
            $kingdoms,
            new MemoryTransactions(),
            CategorizationArrange::categorizer(),
            new LedgerProviderIdResolver(Strategies::providers(Strategies::teller())),
            $catalog,
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            $recategorizer,
        );
        $patterns->saveNew($kingdom, [
            'category' => 'expense.storage',
            'match_type' => 'token',
            'token' => 'SECRET VENDOR NAME',
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'kingdom_pattern_saved',
            KingdomPatternService::class . '::saveNew',
        );
        MethodLogAssert::assertBranchContextExcludes(
            'kingdom_pattern_saved',
            KingdomPatternService::class . '::saveNew',
            'token',
            'description',
            'counterparty',
            'pattern',
        );
        $saved = $rules->forKingdom((int) $kingdom->getId())[0];
        MethodLogAssert::reset();
        $patterns->delete($kingdom, (int) $saved->getId());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'kingdom_pattern_deleted',
            KingdomPatternService::class . '::delete',
        );
        MethodLogAssert::assertBranchContextExcludes(
            'kingdom_pattern_deleted',
            KingdomPatternService::class . '::delete',
            'token',
            'description',
            'counterparty',
        );
    }

    public function testPatternPrefillUsesNormalizedCounterparty(): void
    {
        $prefill = new KingdomPatternPrefill(new DescriptionNormalizer());
        $result = $prefill->fromReviewQuery('Shop', 'POS DEBIT 1234', 'expense.site_rental');
        $this->assertSame('SHOP', $result['token']);
        $this->assertSame('expense.site_rental', $result['category']);
        $fromDescription = $prefill->fromReviewQuery('', 'POS DEBIT LONG VENDOR NAME', 'expense.storage');
        $this->assertNotSame('', $fromDescription['token']);
    }

    public function testSaveNewAcceptsAnyOfMatch(): void
    {
        $kingdoms = new MemoryKingdoms();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(8)->name('K')->slug('k8')->provider('teller')->build());
        $patterns = Strategies::kingdomPatternService($kingdoms, new MemoryTransactions(), $rules);
        $patterns->saveNew($kingdom, [
            'category' => 'expense.storage',
            'match_type' => 'anyOf',
            'any_of' => 'LOCKER, UNIT',
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        $this->assertCount(1, $rules->forKingdom((int) $kingdom->getId()));
    }

    public function testUpdateRejectsMissingPattern(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(7)->name('K')->slug('k7')->provider('teller')->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $patterns = new KingdomPatternService(
            new MemoryKingdomCategoryRules(),
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            Strategies::recategorizer($kingdoms, new MemoryTransactions(), Strategies::providers(Strategies::teller())),
        );
        $this->expectException(\InvalidArgumentException::class);
        $patterns->update($kingdom, 404, ['category' => 'expense.storage', 'match_type' => 'token', 'token' => 'X']);
    }

    public function testAnyOfKingdomRuleMatches(): void
    {
        $catalog = CategorizationArrange::bundledCatalog();
        $rules = new MemoryKingdomCategoryRules();
        $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId(3)
            ->category('expense.site_rental')
            ->matchType('anyOf')
            ->anyOfTokens(['CAMPGROUND', 'KOA'])
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = CategorizationArrange::categorizer($catalog, $rules);
        $incoming = TransactionRecord::builder()->kingdomId(3)->amountCents(-100)->description('KOA HOLIDAY')->build();
        $decision = $categorizer->decide('teller', $incoming, null, 3);
        $this->assertSame('expense.site_rental', $decision->category);
    }

    public function testRegexKingdomRuleMatches(): void
    {
        $catalog = CategorizationArrange::bundledCatalog();
        $rules = new MemoryKingdomCategoryRules();
        $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId(2)
            ->category('expense.storage')
            ->matchType('regex')
            ->regexPattern('\\bUNIT\\b')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords),
                $keywords,
                new FallbackMatcher(),
            ]),
        );
        $incoming = TransactionRecord::builder()->kingdomId(2)->amountCents(-100)->description('STORAGE UNIT RENT')->build();
        $decision = $categorizer->decide('teller', $incoming, null, 2);
        $this->assertSame('expense.storage', $decision->category);
    }

    public function testBulkSaveSkipsUnknownRuleRows(): void
    {
        $kingdoms = new MemoryKingdoms();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(6)->name('K')->slug('k6')->provider('teller')->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            Strategies::recategorizer($kingdoms, new MemoryTransactions(), Strategies::providers(Strategies::teller()), $rules),
        );
        $patterns->bulkSave($kingdom, [99 => ['category' => 'expense.storage', 'match_type' => 'token', 'token' => 'X']]);
        $this->assertSame([], $rules->forKingdom((int) $kingdom->getId()));
    }

    public function testGuestCannotOpenPatterns(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->id(1)->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $queue = new MemoryRefresh();
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['manage-patterns.twig' => 'patterns'])));
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $manager = new ManagerController(
            new SessionAuthStore('empty'),
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            $twig,
            new BankConnect(Strategies::providers(Strategies::teller())),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
            Strategies::categorySearch(),
            Strategies::kingdomPatternService($kingdoms, $transactions),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
        );
        $response = $manager->patterns(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains/patterns'),
            new Response(),
            'golden-plains',
        );
        $this->assertSame(302, $response->getStatusCode());
    }
}
