<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Auth\BootstrapAdmins;
use Amtgard\Denarius\Auth\ClaimOrn;
use Amtgard\Denarius\Auth\CurrentActor;
use Amtgard\Denarius\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Auth\IdpPolicyGateway;
use Amtgard\Denarius\Persistence\Repository\AccountRepositoryInterface;
use Amtgard\Denarius\Queue\KeyValueStore;
use Amtgard\Denarius\Queue\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;
use Amtgard\Denarius\Queue\MessageQueue;
use Amtgard\Denarius\Auth\PolicyGateway;
use Amtgard\Denarius\Persistence\Repository\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\SecretRepositoryInterface;
use Amtgard\Denarius\Bank\Teller\TellerApi;
use Amtgard\Denarius\Persistence\Repository\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\AccessResult;
use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\KingdomAccess;
use Amtgard\Denarius\Domain\KingdomSlug;
use Amtgard\Denarius\Domain\LedgerLine;
use Amtgard\Denarius\Domain\Money;
use Amtgard\Denarius\Domain\MonthStatementBuilder;
use Amtgard\Denarius\Domain\MonthWindow;
use Amtgard\Denarius\Domain\Viewer;
use Amtgard\Denarius\Domain\Visibility;
use Amtgard\Denarius\Http\BuildInfo;
use Amtgard\Denarius\Http\CsrfToken;
use Amtgard\Denarius\Http\JsonBody;
use Amtgard\Denarius\Queue\MessageKingdomRefreshQueue;
use Amtgard\Denarius\Queue\PubSubMessageQueue;
use Amtgard\Denarius\Queue\RedisKeyValueStore;
use Amtgard\Denarius\Record\AccountRecord;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Record\PrincipalRecord;
use Amtgard\Denarius\Record\RoleGrantRecord;
use Amtgard\Denarius\Record\TransactionRecord;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\DailySweep;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomPageQuery;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Service\PrincipalSync;
use Amtgard\Denarius\Service\RoleAdmin;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Session\RedisSessionHandler;
use Amtgard\Denarius\Bank\Teller\CurlTellerApi;
use Amtgard\Denarius\Bank\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaim;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaimList;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\PHPUnit\AmtgardTestCase;
use Amtgard\SetQueue\PubSubQueue;
use Slim\Psr7\Response;

final class ApplicationTest extends AmtgardTestCase
{
    protected function tearDown(): void
    {
        CurrentActor::reset();
        unset($_SESSION['_csrf']);
    }

