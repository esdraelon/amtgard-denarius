<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->matchType('token')
            ->token('COSTCO')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            CategoryCatalogFixture::asInterface(),
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(CategoryCatalogFixture::asInterface()),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords, CategoryCatalogFixture::asInterface()),
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
        $this->assertSame(CategoryCatalogFixture::id('expense.storage'), $decision->categoryId);
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
            ->categorySource(CategorySource::SharedRule->value)
            ->categoryConfidence(75)
            ->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            CategoryCatalogFixture::asInterface(),
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(CategoryCatalogFixture::asInterface()),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords, CategoryCatalogFixture::asInterface()),
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
            CategoryCatalogFixture::asInterface(),
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            CategoryCatalogFixture::asInterface(),
            Strategies::categoryAssigner(),
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
        $this->assertSame(CategoryCatalogFixture::id('expense.storage'), $row->getCategoryId());
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->categorySource(CategorySource::KingdomRule->value)
            ->categoryRuleId($saved->publicRuleId())
            ->categoryConfidence(100)
            ->build());
        $catalog = CategorizationArrange::bundledCatalog();
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            CategoryCatalogFixture::asInterface(),
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            CategorizationArrange::matcherChain($catalog, new KingdomRuleMatcher($rules, $keywords, CategoryCatalogFixture::asInterface())),
        );
        $recategorizer = new TransactionRecategorizer(
            $kingdoms,
            $transactions,
            $categorizer,
            new LedgerProviderIdResolver(Strategies::providers(Strategies::teller())),
            $catalog,
            CategoryCatalogFixture::asInterface(),
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            CategoryCatalogFixture::asInterface(),
            Strategies::categoryAssigner(),
            $recategorizer,
        );
        $patterns->delete($kingdom, (int) $saved->getId());
        $row = $transactions->findByTellerTransactionId('txn-joe');
        $this->assertNotNull($row);
        $this->assertSame(CategoryCatalogFixture::id('uncategorized'), $row->getCategoryId());
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
            CategoryCatalogFixture::asInterface(),
            Strategies::months(),
        );
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            CategoryCatalogFixture::asInterface(),
            Strategies::categoryAssigner(),
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
            CategoryCatalogFixture::asInterface(),
            Strategies::categoryAssigner(),
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.site_rental'))
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
        $this->assertSame(CategoryCatalogFixture::id('expense.site_rental'), $decision->categoryId);
    }

    public function testRegexKingdomRuleMatches(): void
    {
        $catalog = CategorizationArrange::bundledCatalog();
        $rules = new MemoryKingdomCategoryRules();
        $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId(2)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->matchType('regex')
            ->regexPattern('\\bUNIT\\b')
            ->fields(['description'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $keywords = new KeywordRuleMatcher($catalog);
        $categorizer = new TransactionCategorizer(
            $catalog,
            CategoryCatalogFixture::asInterface(),
            new DescriptionNormalizer(),
            CategorizationArrange::amountSignRegistry(),
            new CategoryMatcherChain([
                new ManagerLockMatcher(CategoryCatalogFixture::asInterface()),
                new ProviderHintMatcher($catalog),
                new KingdomRuleMatcher($rules, $keywords, CategoryCatalogFixture::asInterface()),
                $keywords,
                new FallbackMatcher(),
            ]),
        );
        $incoming = TransactionRecord::builder()->kingdomId(2)->amountCents(-100)->description('STORAGE UNIT RENT')->build();
        $decision = $categorizer->decide('teller', $incoming, null, 2);
        $this->assertSame(CategoryCatalogFixture::id('expense.storage'), $decision->categoryId);
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
            CategoryCatalogFixture::asInterface(),
            Strategies::categoryAssigner(),
            Strategies::recategorizer($kingdoms, new MemoryTransactions(), Strategies::providers(Strategies::teller()), $rules),
        );
        $patterns->bulkSave($kingdom, [99 => ['category' => 'expense.storage', 'match_type' => 'token', 'token' => 'X']]);
        $this->assertSame([], $rules->forKingdom((int) $kingdom->getId()));
    }

    public function testPatternPreviewRespectsExpenseVersusIncomeDirection(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(10)->name('K')->slug('k10')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-out')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-5000)
            ->description('USAA FSB TRNSFER')
            ->counterparty('USAA')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-in')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(5000)
            ->description('USAA FSB TRNSFER')
            ->counterparty('USAA')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $expenseBody = [
            'category' => 'expense.bank_fees',
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -5000,
        ];
        $expenseMatches = $wizard->previewMatches($kingdom, $expenseBody);
        $this->assertSame(['txn-out'], array_column($expenseMatches, 'tellerTransactionId'));

        $incomeBody = [
            'category' => 'income.dues',
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => 5000,
        ];
        $incomeMatches = $wizard->previewMatches($kingdom, $incomeBody);
        $this->assertSame(['txn-in'], array_column($incomeMatches, 'tellerTransactionId'));
    }

    public function testPatternPreviewUsesStoredSignForPlaidKingdoms(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(12)->name('K')->slug('k12')->provider('plaid')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-usaa-out')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-5000)
            ->description('USAA FSB TRNSFER')
            ->counterparty('USAA')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $matches = $wizard->previewMatches($kingdom, [
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -5000,
        ]);
        $this->assertSame(['txn-usaa-out'], array_column($matches, 'tellerTransactionId'));
    }

    public function testPatternPreviewMatchesAllMonthsOnPublishedAccounts(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(15)->name('K')->slug('k15')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        foreach (['2026-08-10', '2026-09-04'] as $postedOn) {
            $transactions->upsert(TransactionRecord::builder()
                ->kingdomId($kingdomId)
                ->tellerTransactionId('txn-' . $postedOn)
                ->tellerAccountId('acc')
                ->postedOn($postedOn)
                ->amountCents(-5000)
                ->description('USAA FSB TRNSFER')
                ->counterparty('USAA')
                ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
                ->build());
        }
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $matches = $wizard->previewMatches($kingdom, [
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -5000,
        ]);
        $this->assertCount(2, $matches);
    }

    public function testPatternPreviewIncludesManagerLockedAnchorRow(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(13)->name('K')->slug('k13')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-anchor')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-5000)
            ->description('USAA FSB TRNSFER')
            ->counterparty('USAA')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.bank_fees'))
            ->categorySource(CategorySource::Manager->value)
            ->build());
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $matches = $wizard->previewMatches($kingdom, [
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -5000,
            'pattern_anchor_transaction_id' => 'txn-anchor',
        ]);
        $this->assertSame(['txn-anchor'], array_column($matches, 'tellerTransactionId'));
    }

    public function testPatternPreviewIncludesAlreadyCategorizedNonManagerRows(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(11)->name('K')->slug('k11')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-tagged')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-2500)
            ->description('COSTCO WHOLESALE')
            ->counterparty('COSTCO')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->categorySource(CategorySource::SharedRule->value)
            ->build());
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $matches = $wizard->previewMatches($kingdom, [
            'match_type' => 'token',
            'token' => 'COSTCO',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -2500,
        ]);
        $this->assertSame(['txn-tagged'], array_column($matches, 'tellerTransactionId'));
    }

    public function testPatternReviewWizardCreatesCustomCategoryAndUpdatesRow(): void
    {
        $categories = CategoryCatalogFixture::load();
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(14)->name('K')->slug('k14')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-usaa')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-5000)
            ->description('USAA FSB TRNSFER')
            ->counterparty('USAA')
            ->categoryId(CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $assigner = Strategies::categoryAssigner();
        $patterns = new KingdomPatternService(
            $rules,
            new KingdomPatternValidator(CategorizationArrange::bundledCatalog(), new RegexPatternGuard()),
            CategorizationArrange::bundledCatalog(),
            $categories,
            $assigner,
            Strategies::recategorizer($kingdoms, $transactions, Strategies::providers(Strategies::teller()), $rules),
        );
        $wizard = new \Amtgard\Denarius\Service\Ledger\KingdomPatternReviewWizard(
            $patterns,
            Strategies::reviewService($transactions, $accounts, null, null, $assigner),
            $transactions,
            $accounts,
            new KeywordRuleMatcher(CategorizationArrange::bundledCatalog()),
            new DescriptionNormalizer(),
            Strategies::recategorizer($kingdoms, $transactions, Strategies::providers(Strategies::teller()), $rules),
            $categories,
        );
        $body = [
            'category_display' => 'Expense: Reallocate',
            'match_type' => 'token',
            'token' => 'USAA',
            'fields' => ['description', 'counterparty'],
            'pattern_anchor_amount_cents' => -5000,
            'pattern_anchor_transaction_id' => 'txn-usaa',
        ];
        $wizard->complete($kingdom, $body, ['txn-usaa']);
        $saved = $transactions->findByTellerTransactionId('txn-usaa');
        $savedCategoryId = (int) ($saved?->getCategoryId() ?? 0);
        $this->assertSame('Reallocate', $categories->labelFor($savedCategoryId));
        $this->assertSame(CategorySource::Manager->value, $saved?->getCategorySource());
        $this->assertSame($savedCategoryId, $rules->forKingdom($kingdomId)[0]->getCategoryId());
    }

    public function testPatternReviewWizardAppliesOnlySelectedUncategorizedRows(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(9)->name('K')->slug('k9')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        foreach (['txn-a', 'txn-b'] as $id) {
            $transactions->upsert(TransactionRecord::builder()
                ->kingdomId($kingdomId)
                ->tellerTransactionId($id)
                ->tellerAccountId('acc')
                ->postedOn('2026-09-04')
                ->amountCents(-2500)
                ->description('COSTCO WHOLESALE #' . $id)
                ->counterparty('COSTCO')
                ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
                ->build());
        }
        $wizard = Strategies::patternWizard($kingdoms, $transactions, $accounts, $rules);
        $body = [
            'category' => 'expense.storage',
            'match_type' => 'token',
            'token' => 'COSTCO',
            'fields' => ['description', 'counterparty'],
            'flows' => ['expense'],
        ];
        $matches = $wizard->previewMatches($kingdom, $body);
        $this->assertCount(2, $matches);
        $wizard->complete($kingdom, $body, ['txn-a']);
        $this->assertSame(CategoryCatalogFixture::id('expense.storage'), $transactions->findByTellerTransactionId('txn-a')?->getCategoryId());
        $this->assertSame(CategorySource::Manager->value, $transactions->findByTellerTransactionId('txn-a')?->getCategorySource());
        $this->assertSame(CategoryCatalogFixture::id('uncategorized'), $transactions->findByTellerTransactionId('txn-b')?->getCategoryId());
    }

    public function testPatternAutomaticReviewListsAndAppliesMonthRevisions(): void
    {
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $rules = new MemoryKingdomCategoryRules();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(16)->name('K')->slug('k16')->provider('teller')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerAccountId('acc')
            ->name('Checking')
            ->published(true)
            ->build());
        $rules->save(KingdomCategoryRuleRecord::builder()
            ->kingdomId($kingdomId)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.storage'))
            ->matchType('token')
            ->token('COSTCO')
            ->fields(['description', 'counterparty'])
            ->flows([TransactionFlow::Expense])
            ->confidence(100)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-sep')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-2500)
            ->description('COSTCO WHOLESALE')
            ->counterparty('COSTCO')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('txn-aug')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-04')
            ->amountCents(-2500)
            ->description('COSTCO WHOLESALE')
            ->counterparty('COSTCO')
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $review = Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts, $rules);
        $candidates = $review->candidatesForMonth($kingdom, '2026-09');
        $this->assertSame(['txn-sep'], array_column($candidates, 'tellerTransactionId'));
        $this->assertSame('expense.storage', $candidates[0]['suggestedCategory']);
        $review->applySelected($kingdom, '2026-09', ['txn-sep']);
        $this->assertSame(CategoryCatalogFixture::id('expense.storage'), $transactions->findByTellerTransactionId('txn-sep')?->getCategoryId());
        $this->assertSame(CategorySource::Manager->value, $transactions->findByTellerTransactionId('txn-sep')?->getCategorySource());
        $this->assertSame(CategoryCatalogFixture::id('uncategorized'), $transactions->findByTellerTransactionId('txn-aug')?->getCategoryId());
    }

    public function testGuestCannotOpenPatterns(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->id(1)->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $transactions = new MemoryTransactions();
        $accounts = new MemoryAccounts();
        $queue = new MemoryRefresh();
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['manage.twig' => 'manage'])));
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $manager = new ManagerController(
            new SessionAuthStore('empty'),
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset()),
            $queue,
            $twig,
            new BankConnect(Strategies::providers(Strategies::teller())),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
            Strategies::kingdomScopedCategorySearch(),
            Strategies::kingdomPatternService($kingdoms, $transactions),
            Strategies::patternWizard($kingdoms, $transactions, $accounts),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
            Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts),
            Strategies::managePagePresenter($twig, $kingdoms, $accounts, $transactions),
            Strategies::manageCsrfGuard($twig),
        );
        $response = $manager->patterns(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains/patterns'),
            new Response(),
            'golden-plains',
        );
        $this->assertSame(302, $response->getStatusCode());
    }
}
