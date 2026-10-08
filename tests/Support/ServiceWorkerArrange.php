<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Ledger\DailySweep;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Worker\Job\Impl\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\Impl\MonthCacheRefreshJob;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemoryGrants;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryMessages;
use Amtgard\Denarius\Tests\Unit\MemoryRefresh;
use Amtgard\Denarius\Tests\Unit\MemorySecrets;
use Amtgard\Denarius\Tests\Unit\MemoryTransactions;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Worker\LedgerWorker;

/** Memory fakes and direct calls for service and worker method-log tests. */
final class ServiceWorkerArrange
{
    public static function exerciseAll(): void
    {
        $kingdoms = new MemoryKingdoms();
        $grants = new MemoryGrants();
        $policies = new FakePolicies([]);
        $permissions = new PermissionService($policies, new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $roleAdmin = new RoleAdmin($policies, $permissions, $kingdoms, $grants, '3');
        $roleAdmin->grantAdmin('9');
        $roleAdmin->revokeAdmin('9');
        $saved = $roleAdmin->grantManager('9', 4, 'Golden Plains');
        $roleAdmin->revokeManager('9', 4);

        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('31786326')->email('legacy@example.com')->build());
        $grantTargets = Strategies::grantTargets($principals);
        $legacyPrincipal = $principals->findByEmail('legacy@example.com');
        if ($legacyPrincipal !== null) {
            $grantTargets->viewForAdmin($legacyPrincipal);
        }
        try {
            $grantTargets->resolveIdpUserId([
                'target_email' => 'legacy@example.com',
                'idp_user_id' => '31786326',
            ]);
        } catch (\InvalidArgumentException) {
        }
        AdminGrantTargetResolver::isLegacyPublicId('31786326');

        $principalSuggester = Strategies::principalSuggester($principals);
        $principalSuggester->match('person@example.com');
        $principalSuggester->match('megiddo');
        $principalSuggester->match('');

        $grantedIndex = new AdminGrantedRoleIndex($grants, $principals, Strategies::orkKingdoms($kingdoms, $principals));
        $grantedIndex->search(null, null);
        $grantedIndex->search('legacy', 'Golden');

        $managed = Strategies::managedKingdomResolver($kingdoms, $principals);
        $managed->resolve(4);
        $managed->resolve(404);

        $registry = Strategies::admin();
        $body = ['idp_user_id' => '9', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains'];
        foreach (['grant-admin', 'revoke-admin', 'grant-manager', 'revoke-manager'] as $name) {
            $registry->find($name)->execute($roleAdmin, $body);
        }
        $ignoredCommand = $registry->find('unknown');
        $ignoredCommand->name();
        $ignoredCommand->execute($roleAdmin, $body);

        $settings = Strategies::kingdomSettings($kingdoms);
        $updated = $settings->update($saved, \Amtgard\Denarius\Domain\Access\Visibility::Public, DisplayMode::LessRedacted, 3);

        $secrets = new MemorySecrets();
        $accounts = new MemoryAccounts();
        $queue = new MemoryRefresh();
        $teller = Strategies::teller();
        $cipher = new TokenCipher('app-key');
        $cache = new ArrayStore();
        $enrollment = new EnrollmentService($kingdoms, $secrets, $accounts, Strategies::providers($teller), $cipher, $queue, Strategies::months($cache));
        $connected = $enrollment->connect($updated, [
            'accessToken' => 'token-1',
            'enrollment' => ['id' => 'enr_1', 'institution' => ['name' => 'Bank']],
        ]);
        $enrollment->setPublished($connected, ['acc_1' => true]);
        $enrollment->markDisconnected($connected);
        $kingdoms->save(KingdomRecord::builder()
            ->id($connected->getId())
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->visibility('public')
            ->displayMode('all')
            ->enrollmentId('enr_1')
            ->institutionName('Bank')
            ->enrollmentStatus('connected')
            ->provider('teller')
            ->build());
        $secrets->saveCiphertext((int) $connected->getId(), $cipher->encrypt('token-1'));
        $accounts->save(AccountRecord::builder()->kingdomId((int) $connected->getId())->tellerAccountId('acc_1')->name('Checking')->published(true)->build());

        $transactions = new MemoryTransactions();
        $sync = Strategies::synchronizer($kingdoms, $accounts, $secrets, $transactions, Strategies::providers($teller), $cipher, new \DateTimeImmutable('2026-09-01'), Strategies::months($cache));
        $sync->sync(4);
        $sync->sync(99);
        $brokenSecrets = new MemorySecrets();
        $brokenSecrets->saveCiphertext((int) $connected->getId(), 'bad');
        $brokenSync = Strategies::synchronizer(
            $kingdoms,
            $accounts,
            $brokenSecrets,
            $transactions,
            Strategies::providers($teller),
            $cipher,
            new \DateTimeImmutable('2026-09-01'),
            Strategies::months($cache),
        );
        try {
            (new LedgerRefreshJob($brokenSync))->handle(['orkKingdomId' => 4]);
        } catch (\Throwable) {
        }

        $handler = new ProviderWebhookHandler(
            Strategies::providers(Strategies::teller(verifier: new TellerWebhookVerifier('whsec', 300))),
            $kingdoms,
            Strategies::events($queue, $enrollment),
        );
        $handler->signatureHeader('teller');
        $bodyJson = json_encode(['type' => 'transactions.processed', 'enrollment_id' => 'enr_1'], JSON_THROW_ON_ERROR);
        $now = 1_700_000_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $bodyJson, 'whsec');
        $handler->handle('teller', $bodyJson, $signature, $now);
        $handler->handle('teller', $bodyJson, 't=1,v1=nope', $now);
        $empty = json_encode(['type' => 'transactions.processed'], JSON_THROW_ON_ERROR);
        $emptySig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $empty, 'whsec');
        $handler->handle('teller', $empty, $emptySig, $now);
        $disconnect = json_encode(['type' => 'enrollment.disconnected', 'payload' => ['enrollment_id' => 'enr_1']]);
        $disconnectSig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $disconnect, 'whsec');
        $handler->handle('teller', (string) $disconnect, $disconnectSig, $now);
        $unknown = json_encode(['type' => 'transactions.processed', 'enrollment_id' => 'missing']);
        $unknownSig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $unknown, 'whsec');
        $handler->handle('teller', (string) $unknown, $unknownSig, $now);

        $page = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $page->statement($kingdoms->findByOrkId(4), new MonthWindow(2026, 9));
        KingdomPageQueryFactory::managerReview($transactions, $accounts)
            ->statement($kingdoms->findByOrkId(4), new MonthWindow(2026, 9));

        $reviewKingdom = $kingdoms->findByOrkId(4);
        if ($reviewKingdom !== null) {
            $accounts->save(AccountRecord::builder()->kingdomId((int) $reviewKingdom->getId())->tellerAccountId('acc_review')->name('Review')->published(true)->build());
            $transactions->upsert(\Amtgard\Denarius\Persistence\Record\TransactionRecord::builder()
                ->kingdomId((int) $reviewKingdom->getId())
                ->tellerTransactionId('review-tx')
                ->tellerAccountId('acc_review')
                ->postedOn('2026-09-02')
                ->amountCents(-100)
                ->category('expense.feast_groceries')
                ->categorySource('shared_rule')
                ->categoryConfidence(85)
                ->publishableAfter('2026-09-01T00:00:00+00:00')
                ->build());
            $reviews = Strategies::reviewService($transactions, $accounts, Strategies::months($cache), new \DateTimeImmutable('2026-10-01'));
            $reviews->publish($reviewKingdom, 'review-tx');
            $reviews->withhold($reviewKingdom, 'review-tx');
            $reviews->update($reviewKingdom, 'review-tx', 'expense.site_rental', false, false);
            $transactions->upsert(\Amtgard\Denarius\Persistence\Record\TransactionRecord::builder()
                ->kingdomId((int) $reviewKingdom->getId())
                ->tellerTransactionId('hard-review')
                ->tellerAccountId('acc_review')
                ->postedOn('2026-09-03')
                ->amountCents(25)
                ->category('uncategorized')
                ->publicationFlags('{"hard":true,"pattern_ids":["ingest.test_hard"]}')
                ->publishableAfter('2026-09-01T00:00:00+00:00')
                ->build());
            $reviews->publish($reviewKingdom, 'hard-review');
            Strategies::reviewQueue($transactions, $accounts, new \DateTimeImmutable('2026-10-01'))->rowsForManage($reviewKingdom, true);
            Strategies::categorySearch()->search('site', \Amtgard\Denarius\Domain\Taxonomy\TransactionFlow::Expense);
        }

        $connect = new BankConnect(Strategies::providers($teller));
        $connect->blank('  ');
        $connect->offer('golden-plains', ['institution' => 'First Bank', 'skipped' => 'teller', 'current' => 'simplefin', 'skip' => '1']);
        $connect->offer('golden-plains', ['institution' => '', 'skip' => '1', 'current' => 'teller']);
        $connect->offer('golden-plains', ['institution' => 'First Bank', 'skipped' => ['teller', ''], 'current' => 'teller', 'skip' => '1']);
        $connect->idle();
        $connect->launch('golden-plains', ['skip' => '1', 'current' => 'teller']);

        $_ENV['APP_PUBLIC_URL'] = 'http://localhost:37180';
        $_ENV['SIMPLEFIN_APP_ID'] = 'amtgard_denarius_dev';
        $_ENV['SIMPLEFIN_APP_TOKEN'] = 'token';
        \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig::fromEnv()->userCreateUrl();
        $sfSession = new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession();
        $sfSession->remember('golden-plains');
        $sfSession->peekKingdomSlug();
        $sfSession->pullKingdomSlug();
        \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinSetupToken::decode(base64_encode('https://bridge.simplefin.org/simplefin/claim/test'));
        \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinSetupToken::forbidden('Forbidden (was it already claimed?)');
        \Amtgard\Denarius\Utilities\Http\AppPublicUrl::base();
        $_ENV['APP_PUBLIC_URL'] = 'http://localhost:37180';
        $sfConfig = new \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig('amtgard_denarius_dev', 'token', 'https://bridge.simplefin.org/simplefin');
        $sfConfig->userCreateUrl();
        $sfConfig->appId();
        $sfConfig->appToken();
        $sfConfig->returnUrl();
        $sfConfig->configured();
        $sfReturn = new \Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment(
            $kingdoms,
            $enrollment,
            new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(),
            $permissions,
        );
        try {
            $sfReturn->complete('9', []);
        } catch (\Amtgard\Denarius\Service\Enrollment\SimpleFinReturnException $exception) {
            $exception->reason();
        }
        $sfProviders = new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry([
            new \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider(
                new class implements \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi {
                    public function claim(string $claimUrl): string
                    {
                        return 'https://user:secret@bridge.simplefin.org/simplefin';
                    }

                    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                    {
                        return [];
                    }
                },
                new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady(),
                new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow(new \DateTimeImmutable('2026-09-28')),
                new \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin'),
            ),
        ]);
        $sfEnrollment = new EnrollmentService($kingdoms, $secrets, $accounts, $sfProviders, $cipher, $queue, Strategies::months($cache));
        $sfSession2 = new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession();
        $sfSession2->remember($saved->getSlug());
        $adminPermissions = new PermissionService(new FakePolicies([\Amtgard\Denarius\Utilities\Auth\ClaimOrn::admin()]), $cache, new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $sfReturn2 = new \Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment($kingdoms, $sfEnrollment, $sfSession2, $adminPermissions);
        $sfReturn2->complete('9', ['setup_token' => base64_encode('https://bridge.simplefin.org/simplefin/claim/worker')]);

        $month = new MonthWindow(2026, 9);
        $kingdomRow = KingdomRecord::builder()->id(4)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('all')->build();
        $origin = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(
                    DisplayMode::LessRedacted,
                    $month,
                    [LedgerLine::builder()->postedOn('2026-09-02')->amountCents(250)->category('office')->description('paper')->counterparty('Shop')->build()],
                );
            }
        };
        $catalog = \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog();
        $reader = new CachingMonthReader($origin, $cache, $catalog);
        $reader->statement($kingdomRow, $month);
        $reader->statement($kingdomRow, $month);
        $cache->set('denarius:month:4:0:all:2026-09:taxonomy/v1', 'not-json', 10);
        $reader->statement($kingdomRow, $month);

        $summaryKingdom = KingdomRecord::builder()->id(5)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('summarized')->build();
        $summaryOrigin = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(
                    DisplayMode::Summarized,
                    $month,
                    [new CategoryTotal('office', 2, 250)],
                );
            }
        };
        $cachedSummary = new CachingMonthReader($summaryOrigin, $cache, $catalog);
        $cachedSummary->statement($summaryKingdom, $month);
        $cachedSummary->statement($summaryKingdom, $month);

        (new MonthInvalidator($cache))->forget(4);

        $sweep = new DailySweep($kingdoms, $queue);
        $sweep->enqueueConnected();

        $categoryApplier = Strategies::categoryApplier(Strategies::providers($teller));
        $existingTxn = $transactions->findByTellerTransactionId('txn_1');
        if ($existingTxn !== null) {
            $resync = TransactionRecord::builder()
                ->kingdomId((int) $saved->getId())
                ->tellerTransactionId('txn_1')
                ->tellerAccountId('acc')
                ->postedOn('2026-09-02')
                ->amountCents(-325)
                ->description($existingTxn->getDescription())
                ->status('posted')
                ->providerCategory('food')
                ->build();
            $categoryApplier->apply($saved, $resync, $existingTxn);
        }

        $recategorizer = Strategies::recategorizer($kingdoms, $transactions, Strategies::providers($teller));
        $recategorizer->recategorizeKingdom($saved);
        $recategorizer->recategorizeKingdomAfterPatternChange($saved);
        $recategorizer->recategorizeAll();
        $patternRules = new \Amtgard\Denarius\Tests\Support\MemoryKingdomCategoryRules();
        $patterns = \Amtgard\Denarius\Tests\Unit\Strategies::kingdomPatternService($kingdoms, $transactions, $patternRules);
        $patterns->listViews($saved);
        $patterns->saveNew($saved, [
            'category' => 'expense.storage',
            'match_type' => 'token',
            'token' => 'PATTERN TOKEN',
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        $stored = $patternRules->forKingdom((int) $saved->getId());
        if ($stored !== []) {
            $ruleId = (int) $stored[0]->getId();
            $patterns->update($saved, $ruleId, [
                'category' => 'expense.storage',
                'match_type' => 'token',
                'token' => 'PATTERN TOKEN TWO',
                'fields' => ['description'],
                'flows' => ['expense'],
            ]);
            $patterns->bulkSave($saved, [
                $ruleId => [
                    'category' => 'expense.storage',
                    'match_type' => 'token',
                    'token' => 'PATTERN TOKEN THREE',
                ],
            ]);
            $patterns->delete($saved, $ruleId);
        }
        (new \Amtgard\Denarius\Worker\Job\Impl\TransactionRecategorizeJob($recategorizer))->handle(['orkKingdomId' => 4]);
        (new \Amtgard\Denarius\Worker\Job\Impl\TransactionRecategorizeJob($recategorizer))->handle([]);
        (new \Amtgard\Denarius\Service\Ledger\LedgerProviderIdResolver(Strategies::providers($teller)))->forKingdom($saved);
        (new \Amtgard\Denarius\Service\Month\MonthCacheKeys())->statement(4, 0, 'all', '2026-09', 'taxonomy/v1');
        (new \Amtgard\Denarius\Service\Month\MonthCacheKeys())->taxonomyNamespace();

        $monthWriter = new MonthCacheWriter($cache, $catalog);
        $monthJob = new MonthCacheRefreshJob(
            $kingdoms,
            KingdomPageQueryFactory::publicRead($transactions, $accounts),
            $monthWriter,
        );
        $managedKingdomId = (int) $saved->getId();
        $monthJob->type();
        $monthJob->handle(['kingdom_id' => 0, 'month' => 'bad']);
        $monthJob->handle(['kingdom_id' => $managedKingdomId, 'month' => '2026-09']);
        $monthWriter->deleteMonth($managedKingdomId, $month);

        $codec = new \Amtgard\Denarius\Service\Month\MonthStatementCacheCodec();
        $sampleStatement = KingdomPageQueryFactory::publicRead($transactions, $accounts)->statement($saved, $month);
        $encoded = $codec->encode($sampleStatement);
        $codec->decode($encoded, DisplayMode::LessRedacted, $month);
        $codec->decode(null, DisplayMode::LessRedacted, $month);
        $codec->decode('{"month":"2020-01","rows":[]}', DisplayMode::LessRedacted, $month);
        $codec->decode(json_encode([
            'mode' => DisplayMode::LessRedacted->value,
            'month' => $month->key(),
            'rows' => [
                [
                    'kind' => 'line',
                    'postedOn' => '2026-09-02',
                    'amountCents' => 1,
                    'category' => 'office',
                    'description' => 'supplies',
                    'counterparty' => 'Shop',
                    'status' => 'posted',
                    'accountName' => 'Checking',
                ],
                [
                    'kind' => 'total',
                    'category' => 'office',
                    'count' => 2,
                    'amountCents' => 100,
                ],
            ],
            'absence' => ['code' => 'no_transactions', 'detail' => 'none'],
        ], JSON_THROW_ON_ERROR), DisplayMode::LessRedacted, $month);

        $monthPublisher = new \Amtgard\Denarius\Service\Month\MonthCacheRefreshPublisher(new MemoryMessages(), $transactions);
        $monthPublisher->schedule((int) $saved->getId());
        $monthPublisher->schedule((int) $saved->getId(), $month);
        $monthPublisher->targetMonths((int) $saved->getId());

        $syncFeedback = new \Amtgard\Denarius\Service\Ledger\ManagerLedgerSyncFeedback();
        $syncFeedback->forManage(
            KingdomRecord::builder()->enrollmentStatus('disconnected')->build(),
            false,
        );
        $syncFeedback->forManage(
            KingdomRecord::builder()->enrollmentStatus('connected')->build(),
            false,
        );
        $syncFeedback->forManage(
            KingdomRecord::builder()
                ->enrollmentStatus('connected')
                ->lastSyncAttemptedAt('2026-10-07T16:45:25+00:00')
                ->lastSyncStatus('failed')
                ->lastSyncError('timeout')
                ->build(),
            false,
        );
        $syncFeedback->forManage(
            KingdomRecord::builder()
                ->enrollmentStatus('connected')
                ->lastSyncAttemptedAt('2026-10-07T16:45:25+00:00')
                ->lastSyncStatus('succeeded')
                ->build(),
            false,
        );
        $syncFeedback->forManage(
            KingdomRecord::builder()
                ->enrollmentStatus('connected')
                ->lastSyncAttemptedAt('2026-10-07T16:45:25+00:00')
                ->lastSyncStatus('succeeded')
                ->build(),
            true,
        );
        $syncFeedback->forManage(
            KingdomRecord::builder()
                ->enrollmentStatus('connected')
                ->lastSyncAttemptedAt('not-a-date')
                ->lastSyncStatus('pending')
                ->build(),
            true,
        );

        $messages = new MemoryMessages();
        $worker = new LedgerWorker($messages, Strategies::jobs($sync, $recategorizer, $monthJob), 1);
        $worker->handle('not-json');
        $worker->handle(json_encode(['type' => 'ledger', 'orkKingdomId' => 4]));
        $worker->handle(json_encode(['type' => 'month_cache', 'kingdom_id' => $managedKingdomId, 'month' => '2026-09']));
        $worker->handle(json_encode(['type' => 'other']));
        $worker->run(1);

        $jobs = Strategies::jobs($sync, $recategorizer, $monthJob);
        $jobs->find('ledger')->handle(['orkKingdomId' => 4]);
        $ignoredJob = $jobs->find('other');
        $ignoredJob->type();
        $ignoredJob->handle(['orkKingdomId' => 4]);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE transactions (kingdom_id INTEGER); CREATE TABLE published_accounts (kingdom_id INTEGER); CREATE TABLE enrollment_secrets (kingdom_id INTEGER);');
        (new \Amtgard\Denarius\Service\Enrollment\BankConnectionReset($pdo))->clearKingdom(4);
    }
}