    public function testSlugMonthMoneyAndStatementModes(): void
    {
        $this->assertSame('golden-plains', KingdomSlug::fromName('  Golden   Plains! '));
        $this->assertSame('', KingdomSlug::fromName('---'));
        $this->assertTrue(KingdomSlug::isReserved('admin'));
        $this->assertFalse(KingdomSlug::isReserved('golden-plains'));
        $this->assertSame(Visibility::KingdomOnly, Visibility::fromStored('nope'));
        $this->assertSame(DisplayMode::Summarized, DisplayMode::fromStored('nope'));

        $now = new \DateTimeImmutable('2026-09-15');
        $month = MonthWindow::fromQuery('2026-01', $now);
        $this->assertSame('2025-12', $month->previous()->key());
        $this->assertSame('2026-02', $month->next()->key());
        $this->assertSame('2026-09', MonthWindow::fromQuery('bad', $now)->key());
        $this->assertSame('2026-09', MonthWindow::fromQuery('2026-13', $now)->key());
        $this->assertTrue($month->contains('2026-01-31'));
        $this->assertFalse($month->contains('2026-02-01'));
        $this->assertSame('2027-01', (new MonthWindow(2026, 12))->next()->key());
        $this->assertSame('2026-01-01', $month->startDate());

        $this->assertSame(123, Money::centsFromDecimal('1.225'));
        $this->assertSame(-100, Money::centsFromDecimal('-1'));
        $this->assertSame('-1.00', Money::format(-100));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Money::centsFromDecimal('nope'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => new MonthWindow(2026, 0));

        $line = LedgerLine::builder()->postedOn('2026-01-02')->amountCents(250)->category('dining')->description('meal')->counterparty('Cafe')->status('posted')->accountName('Checking')->build();
        $other = LedgerLine::builder()->postedOn('2026-02-01')->amountCents(100)->category('fuel')->build();
        $builder = MonthStatementBuilder::standard();
        $all = $builder->build([$line, $other], DisplayMode::All, $month);
        $this->assertCount(1, $all->rows);
        $redacted = $builder->build([$line], DisplayMode::Redacted, $month);
        $this->assertSame('', $redacted->rows[0]->getDescription());
        $this->assertSame('', $redacted->rows[0]->getCounterparty());
        $this->assertSame(250, $redacted->rows[0]->getAmountCents());
        $this->assertSame('2026-01-02', $redacted->rows[0]->getPostedOn());
        $summary = $builder->build([
            $line,
            LedgerLine::builder()->postedOn('2026-01-03')->amountCents(50)->category('dining')->build(),
            LedgerLine::builder()->postedOn('2026-01-04')->amountCents(20)->category('fuel')->build(),
        ], DisplayMode::Summarized, $month);
        $this->assertSame('dining', $summary->rows[0]->category);
        $this->assertSame(2, $summary->rows[0]->count);
        $this->assertSame(300, $summary->rows[0]->amountCents);
    }

    public function testAccessClaimsActorsAndPermissions(): void
    {
        $access = KingdomAccess::standard();
        $this->assertSame(AccessResult::Allow, $access->decide(Visibility::Public, null, 3));
        $this->assertSame(AccessResult::Login, $access->decide(Visibility::Registered, null, 3));
        $viewer = new Viewer('9', 3);
        $this->assertSame(AccessResult::Allow, $access->decide(Visibility::Registered, $viewer, 3));
        $this->assertSame(AccessResult::Allow, $access->decide(Visibility::KingdomOnly, $viewer, 3));
        $this->assertSame(AccessResult::Deny, $access->decide(Visibility::KingdomOnly, new Viewer('9', null), 3));
        $this->assertSame(AccessResult::Deny, $access->decide(Visibility::KingdomOnly, new Viewer('9', 4), 3));

        $this->assertSame('Denarius:0:0:Denarius/Admin', ClaimOrn::admin());
        $this->assertSame('Denarius:0:12:Denarius/ManageKingdom', ClaimOrn::manage(12));
        $this->assertNull(ClaimOrn::parse('Other:0:0:Denarius/Admin'));
        $this->assertNull(ClaimOrn::parse('Denarius:0:x:Denarius/Admin'));
        $this->assertNull(ClaimOrn::parse('Denarius:0:1:Nope'));
        $parsed = ClaimOrn::parse(ClaimOrn::manage(12));
        $this->assertSame(12, $parsed->kingdomId);

        $bootstrap = BootstrapAdmins::fromEnv(' 7, 8 ,');
        $this->assertTrue($bootstrap->contains('7'));
        $this->assertFalse($bootstrap->contains('9'));
        $this->assertFalse(BootstrapAdmins::fromEnv('  ')->contains('1'));
        $this->assertFalse(BootstrapAdmins::fromEnv(null)->contains('1'));

        $authorizer = new DenariusAuthorizer();
        $this->assertTrue($authorizer->isAdmin('7', [], $bootstrap));
        $this->assertTrue($authorizer->isAdmin('1', [ClaimOrn::admin()], BootstrapAdmins::fromEnv(null)));
        $this->assertFalse($authorizer->isAdmin('1', [ClaimOrn::manage(4)], BootstrapAdmins::fromEnv(null)));
        $this->assertSame([4, 9], $authorizer->managedKingdomIds([ClaimOrn::manage(9), ClaimOrn::manage(4), ClaimOrn::manage(0), 'nope']));

        CurrentActor::set('15');
        $this->assertSame('15', CurrentActor::id());
        $this->assertSame(15, CurrentActor::editedById());
        CurrentActor::set('abc');
        $this->assertNull(CurrentActor::editedById());
        CurrentActor::set(null);
        $this->assertNull(CurrentActor::editedById());

        $cache = new ArrayStore();
        $policies = new FakePolicies([ClaimOrn::admin()]);
        $permissions = new PermissionService($policies, $cache, $authorizer, $bootstrap, 30);
        $this->assertTrue($permissions->isAdmin('1'));
        $policies->orns = [];
        $this->assertTrue($permissions->isAdmin('1'));
        $permissions->forget('1');
        $this->assertFalse($permissions->isAdmin('1'));
        $cache->set('denarius:claims:2', '{', 10);
        $policies->orns = [ClaimOrn::manage(5)];
        $this->assertSame([5], $permissions->managedKingdomIds('2'));
    }

