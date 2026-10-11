<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\DisconnectLedgerNotice;
use Amtgard\Denarius\Domain\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\RefreshLedgerNotice;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Service\Month\MonthCacheRefreshPublisher;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Tests\Support\StubOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Service\Admin\Impl\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeManagerCommand;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KeywordRuleMatcher;
use Amtgard\Denarius\Service\Ledger\KingdomPatternReviewWizard;
use Amtgard\Denarius\Service\Ledger\LedgerProviderIdResolver;
use Amtgard\Denarius\Service\Ledger\TransactionCategoryApplier;
use Amtgard\Denarius\Service\Ledger\TransactionPublicationApplier;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternPrefill;
use Amtgard\Denarius\Domain\Taxonomy\KingdomPatternValidator;
use Amtgard\Denarius\Domain\Taxonomy\RegexPatternGuard;
use Amtgard\Denarius\Service\Ledger\KingdomPatternService;
use Amtgard\Denarius\Service\Ledger\TransactionRecategorizer;
use Amtgard\Denarius\Tests\Support\MemoryKingdomCategoryRules;
use Amtgard\Denarius\Worker\Job\Impl\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\Impl\MonthCacheRefreshJob;
use Amtgard\Denarius\Worker\Job\Impl\TransactionRecategorizeJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;

final class Strategies
{
    public static function orkKingdoms(
        KingdomRepositoryInterface $kingdoms,
        PrincipalRepositoryInterface $principals,
        ?OrkGetKingdomsGateway $orkApi = null,
    ): OrkKingdomDirectory {
        return new OrkKingdomDirectory(
            dirname(__DIR__, 2),
            null,
            $kingdoms,
            $principals,
            $orkApi ?? new StubOrkGetKingdomsGateway(),
            new OrkKingdomCacheWriter(),
        );
    }

    public static function ledgerSyncFeedback(): \Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback
    {
        return new \Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback();
    }

    public static function managedKingdomResolver(
        KingdomRepositoryInterface $kingdoms,
        PrincipalRepositoryInterface $principals,
        ?OrkGetKingdomsGateway $orkApi = null,
    ): \Amtgard\Denarius\Service\Kingdom\ManagedKingdomResolver {
        return new \Amtgard\Denarius\Service\Kingdom\ManagedKingdomResolver(
            $kingdoms,
            self::orkKingdoms($kingdoms, $principals, $orkApi),
        );
    }

    public static function grantedRoles(
        RoleGrantRepositoryInterface $grants,
        PrincipalRepositoryInterface $principals,
        KingdomRepositoryInterface $kingdoms,
    ): AdminGrantedRoleIndex {
        return new AdminGrantedRoleIndex(
            $grants,
            $principals,
            self::orkKingdoms($kingdoms, $principals),
        );
    }

    public static function grantTargets(PrincipalRepositoryInterface $principals): AdminGrantTargetResolver
    {
        return new AdminGrantTargetResolver(
            self::idpUserDirectory(),
            $principals,
            new PrincipalSync($principals),
        );
    }

