<?php

declare(strict_types=1);

use Amtgard\Denarius\Auth\BootstrapAdmins;
use Amtgard\Denarius\Auth\CurrentActor;
use Amtgard\Denarius\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Auth\IdpPolicyGateway;
use Amtgard\Denarius\Bank\DisconnectLedgerNotice;
use Amtgard\Denarius\Bank\LedgerNoticeRegistry;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\RefreshLedgerNotice;
use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\MessageQueue;
use Amtgard\Denarius\Contract\OrkKingdomClient;
use Amtgard\Denarius\Contract\PolicyGateway;
use Amtgard\Denarius\Contract\PrincipalStore;
use Amtgard\Denarius\Contract\RoleGrantStore;
use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Contract\TellerApi;
use Amtgard\Denarius\Contract\TransactionStore;
use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Domain\KingdomAccess;
use Amtgard\Denarius\Domain\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Presentation\StatementPresenterRegistry;
use Amtgard\Denarius\Domain\Access\VisibilityPolicyRegistry;
use Amtgard\Denarius\Http\SyncPrincipalMiddleware;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Ork\HttpOrkKingdomClient;
use Amtgard\Denarius\Ork\OrkKingdomParser;
use Amtgard\Denarius\Persistence\AaroAccountStore;
use Amtgard\Denarius\Persistence\AaroKingdomStore;
use Amtgard\Denarius\Persistence\AaroPrincipalStore;
use Amtgard\Denarius\Persistence\AaroRoleGrantStore;
use Amtgard\Denarius\Persistence\AaroSecretStore;
use Amtgard\Denarius\Persistence\AaroTransactionStore;
use Amtgard\Denarius\Queue\MessageKingdomRefreshQueue;
use Amtgard\Denarius\Queue\PubSubMessageQueue;
use Amtgard\Denarius\Queue\RedisKeyValueStore;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomPageQuery;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\Month\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Service\PrincipalSync;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\RevokeManagerCommand;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
use Amtgard\Denarius\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Worker\Job\DirectoryRefreshJob;
use Amtgard\Denarius\Worker\Job\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Session\RedisSessionHandler;
use Amtgard\Denarius\Teller\CurlTellerApi;
use Amtgard\Denarius\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisDataStructureConfig;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisHashSetFactory;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisRedrivableQueueFactory;
use Amtgard\SetQueue\DataStructure\SetQueue;
use Amtgard\SetQueue\PubSubQueue;
use Psr\Container\ContainerInterface;
use Slim\App;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