    public function testRoleAdminSettingsEnrollmentSyncAndWebhooks(): void
    {
        $kingdoms = new MemoryKingdoms();
        $grants = new MemoryGrants();
        $policies = new FakePolicies([]);
        $permissions = new PermissionService($policies, new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $admin = new RoleAdmin($policies, $permissions, $kingdoms, $grants, '3');
        $admin->grantAdmin('9');
        $admin->revokeAdmin('9');
        $saved = $admin->grantManager('9', 4, 'Golden Plains');
        $again = $admin->grantManager('9', 4, 'Golden Plains');
        $this->assertSame($saved->getId(), $again->getId());
        $admin->revokeManager('9', 4);
        $this->assertCount(5, $grants->rows);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $admin->grantManager('9', 1, 'admin'));

        $settings = new KingdomSettings($kingdoms);
        $updated = $settings->update($saved, Visibility::Public, DisplayMode::All);
        $this->assertSame('public', $updated->getVisibility());
        $this->assertSame('all', $updated->getDisplayMode());

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
        $this->assertSame('connected', $connected->getEnrollmentStatus());
        $this->assertSame('token-1', $cipher->decrypt((string) $secrets->findCiphertext((int) $connected->getId())));
        $this->assertCount(1, $queue->ledger);
        $enrollment->setPublished($connected, []);
        $this->assertFalse($accounts->forKingdom((int) $connected->getId())[0]->getPublished());
        $disconnected = $enrollment->markDisconnected($connected);
        $this->assertSame('disconnected', $disconnected->getEnrollmentStatus());
        $this->assertThrows(\InvalidArgumentException::class, fn () => $enrollment->connect($updated, []));

        $round = $cipher->encrypt('secret-token');
        $this->assertSame('secret-token', $cipher->decrypt($round));
        $this->assertThrows(\RuntimeException::class, fn () => $cipher->decrypt('%%%'));
        $this->assertThrows(\RuntimeException::class, fn () => $cipher->decrypt(base64_encode('short')));

        $transactions = new MemoryTransactions();
        $sync = new TransactionSynchronizer($kingdoms, $accounts, $secrets, $transactions, Strategies::providers($teller), $cipher, new \DateTimeImmutable('2026-09-01'), Strategies::months($cache));
        $enrollment->setPublished($kingdoms->findByOrkId(4), ['acc_1' => true]);
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
        $this->assertTrue($sync->sync(4));
        $this->assertGreaterThan(0, (int) $cache->get('denarius:month-gen:1'));
        $this->assertFalse($sync->sync(99));
        $this->assertSame(2, count($transactions->forKingdom((int) $connected->getId())));
        $this->assertNotNull($kingdoms->findByOrkId(4)->getLastSyncedAt());

        $handler = new ProviderWebhookHandler(Strategies::providers(Strategies::teller(verifier: new TellerWebhookVerifier('whsec', 300))), $kingdoms, Strategies::events($queue, $enrollment));
        $body = json_encode(['type' => 'transactions.processed', 'enrollment_id' => 'enr_1'], JSON_THROW_ON_ERROR);
        $now = 1_700_000_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec');
        $this->assertTrue($handler->handle('teller', $body, $signature, $now));
        $this->assertFalse($handler->handle('teller', $body, 't=1,v1=nope', $now));
        $this->assertFalse($handler->handle('teller', '{', $signature, $now));
        $empty = json_encode(['type' => 'transactions.processed'], JSON_THROW_ON_ERROR);
        $emptySig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $empty, 'whsec');
        $this->assertTrue($handler->handle('teller', $empty, $emptySig, $now));
        $disconnect = json_encode(['type' => 'enrollment.disconnected', 'payload' => ['enrollment_id' => 'enr_1']]);
        $disconnectSig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $disconnect, 'whsec');
        $this->assertTrue($handler->handle('teller', $disconnect, $disconnectSig, $now));
        $this->assertSame('disconnected', $kingdoms->findByEnrollmentId('enr_1')->getEnrollmentStatus());
        $unknown = json_encode(['type' => 'transactions.processed', 'enrollment_id' => 'missing']);
        $unknownSig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $unknown, 'whsec');
        $this->assertTrue($handler->handle('teller', $unknown, $unknownSig, $now));

        $verifier = new TellerWebhookVerifier('whsec', 300);
        $this->assertFalse($verifier->verify('body', null, $now));
        $this->assertFalse($verifier->verify('body', 't=abc,v1=abc', $now));
        $this->assertFalse($verifier->verify('body', 't=' . ($now - 500), $now));
        $this->assertFalse((new TellerWebhookVerifier(''))->verify('body', $signature, $now));

        $page = new KingdomPageQuery($transactions, $accounts, MonthStatementBuilder::standard());
        $statement = $page->statement($kingdoms->findByOrkId(4), new MonthWindow(2026, 9));
        $this->assertNotEmpty($statement->rows);

        $principals = new MemoryPrincipals();
        $syncPrincipal = new PrincipalSync($principals);
        $first = $syncPrincipal->upsert('9', 'a@b.c', 4, 'Golden Plains');
        $second = $syncPrincipal->upsert('9', 'new@b.c', null, null);
        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('new@b.c', $second->getEmail());

        $sweep = new DailySweep($kingdoms, $queue);
        $this->assertSame(0, $sweep->enqueueConnected());
    }

    public function testDirectoryQueueWorkerAndHttpClients(): void
    {
        $messages = new MemoryMessages();
        $refresh = new MessageKingdomRefreshQueue($messages);
        $refresh->publishLedger(4);
        $this->assertSame('ledger:4', $messages->published[0]['key']);

        $sync = new TransactionSynchronizer(
            new MemoryKingdoms(),
            new MemoryAccounts(),
            new MemorySecrets(),
            new MemoryTransactions(),
            Strategies::providers(Strategies::teller()),
            new TokenCipher('k'),
            new \DateTimeImmutable('now'),
            Strategies::months(),
        );
        $worker = new LedgerWorker($messages, Strategies::jobs($sync), 0);
        $worker->handle('nope');
        $worker->handle(json_encode(['type' => 'ledger', 'orkKingdomId' => 4]));
        $worker->handle(json_encode(['type' => 'other']));
        $this->assertSame(0, $worker->run(1));

        $redis = new class {
            public array $data = [];
            public function get(string $key): mixed
            {
                return $this->data[$key] ?? false;
            }
            public function setex(string $key, int $ttl, string $value): bool
            {
                $this->data[$key] = $value;
                return $ttl > 0;
            }
            public function del(string $key): int
            {
                unset($this->data[$key]);
                return 1;
            }
        };
        $store = new RedisKeyValueStore($redis);
        $store->set('a', 'b', 5);
        $this->assertSame('b', $store->get('a'));
        $store->delete('a');
        $this->assertNull($store->get('missing'));

        $sessions = new RedisSessionHandler($redis, 10);
        $this->assertTrue($sessions->open('', ''));
        $this->assertTrue($sessions->close());
        $this->assertSame('', $sessions->read('id'));
        $this->assertTrue($sessions->write('id', 'payload'));
        $this->assertSame('payload', $sessions->read('id'));
        $this->assertTrue($sessions->destroy('id'));
        $this->assertSame(0, $sessions->gc(1));

        $teller = new CurlTellerApi('https://api.teller.io', '', '', function (string $url): string {
            if (str_contains($url, 'transactions')) {
                $this->assertStringContainsString('from_id=txn_1', $url);
                return json_encode([['id' => 'txn_2'], 'skip']);
            }
            return json_encode([['id' => 'acc_1', 'name' => 'Checking']]);
        });
        $this->assertSame('acc_1', $teller->accounts('tok')[0]['id']);
        $this->assertSame('txn_2', $teller->transactions('tok', 'acc 1', 'txn_1')[0]['id']);

        $pubsub = new class extends PubSubQueue {
            public array $calls = [];
            public function publish(string $queueName, \JsonSerializable|string $key, \JsonSerializable|string $message, bool $replace = true): mixed
            {
                $this->calls[] = 'publish';
                return null;
            }
            public function redrive($queueName)
            {
                $this->calls[] = 'redrive';
            }
            public function subscribe(string $queueName, callable $callback, callable $failure = null): string
            {
                $this->calls[] = 'subscribe';
                return $queueName;
            }
            public function callConsumers(string $queueName, $count = 1)
            {
                $this->calls[] = 'consume';
            }
        };
        $adapter = new PubSubMessageQueue($pubsub);
        $adapter->publish('q', 'k', 'm');
        $adapter->redrive('q');
        $adapter->subscribe('q', fn () => null, null);
        $this->assertSame(0, $adapter->callConsumers('q'));

        $iam = new class {
            public bool $format = false;
            public function getServiceFormat(): void
            {
                if (!$this->format) {
                    throw new \RuntimeException('missing');
                }
            }
            public function createServiceFormat(ServiceFormatRequest $request): void
            {
                $this->format = $request->serviceFormat === ['Configuration', 'Kingdom'];
            }
            public function listPolicyClaims(string $id): PolicyClaimList
            {
                return new PolicyClaimList([new PolicyClaim('Denarius', ':0:0:', 'Denarius/Admin')]);
            }
            public function addPolicyClaimFromOrn(string $id, string $orn): void
            {
            }
            public function composeClaim(array $segments, string $resource): object
            {
                return (object) ['segments' => $segments, 'resource' => $resource];
            }
            public function deletePolicyClaim(string $id, object $claim): void
            {
            }
        };
        $gateway = new IdpPolicyGateway($iam);
        $gateway->ensureFormat();
        $gateway->ensureFormat();
        $this->assertSame(['Denarius:0:0:Denarius/Admin'], $gateway->listOrns('1'));
        $gateway->grant('1', ClaimOrn::admin());
        $gateway->revoke('1', ClaimOrn::manage(6));

        $_SESSION = [];
        $token = CsrfToken::issue();
        $this->assertTrue(CsrfToken::matches($token));
        $this->assertFalse(CsrfToken::matches('nope'));
        $this->assertSame($token, CsrfToken::issue());
        unset($_SESSION['_csrf']);
        $this->assertFalse(CsrfToken::matches('x'));

        $dir = sys_get_temp_dir() . '/denarius-version-' . uniqid();
        mkdir($dir);
        $this->assertSame('dev', BuildInfo::version($dir));
        file_put_contents($dir . '/VERSION', "  \n");
        $this->assertSame('dev', BuildInfo::version($dir));
        file_put_contents($dir . '/VERSION', "2026.1\n");
        $this->assertSame('2026.1', BuildInfo::version($dir));

        $response = JsonBody::write(new Response(), ['ok' => true], 201);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringContainsString('ok', (string) $response->getBody());
    }
}

