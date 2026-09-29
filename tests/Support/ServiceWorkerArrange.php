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
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Ledger\DailySweep;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemoryGrants;
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

        $registry = Strategies::admin();
        $body = ['idp_user_id' => '9', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains'];
        foreach (['grant-admin', 'revoke-admin', 'grant-manager', 'revoke-manager'] as $name) {
            $registry->find($name)->execute($roleAdmin, $body);
        }
        $ignoredCommand = $registry->find('unknown');
        $ignoredCommand->name();
        $ignoredCommand->execute($roleAdmin, $body);

        $settings = new KingdomSettings($kingdoms);
        $updated = $settings->update($saved, \Amtgard\Denarius\Domain\Access\Visibility::Public, DisplayMode::All);

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
        $sync = new TransactionSynchronizer($kingdoms, $accounts, $secrets, $transactions, Strategies::providers($teller), $cipher, new \DateTimeImmutable('2026-09-01'), Strategies::months($cache));
        $sync->sync(4);
        $sync->sync(99);

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

        $page = new KingdomPageQuery($transactions, $accounts, MonthStatementBuilder::standard());
        $page->statement($kingdoms->findByOrkId(4), new MonthWindow(2026, 9));

        $connect = new BankConnect(Strategies::providers($teller));
        $connect->blank('  ');
        $connect->offer('golden-plains', ['institution' => 'First Bank', 'skipped' => 'teller', 'current' => 'simplefin', 'skip' => '1']);
        $connect->offer('golden-plains', ['institution' => '', 'skip' => '1', 'current' => 'teller']);
        $connect->offer('golden-plains', ['institution' => 'First Bank', 'skipped' => ['teller', ''], 'current' => 'teller', 'skip' => '1']);

        $month = new MonthWindow(2026, 9);
        $kingdomRow = KingdomRecord::builder()->id(4)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('all')->build();
        $origin = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(
                    DisplayMode::All,
                    $month,
                    [LedgerLine::builder()->postedOn('2026-09-02')->amountCents(250)->category('office')->description('paper')->counterparty('Shop')->build()],
                );
            }
        };
        $reader = new CachingMonthReader($origin, $cache);
        $reader->statement($kingdomRow, $month);
        $reader->statement($kingdomRow, $month);
        $cache->set('denarius:month:4:0:all:2026-09', 'not-json', 10);
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
        $cachedSummary = new CachingMonthReader($summaryOrigin, $cache);
        $cachedSummary->statement($summaryKingdom, $month);
        $cachedSummary->statement($summaryKingdom, $month);

        (new MonthInvalidator($cache))->forget(4);

        $sweep = new DailySweep($kingdoms, $queue);
        $sweep->enqueueConnected();

        $messages = new MemoryMessages();
        $worker = new LedgerWorker($messages, Strategies::jobs($sync), 1);
        $worker->handle('not-json');
        $worker->handle(json_encode(['type' => 'ledger', 'orkKingdomId' => 4]));
        $worker->handle(json_encode(['type' => 'other']));
        $worker->run(1);

        $jobs = Strategies::jobs($sync);
        $jobs->find('ledger')->handle(['orkKingdomId' => 4]);
        $ignoredJob = $jobs->find('other');
        $ignoredJob->type();
        $ignoredJob->handle(['orkKingdomId' => 4]);
    }
}