    public static function idpUserDirectory(): IdpUserDirectory
    {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $client = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $query = (string) $request->getUri()->getQuery();
                /** @var array<string, string> $known */
                $known = [
                    'person@example.com' => '9',
                    'legacy@example.com' => '31786326',
                    'megiddo@esdraelon.com' => 'idp-uuid-megiddo',
                    'no-one@example.com' => '404-user',
                ];
                foreach ($known as $email => $idpUserId) {
                    if (! str_contains($query, 'email=')) {
                        continue;
                    }
                    if (str_contains($query, rawurlencode($email)) || str_contains($query, 'email=' . $email)) {
                        return new \Nyholm\Psr7\Response(200, [], json_encode([
                            'idp_user_id' => $idpUserId,
                            'email' => $email,
                        ], JSON_THROW_ON_ERROR));
                    }
                }

                return new \Nyholm\Psr7\Response(404, [], '{"error":"unknown email"}');
            }
        };

        return new IdpUserDirectory(
            \Amtgard\IdpClient\Config\IdpClientEnvironmentFactory::fromEnvVars([
                'IDP_BASE_URL' => 'https://idp.example.test',
                'IDP_CLIENT_ID' => 'denarius_test',
                'IDP_CLIENT_SECRET' => 'secret',
                'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
            ]),
            $client,
            $psr17,
        );
    }

    public static function principalSuggester(PrincipalRepositoryInterface $principals): \Amtgard\Denarius\Service\Admin\AdminPrincipalSuggester
    {
        return new \Amtgard\Denarius\Service\Admin\AdminPrincipalSuggester(
            $principals,
            self::idpUserDirectory(),
            new PrincipalSync($principals),
        );
    }

    public static function admin(): AdminCommandRegistry
    {
        return new AdminCommandRegistry([
            new GrantAdminCommand(),
            new RevokeAdminCommand(),
            new GrantManagerCommand(),
            new RevokeManagerCommand(),
        ]);
    }

    public static function events(KingdomRefreshQueue $queue, EnrollmentService $enrollments): LedgerNoticeRegistry
    {
        return new LedgerNoticeRegistry([
            new RefreshLedgerNotice($queue),
            new DisconnectLedgerNotice($enrollments),
        ]);
    }

    public static function providers(LedgerProvider $provider): LedgerProviderRegistry
    {
        return new LedgerProviderRegistry([$provider]);
    }

    public static function teller(?TellerApi $api = null, ?TellerWebhookVerifier $verifier = null): LedgerProvider
    {
        return new TellerLedgerProvider(
            $api ?? new FakeTeller(),
            $verifier ?? new TellerWebhookVerifier('whsec', 300),
            TellerLedgerProvider::actions(),
            new AlwaysReady(),
            'app_test',
            'sandbox',
        );
    }

    public static function bankReset(
        ?MemoryTransactions $transactions = null,
        ?MemoryAccounts $accounts = null,
        ?MemorySecrets $secrets = null,
    ): \Amtgard\Denarius\Tests\Support\MemoryKingdomBankReset {
        return new \Amtgard\Denarius\Tests\Support\MemoryKingdomBankReset(
            $transactions ?? new MemoryTransactions(),
            $accounts ?? new MemoryAccounts(),
            $secrets ?? new MemorySecrets(),
        );
    }

    public static function months(
        ?KeyValueStore $store = null,
        ?MonthCacheRefreshPublisher $publisher = null,
        ?TransactionRepositoryInterface $transactions = null,
    ): MonthInvalidator {
        $publisher ??= new MonthCacheRefreshPublisher(new MemoryMessages(), $transactions ?? new MemoryTransactions());

        return new MonthInvalidator(
            new MonthCacheWriter($store ?? new ArrayStore(), CategorizationArrange::bundledCatalog()),
            $publisher,
        );
    }

    public static function jobs(
        TransactionSynchronizer $synchronizer,
        ?TransactionRecategorizer $recategorizer = null,
        ?MonthCacheRefreshJob $monthCache = null,
    ): RefreshJobRegistry {
        $recategorizer ??= self::recategorizer(
            new MemoryKingdoms(),
            new MemoryTransactions(),
            self::providers(self::teller()),
        );

        $jobs = [
            new LedgerRefreshJob($synchronizer),
            new TransactionRecategorizeJob($recategorizer),
        ];
        if ($monthCache !== null) {
            $jobs[] = $monthCache;
        }

        return new RefreshJobRegistry($jobs);
    }

    public static function recategorizer(
        KingdomRepositoryInterface $kingdoms,
        TransactionRepositoryInterface $transactions,
        LedgerProviderRegistry $providers,
        ?MemoryKingdomCategoryRules $kingdomRules = null,
    ): TransactionRecategorizer {
        return new TransactionRecategorizer(
            $kingdoms,
            $transactions,
            CategorizationArrange::categorizer(null, $kingdomRules),
            new LedgerProviderIdResolver($providers),
            CategorizationArrange::bundledCatalog(),
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
            self::months(),
        );
    }

    public static function kingdomPatternService(
        KingdomRepositoryInterface $kingdoms,
        TransactionRepositoryInterface $transactions,
        ?MemoryKingdomCategoryRules $rules = null,
    ): KingdomPatternService {
        $rules ??= new MemoryKingdomCategoryRules();
        $catalog = CategorizationArrange::bundledCatalog();

        return new KingdomPatternService(
            $rules,
            new KingdomPatternValidator($catalog, new RegexPatternGuard()),
            $catalog,
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
            self::categoryAssigner(),
            self::recategorizer($kingdoms, $transactions, self::providers(self::teller()), $rules),
        );
    }

    public static function categoryAssigner(): \Amtgard\Denarius\Service\Ledger\KingdomCategoryAssigner
    {
        $catalog = CategorizationArrange::bundledCatalog();

        return new \Amtgard\Denarius\Service\Ledger\KingdomCategoryAssigner(
            $catalog,
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
        );
    }

    public static function patternAutomaticReview(
        KingdomRepositoryInterface $kingdoms,
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MemoryKingdomCategoryRules $rules = null,
    ): \Amtgard\Denarius\Service\Ledger\PatternAutomaticCategoryReview {
        $rules ??= new MemoryKingdomCategoryRules();
        $assigner = self::categoryAssigner();
        $catalog = CategorizationArrange::bundledCatalog();
        $keywords = new \Amtgard\Denarius\Domain\Taxonomy\Categorization\KeywordRuleMatcher($catalog);
        $categories = \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface();

        return new \Amtgard\Denarius\Service\Ledger\PatternAutomaticCategoryReview(
            $transactions,
            $accounts,
            new \Amtgard\Denarius\Domain\Taxonomy\Categorization\KingdomRuleMatcher($rules, $keywords, $categories),
            new \Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer(),
            $categories,
            $assigner,
            self::reviewService($transactions, $accounts, null, null, $assigner),
            self::months(),
        );
    }

    public static function patternWizard(
        KingdomRepositoryInterface $kingdoms,
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MemoryKingdomCategoryRules $rules = null,
    ): KingdomPatternReviewWizard {
        $rules ??= new MemoryKingdomCategoryRules();
        $providers = self::providers(self::teller());
        $catalog = CategorizationArrange::bundledCatalog();

        return new KingdomPatternReviewWizard(
            self::kingdomPatternService($kingdoms, $transactions, $rules),
            self::reviewService($transactions, $accounts),
            $transactions,
            $accounts,
            new KeywordRuleMatcher($catalog),
            new DescriptionNormalizer(),
            self::recategorizer($kingdoms, $transactions, $providers, $rules),
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
        );
    }

    public static function patternPrefill(): KingdomPatternPrefill
    {
        return new KingdomPatternPrefill(new DescriptionNormalizer());
    }

    public static function kingdomSettings(
        KingdomRepositoryInterface $kingdoms,
        ?MonthInvalidator $months = null,
    ): KingdomSettings {
        return new KingdomSettings($kingdoms, new PublicationSettingsValidator(), $months ?? self::months());
    }

    public static function reviewQueue(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?\DateTimeImmutable $now = null,
    ): TransactionReviewQueue {
        return new TransactionReviewQueue(
            new KingdomPublicationLineSource($transactions, $accounts),
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
            self::categoryAssigner(),
            self::patternPrefill(),
            $now ?? new \DateTimeImmutable('2026-10-01'),
        );
    }

    public static function kingdomScopedCategorySearch(): \Amtgard\Denarius\Domain\Taxonomy\KingdomScopedCategorySearch
    {
        return new \Amtgard\Denarius\Domain\Taxonomy\KingdomScopedCategorySearch(
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
        );
    }

    public static function reviewService(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MonthInvalidator $months = null,
        ?\DateTimeImmutable $now = null,
        ?\Amtgard\Denarius\Service\Ledger\KingdomCategoryAssigner $assigner = null,
    ): TransactionReviewService {
        $assigner ??= self::categoryAssigner();
        $categories = \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface();

        return new TransactionReviewService(
            $transactions,
            $accounts,
            $months ?? self::months(),
            new \Amtgard\Denarius\Domain\Taxonomy\ReviewCategoryValidator($categories),
            $assigner,
            $categories,
            \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog(),
            new PublicationEmbargoCalculator(),
            $now ?? new \DateTimeImmutable('2026-10-01'),
        );
    }

    public static function publicationApplier(
        TransactionRepositoryInterface $transactions,
        ?\DateTimeImmutable $now = null,
    ): TransactionPublicationApplier {
        return new TransactionPublicationApplier(
            $transactions,
            new PublicationEmbargoCalculator(),
            new \Amtgard\Denarius\Domain\Statement\Publication\Ingest\TransactionHardRedactAnnotator(
                new \Amtgard\Denarius\Domain\Statement\Publication\Ingest\VerificationKeywordHardMatcher(),
            ),
            $now ?? new \DateTimeImmutable('2026-09-01'),
        );
    }

    public static function categoryApplier(LedgerProviderRegistry $providers): TransactionCategoryApplier
    {
        return new TransactionCategoryApplier(
            CategorizationArrange::categorizer(),
            new LedgerProviderIdResolver($providers),
        );
    }

    public static function synchronizer(
        KingdomRepositoryInterface $kingdoms,
        AccountRepositoryInterface $accounts,
        SecretRepositoryInterface $secrets,
        TransactionRepositoryInterface $transactions,
        LedgerProviderRegistry $providers,
        TokenCipher $cipher,
        \DateTimeImmutable $now,
        MonthInvalidator $months,
    ): TransactionSynchronizer {
        return new TransactionSynchronizer(
            $kingdoms,
            $accounts,
            $secrets,
            $transactions,
            $providers,
            $cipher,
            $now,
            $months,
            self::categoryApplier($providers),
            \Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::asInterface(),
            self::publicationApplier($transactions, $now),
            new \Amtgard\Denarius\Domain\Statement\Publication\Ingest\MicroDepositPairReconciler($transactions),
        );
    }

    public static function manageCsrfGuard(\Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer $html): \Amtgard\Denarius\Controller\ManageCsrfGuard
    {
        return new \Amtgard\Denarius\Controller\ManageCsrfGuard($html);
    }

    public static function manageKingdomAccess(
        \Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer $html,
        KingdomRepositoryInterface $kingdoms,
        \Amtgard\Denarius\Service\Access\PermissionService $permissions,
        \Amtgard\IdpClient\Session\SessionAuthStore $auth,
    ): \Amtgard\Denarius\Controller\ManageKingdomAccess {
        return new \Amtgard\Denarius\Controller\ManageKingdomAccess($auth, $permissions, $kingdoms, $html);
    }

    public static function managePagePresenter(
        \Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer $html,
        KingdomRepositoryInterface $kingdoms,
        AccountRepositoryInterface $accounts,
        TransactionRepositoryInterface $transactions,
    ): \Amtgard\Denarius\Controller\ManagePagePresenter {
        return new \Amtgard\Denarius\Controller\ManagePagePresenter(
            $html,
            $accounts,
            self::reviewQueue($transactions, $accounts),
            self::patternAutomaticReview($kingdoms, $transactions, $accounts),
            self::kingdomPatternService($kingdoms, $transactions),
            self::ledgerSyncFeedback(),
        );
    }
}