final class ArrayStore implements KeyValueStore
{
    public array $data = [];
    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }
    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->data[$key] = $value;
    }
    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}

final class FakePolicies implements PolicyGateway
{
    public function __construct(public array $orns)
    {
    }
    public function ensureFormat(): void
    {
    }
    public function listOrns(string $idpUserId): array
    {
        return $this->orns;
    }
    public function grant(string $idpUserId, string $orn): void
    {
        $this->orns[] = $orn;
    }
    public function revoke(string $idpUserId, string $orn): void
    {
        $this->orns = array_values(array_filter($this->orns, static fn (string $item): bool => $item !== $orn));
    }
}

final class MemoryKingdoms implements KingdomRepositoryInterface
{
    /** @var array<int, KingdomRecord> */
    public array $rows = [];
    private int $next = 1;
    public function findBySlug(string $slug): ?KingdomRecord
    {
        foreach ($this->rows as $row) {
            if ($row->getSlug() === $slug) {
                return $row;
            }
        }
        return null;
    }
    public function findByOrkId(int $orkKingdomId): ?KingdomRecord
    {
        foreach ($this->rows as $row) {
            if ($row->getOrkKingdomId() === $orkKingdomId) {
                return $row;
            }
        }
        return null;
    }
    public function findByEnrollmentId(string $enrollmentId): ?KingdomRecord
    {
        foreach ($this->rows as $row) {
            if ($row->getEnrollmentId() === $enrollmentId) {
                return $row;
            }
        }
        return null;
    }
    public function findByProviderEnrollment(string $provider, string $enrollmentId): ?KingdomRecord
    {
        foreach ($this->rows as $row) {
            if ($row->getProvider() === $provider && $row->getEnrollmentId() === $enrollmentId) {
                return $row;
            }
        }
        return null;
    }
    public function save(KingdomRecord $kingdom): KingdomRecord
    {
        $id = $kingdom->getId() ?? $this->next++;
        $saved = KingdomRecord::builder()
            ->id($id)
            ->orkKingdomId($kingdom->getOrkKingdomId())
            ->name($kingdom->getName())
            ->slug($kingdom->getSlug())
            ->visibility($kingdom->getVisibility())
            ->displayMode($kingdom->getDisplayMode())
            ->enrollmentId($kingdom->getEnrollmentId())
            ->institutionName($kingdom->getInstitutionName())
            ->provider($kingdom->getProvider())
            ->enrollmentStatus($kingdom->getEnrollmentStatus())
            ->lastSyncedAt($kingdom->getLastSyncedAt())
            ->build();
        $this->rows[$id] = $saved;
        return $saved;
    }
    public function connected(): array
    {
        return array_values(array_filter($this->rows, static fn (KingdomRecord $row): bool => $row->getEnrollmentStatus() === 'connected'));
    }
}

