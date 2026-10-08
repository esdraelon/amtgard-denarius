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
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
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
use Amtgard\Denarius\Worker\Job\Impl\TransactionRecategorizeJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;

final class Strategies
{
    public static function orkKingdoms(
        KingdomRepositoryInterface $kingdoms,
        PrincipalRepositoryInterface $principals,
    ): OrkKingdomDirectory {
        return new OrkKingdomDirectory(dirname(__DIR__, 2), null, $kingdoms, $principals);
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
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new AdminGrantTargetResolver(
            new IdpUserDirectory(
                \Amtgard\IdpClient\Config\IdpClientEnvironmentFactory::fromEnvVars([
                    'IDP_BASE_URL' => 'https://idp.example.test',
                    'IDP_CLIENT_ID' => 'denarius_test',
                    'IDP_CLIENT_SECRET' => 'secret',
                    'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
                ]),
                new \GuzzleHttp\Client(),
                $psr17,
            ),
            $principals,
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

    public static function months(?KeyValueStore $store = null): MonthInvalidator
    {
        return new MonthInvalidator($store ?? new ArrayStore());
    }

    public static function jobs(
        TransactionSynchronizer $synchronizer,
        ?TransactionRecategorizer $recategorizer = null,
    ): RefreshJobRegistry {
        $recategorizer ??= self::recategorizer(
            new MemoryKingdoms(),
            new MemoryTransactions(),
            self::providers(self::teller()),
        );

        return new RefreshJobRegistry([
            new LedgerRefreshJob($synchronizer),
            new TransactionRecategorizeJob($recategorizer),
        ]);
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
            self::recategorizer($kingdoms, $transactions, self::providers(self::teller()), $rules),
        );
    }

    public static function patternPrefill(): KingdomPatternPrefill
    {
        return new KingdomPatternPrefill(new DescriptionNormalizer());
    }

    public static function kingdomSettings(KingdomRepositoryInterface $kingdoms): KingdomSettings
    {
        return new KingdomSettings($kingdoms, new PublicationSettingsValidator());
    }

    public static function reviewQueue(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?\DateTimeImmutable $now = null,
    ): TransactionReviewQueue {
        $catalog = \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog();

        return new TransactionReviewQueue(
            new KingdomPublicationLineSource($transactions, $accounts),
            $catalog,
            $now ?? new \DateTimeImmutable('2026-10-01'),
        );
    }

    public static function categorySearch(): \Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategorySearch
    {
        return new \Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategorySearch(
            \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog(),
        );
    }

    public static function reviewService(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MonthInvalidator $months = null,
        ?\DateTimeImmutable $now = null,
    ): TransactionReviewService {
        $catalog = \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog();

        return new TransactionReviewService(
            $transactions,
            $accounts,
            $months ?? self::months(),
            new \Amtgard\Denarius\Domain\Taxonomy\ReviewCategoryValidator($catalog),
            $catalog,
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
            self::publicationApplier($transactions, $now),
            new \Amtgard\Denarius\Domain\Statement\Publication\Ingest\MicroDepositPairReconciler($transactions),
        );
    }
}