return [
    PDO::class => function () {
        $config = Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration::fromEnvironment();
        return Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider::fromConfiguration($config)->getPdo();
    },
    KingdomStore::class => fn (PDO $pdo) => new AaroKingdomStore($pdo),
    PrincipalStore::class => fn (PDO $pdo) => new AaroPrincipalStore($pdo),
    AccountStore::class => fn (PDO $pdo) => new AaroAccountStore($pdo),
    SecretStore::class => fn (PDO $pdo) => new AaroSecretStore($pdo),
    TransactionStore::class => fn (PDO $pdo) => new AaroTransactionStore($pdo),
    RoleGrantStore::class => fn (PDO $pdo) => new AaroRoleGrantStore($pdo),
    SessionAuthStore::class => fn () => new SessionAuthStore(),
    IdpClient::class => fn () => IdpClientFactory::fromEnvVars(),
    PolicyGateway::class => fn (IdpClient $idp) => new IdpPolicyGateway($idp->clientIam()),
    BootstrapAdmins::class => fn () => BootstrapAdmins::fromEnv($_ENV['DENARIUS_BOOTSTRAP_ADMIN_IDP_USER_IDS'] ?? null),
    DenariusAuthorizer::class => fn () => new DenariusAuthorizer(),
    Redis::class => function () {
        $redis = new Redis();
        $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int) ($_ENV['REDIS_PORT'] ?? 6379));
        $redis->select((int) ($_ENV['REDIS_DB'] ?? 0));
        return $redis;
    },
    RedisKeyValueStore::class => fn (Redis $redis) => new RedisKeyValueStore($redis),
    PubSubQueue::class => function () {
        $config = new RedisDataStructureConfig();
        $config->setConfig([
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        ]);
        $queue = new PubSubQueue();
        $queue->addQueue(LedgerWorker::QUEUE, new SetQueue(
            LedgerWorker::QUEUE,
            $config,
            new RedisHashSetFactory(),
            new RedisRedrivableQueueFactory(),
        ));
        return $queue;
    },
    MessageQueue::class => fn (PubSubQueue $queue) => new PubSubMessageQueue($queue),
    KingdomRefreshQueue::class => fn (MessageQueue $queue) => new MessageKingdomRefreshQueue($queue),
    OrkKingdomClient::class => fn () => new HttpOrkKingdomClient(
        $_ENV['ORK_API_BASE_URL'] ?? 'https://ork.amtgard.com/orkservice/Json/index.php',
        $_ENV['ORK_API_USER_AGENT'] ?? '',
        $_ENV['ORK_API_REFERER'] ?? '',
        new OrkKingdomParser(),
    ),
    CachedKingdomDirectory::class => fn (ContainerInterface $c) => new CachedKingdomDirectory(
        $c->get(OrkKingdomClient::class),
        $c->get(RedisKeyValueStore::class),
        $c->get(KingdomRefreshQueue::class),
    ),
    PermissionService::class => fn (ContainerInterface $c) => new PermissionService(
        $c->get(PolicyGateway::class),
        $c->get(RedisKeyValueStore::class),
        $c->get(DenariusAuthorizer::class),
        $c->get(BootstrapAdmins::class),
    ),
    TokenCipher::class => fn () => new TokenCipher($_ENV['APP_KEY'] ?? ''),
    TellerApi::class => fn () => new CurlTellerApi(
        $_ENV['TELLER_API_BASE'] ?? 'https://api.teller.io',
        $_ENV['TELLER_CERT_PATH'] ?? '',
        $_ENV['TELLER_KEY_PATH'] ?? '',
    ),
    LedgerProvider::class => fn (ContainerInterface $c) => new TellerLedgerProvider(
        $c->get(TellerApi::class),
        $c->get(TellerWebhookVerifier::class),
        TellerLedgerProvider::actions(),
    ),
    EnrollmentService::class => fn (ContainerInterface $c) => new EnrollmentService(
        $c->get(KingdomStore::class),
        $c->get(SecretStore::class),
        $c->get(AccountStore::class),
        $c->get(LedgerProvider::class),
        $c->get(TokenCipher::class),
        $c->get(KingdomRefreshQueue::class),
        $c->get(MonthInvalidator::class),
    ),
    TransactionSynchronizer::class => fn (ContainerInterface $c) => new TransactionSynchronizer(
        $c->get(KingdomStore::class),
        $c->get(AccountStore::class),
        $c->get(SecretStore::class),
        $c->get(TransactionStore::class),
        $c->get(LedgerProvider::class),
        $c->get(TokenCipher::class),
        new DateTimeImmutable('now'),
        $c->get(MonthInvalidator::class),
    ),
    TellerWebhookVerifier::class => fn () => new TellerWebhookVerifier($_ENV['TELLER_WEBHOOK_SECRET'] ?? ''),
    ProviderWebhookHandler::class => fn (ContainerInterface $c) => new ProviderWebhookHandler(
        $c->get(LedgerProvider::class),
        $c->get(KingdomStore::class),
        new LedgerNoticeRegistry([
            new RefreshLedgerNotice($c->get(KingdomRefreshQueue::class)),
            new DisconnectLedgerNotice($c->get(EnrollmentService::class)),
        ]),
    ),
    KingdomPageQuery::class => fn (ContainerInterface $c) => new KingdomPageQuery(
        $c->get(TransactionStore::class),
        $c->get(AccountStore::class),
        new MonthStatementBuilder($c->get(StatementPresenterRegistry::class)),
    ),
    MonthInvalidator::class => fn (RedisKeyValueStore $store) => new MonthInvalidator($store),
    MonthReader::class => fn (ContainerInterface $c) => new CachingMonthReader(
        $c->get(KingdomPageQuery::class),
        $c->get(RedisKeyValueStore::class),
    ),
    StatementPresenterRegistry::class => fn () => StatementPresenterRegistry::standard(),
    VisibilityPolicyRegistry::class => fn () => VisibilityPolicyRegistry::standard(),
    KingdomAccess::class => fn (VisibilityPolicyRegistry $policies) => new KingdomAccess($policies),
    KingdomSettings::class => fn (KingdomStore $kingdoms) => new KingdomSettings($kingdoms),
    PrincipalSync::class => fn (PrincipalStore $principals) => new PrincipalSync($principals),
    SyncPrincipalMiddleware::class => fn (ContainerInterface $c) => new SyncPrincipalMiddleware(
        $c->get(SessionAuthStore::class),
        $c->get(PrincipalSync::class),
    ),
    TwigEnvironment::class => function () {
        $twig = new TwigEnvironment(new FilesystemLoader(__DIR__ . '/../templates'), [
            'cache' => __DIR__ . '/cache/twig',
            'auto_reload' => true,
        ]);
        $twig->addFunction(new Twig\TwigFunction('csrf_token', static fn (): string => Amtgard\Denarius\Http\CsrfToken::issue()));
        return $twig;
    },
    TwigHtmlRenderer::class => fn (TwigEnvironment $twig) => new TwigHtmlRenderer($twig),
    HomeController::class => fn (ContainerInterface $c) => new HomeController(
        $c->get(TwigHtmlRenderer::class),
        $c->get(SessionAuthStore::class),
        dirname(__DIR__),
    ),
    KingdomPageController::class => fn (ContainerInterface $c) => new KingdomPageController(
        $c->get(KingdomStore::class),
        $c->get(MonthReader::class),
        $c->get(KingdomAccess::class),
        $c->get(SessionAuthStore::class),
        $c->get(TwigHtmlRenderer::class),
    ),
    AdminController::class => fn (ContainerInterface $c) => new AdminController(
        $c->get(SessionAuthStore::class),
        $c->get(PermissionService::class),
        $c->get(CachedKingdomDirectory::class),
        $c->get(PrincipalStore::class),
        $c->get(KingdomStore::class),
        $c->get(PolicyGateway::class),
        $c->get(RoleGrantStore::class),
        $c->get(TwigHtmlRenderer::class),
        new AdminCommandRegistry([
            new GrantAdminCommand(),
            new RevokeAdminCommand(),
            new GrantManagerCommand($c->get(CachedKingdomDirectory::class)),
            new RevokeManagerCommand(),
        ]),
    ),
    ManagerController::class => fn (ContainerInterface $c) => new ManagerController(
        $c->get(SessionAuthStore::class),
        $c->get(PermissionService::class),
        $c->get(KingdomStore::class),
        $c->get(AccountStore::class),
        $c->get(KingdomSettings::class),
        $c->get(EnrollmentService::class),
        $c->get(KingdomRefreshQueue::class),
        $c->get(TwigHtmlRenderer::class),
        $_ENV['TELLER_APPLICATION_ID'] ?? '',
        $_ENV['TELLER_ENVIRONMENT'] ?? 'sandbox',
    ),
    WebhookController::class => fn (ProviderWebhookHandler $handler) => new WebhookController($handler),
    LedgerWorker::class => fn (ContainerInterface $c) => new LedgerWorker(
        $c->get(MessageQueue::class),
        new RefreshJobRegistry([
            new DirectoryRefreshJob($c->get(CachedKingdomDirectory::class)),
            new LedgerRefreshJob($c->get(TransactionSynchronizer::class)),
        ]),
    ),
    RedisSessionHandler::class => function () {
        $redis = new Redis();
        $redis->connect($_ENV['SESSION_REDIS_HOST'] ?? '127.0.0.1', (int) ($_ENV['SESSION_REDIS_PORT'] ?? 6379));
        $redis->select((int) ($_ENV['SESSION_REDIS_DB'] ?? 1));
        return new RedisSessionHandler($redis);
    },
    IdpAuthController::class => function (ContainerInterface $container) {
        $app = $container->get(App::class);
        return new IdpAuthController(
            $container->get(IdpClient::class),
            $container->get(SessionAuthStore::class),
            postLoginRoute: 'home',
            postLogoutRoute: 'home',
            routeParser: $app->getRouteCollector()->getRouteParser(),
        );
    },
    App::class => function (ContainerInterface $container) {
        $app = DI\Bridge\Slim\Bridge::create($container);
        (require __DIR__ . '/routes.php')($app);
        return $app;
    },
];