final class MemoryGrants implements RoleGrantRepositoryInterface
{
    public array $rows = [];
    public function append(RoleGrantRecord $grant): void
    {
        $this->rows[] = $grant;
    }
}

final class MemorySecrets implements SecretRepositoryInterface
{
    public array $rows = [];
    public function findCiphertext(int $kingdomId): ?string
    {
        return $this->rows[$kingdomId] ?? null;
    }
    public function saveCiphertext(int $kingdomId, string $ciphertext): void
    {
        $this->rows[$kingdomId] = $ciphertext;
    }
}

final class MemoryAccounts implements AccountRepositoryInterface
{
    /** @var array<int, list<AccountRecord>> */
    public array $rows = [];
    private int $next = 1;
    public function forKingdom(int $kingdomId): array
    {
        return $this->rows[$kingdomId] ?? [];
    }
    public function save(AccountRecord $account): AccountRecord
    {
        $saved = AccountRecord::builder()
            ->id($account->getId() ?? $this->next++)
            ->kingdomId($account->getKingdomId())
            ->tellerAccountId($account->getTellerAccountId())
            ->name($account->getName())
            ->type($account->getType())
            ->lastFour($account->getLastFour())
            ->published($account->getPublished())
            ->build();
        $list = $this->rows[$account->getKingdomId()] ?? [];
        $replaced = false;
        foreach ($list as $index => $existing) {
            if ($existing->getTellerAccountId() === $saved->getTellerAccountId()) {
                $list[$index] = $saved;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $list[] = $saved;
        }
        $this->rows[$account->getKingdomId()] = $list;
        return $saved;
    }
}

final class MemoryTransactions implements TransactionRepositoryInterface
{
    /** @var array<int, list<TransactionRecord>> */
    public array $rows = [];
    public function upsert(TransactionRecord $transaction): void
    {
        $list = $this->rows[$transaction->getKingdomId()] ?? [];
        $list[] = $transaction;
        $this->rows[$transaction->getKingdomId()] = $list;
    }
    public function forKingdom(int $kingdomId): array
    {
        return $this->rows[$kingdomId] ?? [];
    }
}

final class MemoryPrincipals implements PrincipalRepositoryInterface
{
    /** @var array<string, PrincipalRecord> */
    public array $rows = [];
    private int $next = 1;
    public function findByIdpUserId(string $idpUserId): ?PrincipalRecord
    {
        return $this->rows[$idpUserId] ?? null;
    }
    public function save(PrincipalRecord $principal): PrincipalRecord
    {
        $saved = PrincipalRecord::builder()
            ->id($principal->getId() ?? $this->next++)
            ->idpUserId($principal->getIdpUserId())
            ->email($principal->getEmail())
            ->orkKingdomId($principal->getOrkKingdomId())
            ->orkKingdomName($principal->getOrkKingdomName())
            ->build();
        $this->rows[$principal->getIdpUserId()] = $saved;
        return $saved;
    }
    public function searchByEmail(string $term): array
    {
        return array_values(array_filter($this->rows, static fn (PrincipalRecord $row): bool => str_contains($row->getEmail(), $term)));
    }
}

final class MemoryRefresh implements KingdomRefreshQueue
{
    public array $ledger = [];
    public function publishLedger(int $orkKingdomId): void
    {
        $this->ledger[] = $orkKingdomId;
    }
}

final class MemoryMessages implements MessageQueue
{
    public array $published = [];
    public function publish(string $queue, string $key, string $message): void
    {
        $this->published[] = compact('queue', 'key', 'message');
    }
    public function redrive(string $queue): void
    {
    }
    public function subscribe(string $queue, callable $callback, ?callable $failure): void
    {
    }
    public function callConsumers(string $queue): int
    {
        return 0;
    }
}

final class FakeTeller implements TellerApi
{
    public function accounts(string $accessToken): array
    {
        return [['id' => 'acc_1', 'name' => 'Checking', 'type' => 'depository', 'last_four' => '1234']];
    }
    public function transactions(string $accessToken, string $accountId, ?string $fromId): array
    {
        if ($fromId === 'txn_2') {
            return [];
        }
        if ($fromId === 'txn_1') {
            return [[
                'id' => 'txn_2',
                'date' => '2026-09-03',
                'amount' => '1.00',
                'description' => 'second',
                'status' => 'posted',
                'details' => ['category' => 'income', 'counterparty' => ['name' => 'Patron']],
            ]];
        }
        return [
            [
                'id' => 'txn_1',
                'date' => '2026-09-02',
                'amount' => '-12.50',
                'description' => 'supplies',
                'status' => 'posted',
                'details' => ['category' => 'office'],
            ],
            ['id' => ''],
        ];
    }
}
