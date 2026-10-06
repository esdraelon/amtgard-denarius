<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Auth\Impl\IdpPolicyGateway;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ConfiguredLedgerProviders;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\DisconnectLedgerNotice;
use Amtgard\Denarius\Domain\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\PresentCredentials;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ProviderAdmission;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\RefreshLedgerNotice;
use Amtgard\Denarius\Persistence\Orm;
use Amtgard\Denarius\Persistence\Repository\Account\Impl\AccountRepository;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Kingdom\Impl\KingdomRepository;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Principal\Impl\PrincipalRepository;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\Impl\RoleGrantRepository;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Secret\Impl\SecretRepository;
use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\TransactionRepository;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenterRegistry;
use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicyRegistry;
use Amtgard\Denarius\Utilities\Http\BuildInfo;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Http\PostCsrfMiddleware;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\CorrelationMiddleware;
use Amtgard\Denarius\Utilities\Log\JsonStderrHandler;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\Impl\MessageKingdomRefreshQueue;
use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\Denarius\Utilities\Queue\Message\Impl\PubSubMessageQueue;
use Amtgard\Denarius\Utilities\Queue\KeyValue\Impl\RedisKeyValueStore;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider;
use Amtgard\Denarius\Controller\SimpleFinReturnController;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipelineFactory;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Kingdom\ManagerKingdomPageQuery;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
use Amtgard\Denarius\Service\Admin\Impl\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeManagerCommand;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\CurlPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidWebhookVerifier;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\CurlStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeWebhookVerifier;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceClassifier;
use Amtgard\Denarius\Service\Ledger\TransactionPublicationApplier;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Worker\Job\Impl\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;
use Amtgard\Denarius\Utilities\Session\RedisSessionHandler;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\CurlTellerApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
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
    KingdomRepositoryInterface::class => fn () => Orm::repository(KingdomRepository::class),
    PrincipalRepositoryInterface::class => fn () => Orm::repository(PrincipalRepository::class),
    AccountRepositoryInterface::class => fn () => Orm::repository(AccountRepository::class),
    SecretRepositoryInterface::class => fn () => Orm::repository(SecretRepository::class),
    TransactionRepositoryInterface::class => fn () => Orm::repository(TransactionRepository::class),
    RoleGrantRepositoryInterface::class => fn () => Orm::repository(RoleGrantRepository::class),
    SessionAuthStore::class => fn () => new SessionAuthStore(),
    LoggingIdpHttpClient::class => function () {
        $environment = IdpClientEnvironmentFactory::fromEnvVars();
        $inner = new \GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => $environment->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]);

        return new LoggingIdpHttpClient($inner);
    },
    IdpClient::class => fn (ContainerInterface $c) => IdpClientFactory::fromEnvVars(null, null, $c->get(LoggingIdpHttpClient::class)),
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
    PermissionService::class => fn (ContainerInterface $c) => new PermissionService(
        $c->get(PolicyGateway::class),
        $c->get(RedisKeyValueStore::class),
        $c->get(DenariusAuthorizer::class),
        $c->get(BootstrapAdmins::class),
    ),
    AccountNavBuilder::class => fn (ContainerInterface $c) => new AccountNavBuilder(
        $c->get(PermissionService::class),
        $c->get(KingdomRepositoryInterface::class),
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
        new PresentCredentials([$_ENV['TELLER_APPLICATION_ID'] ?? '']),
        $_ENV['TELLER_APPLICATION_ID'] ?? '',
        $_ENV['TELLER_ENVIRONMENT'] ?? 'sandbox',
    ),
    StripeApi::class => fn () => new CurlStripeApi(
        $_ENV['STRIPE_API_BASE'] ?? 'https://api.stripe.com',
        $_ENV['STRIPE_SECRET_KEY'] ?? '',
    ),
    StripeWebhookVerifier::class => fn () => new StripeWebhookVerifier($_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''),
    StripeLedgerProvider::class => fn (ContainerInterface $c) => new StripeLedgerProvider(
        $c->get(StripeApi::class),
        $c->get(StripeWebhookVerifier::class),
        StripeLedgerProvider::actions(),
        new PresentCredentials([$_ENV['STRIPE_SECRET_KEY'] ?? '']),
        new PreviousMonthWindow(new DateTimeImmutable('now')),
        $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
    ),
    PlaidApi::class => fn () => new CurlPlaidApi(
        $_ENV['PLAID_API_BASE'] ?? 'https://sandbox.plaid.com',
        $_ENV['PLAID_CLIENT_ID'] ?? '',
        $_ENV['PLAID_SECRET'] ?? '',
        $_ENV['PLAID_CLIENT_NAME'] ?? 'Denarius',
    ),
    PlaidWebhookVerifier::class => fn (ContainerInterface $c) => new PlaidWebhookVerifier($c->get(PlaidApi::class)),
    PlaidLedgerProvider::class => fn (ContainerInterface $c) => new PlaidLedgerProvider(
        $c->get(PlaidApi::class),
        $c->get(PlaidWebhookVerifier::class),
        PlaidLedgerProvider::actions(),
        new PresentCredentials([$_ENV['PLAID_CLIENT_ID'] ?? '', $_ENV['PLAID_SECRET'] ?? '']),
        new PreviousMonthWindow(new DateTimeImmutable('now')),
    ),
    LedgerProviderRegistry::class => fn (ContainerInterface $c) => (new ConfiguredLedgerProviders([
        new ProviderAdmission($c->get(StripeLedgerProvider::class), new PresentCredentials([$_ENV['STRIPE_SECRET_KEY'] ?? ''])),
        new ProviderAdmission($c->get(PlaidLedgerProvider::class), new PresentCredentials([$_ENV['PLAID_CLIENT_ID'] ?? '', $_ENV['PLAID_SECRET'] ?? ''])),
        new ProviderAdmission($c->get(LedgerProvider::class), new PresentCredentials([$_ENV['TELLER_APPLICATION_ID'] ?? ''])),
        new ProviderAdmission($c->get(SimpleFinLedgerProvider::class), new PresentCredentials([
            $_ENV['SIMPLEFIN_APP_ID'] ?? '',
            $_ENV['SIMPLEFIN_APP_TOKEN'] ?? '',
        ])),
    ]))->registry(),
    SimpleFinApplicationConfig::class => fn () => SimpleFinApplicationConfig::fromEnv(),
    SimpleFinApi::class => fn () => new CurlSimpleFinApi(new SimpleFinHost(['simplefin.org'])),
    SimpleFinLedgerProvider::class => fn (ContainerInterface $c) => new SimpleFinLedgerProvider(
        $c->get(SimpleFinApi::class),
        new PresentCredentials([
            $_ENV['SIMPLEFIN_APP_ID'] ?? '',
            $_ENV['SIMPLEFIN_APP_TOKEN'] ?? '',
        ]),
        new PreviousMonthWindow(new DateTimeImmutable('now')),
        $c->get(SimpleFinApplicationConfig::class),
    ),
    SimpleFinConnectSession::class => fn () => new SimpleFinConnectSession(),
    SimpleFinReturnEnrollment::class => fn (ContainerInterface $c) => new SimpleFinReturnEnrollment(
        $c->get(KingdomRepositoryInterface::class),
        $c->get(EnrollmentService::class),
        $c->get(SimpleFinConnectSession::class),
        $c->get(PermissionService::class),
    ),
    SimpleFinReturnController::class => fn (ContainerInterface $c) => new SimpleFinReturnController(
        $c->get(SessionAuthStore::class),
        $c->get(SimpleFinReturnEnrollment::class),
        $c->get(TwigHtmlRenderer::class),
    ),
    EnrollmentService::class => fn (ContainerInterface $c) => new EnrollmentService(
        $c->get(KingdomRepositoryInterface::class),
        $c->get(SecretRepositoryInterface::class),
        $c->get(AccountRepositoryInterface::class),
        $c->get(LedgerProviderRegistry::class),
        $c->get(TokenCipher::class),
        $c->get(KingdomRefreshQueue::class),
        $c->get(MonthInvalidator::class),
    ),
    PublicationSettingsValidator::class => fn () => new PublicationSettingsValidator(),
    PublicationEmbargoCalculator::class => fn () => new PublicationEmbargoCalculator(),
    TransactionPublicationApplier::class => fn (ContainerInterface $c) => new TransactionPublicationApplier(
        $c->get(TransactionRepositoryInterface::class),
        $c->get(PublicationEmbargoCalculator::class),
        new DateTimeImmutable('now'),
    ),
    TransactionSynchronizer::class => fn (ContainerInterface $c) => new TransactionSynchronizer(
        $c->get(KingdomRepositoryInterface::class),
        $c->get(AccountRepositoryInterface::class),
        $c->get(SecretRepositoryInterface::class),
        $c->get(TransactionRepositoryInterface::class),
        $c->get(LedgerProviderRegistry::class),
        $c->get(TokenCipher::class),
        new DateTimeImmutable('now'),
        $c->get(MonthInvalidator::class),
        $c->get(TransactionPublicationApplier::class),
    ),
    TellerWebhookVerifier::class => fn () => new TellerWebhookVerifier($_ENV['TELLER_WEBHOOK_SECRET'] ?? ''),
    ProviderWebhookHandler::class => fn (ContainerInterface $c) => new ProviderWebhookHandler(
        $c->get(LedgerProviderRegistry::class),
        $c->get(KingdomRepositoryInterface::class),
        new LedgerNoticeRegistry([
            new RefreshLedgerNotice($c->get(KingdomRefreshQueue::class)),
            new DisconnectLedgerNotice($c->get(EnrollmentService::class)),
        ]),
    ),
    PublicationPipelineFactory::class => fn () => PublicationPipelineFactory::standard(),
    KingdomPublicationLineSource::class => fn (ContainerInterface $c) => new KingdomPublicationLineSource(
        $c->get(TransactionRepositoryInterface::class),
        $c->get(AccountRepositoryInterface::class),
    ),
    StatementAbsenceClassifier::class => fn () => new StatementAbsenceClassifier(),
    KingdomPageQuery::class => fn (ContainerInterface $c) => new KingdomPageQuery(
        $c->get(KingdomPublicationLineSource::class),
        $c->get(PublicationPipelineFactory::class)->forPublicRead(),
        new MonthStatementBuilder($c->get(StatementPresenterRegistry::class)),
        $c->get(StatementAbsenceClassifier::class),
        new DateTimeImmutable('now'),
    ),
    ManagerKingdomPageQuery::class => fn (ContainerInterface $c) => new ManagerKingdomPageQuery(
        $c->get(KingdomPublicationLineSource::class),
        $c->get(PublicationPipelineFactory::class)->forManagerReview(),
        new MonthStatementBuilder($c->get(StatementPresenterRegistry::class)),
        new DateTimeImmutable('now'),
    ),
    MonthInvalidator::class => fn (RedisKeyValueStore $store) => new MonthInvalidator($store),
    MonthReader::class => fn (ContainerInterface $c) => new CachingMonthReader(
        $c->get(KingdomPageQuery::class),
        $c->get(RedisKeyValueStore::class),
    ),
    StatementPresenterRegistry::class => fn () => StatementPresenterRegistry::standard(),
    VisibilityPolicyRegistry::class => fn () => VisibilityPolicyRegistry::standard(),
    KingdomAccess::class => fn (VisibilityPolicyRegistry $policies) => new KingdomAccess($policies),
    KingdomSettings::class => fn (ContainerInterface $c) => new KingdomSettings(
        $c->get(KingdomRepositoryInterface::class),
        $c->get(PublicationSettingsValidator::class),
    ),
    TransactionReviewQueue::class => fn (ContainerInterface $c) => new TransactionReviewQueue(
        $c->get(KingdomPublicationLineSource::class),
        new DateTimeImmutable('now'),
    ),
    TransactionReviewService::class => fn (ContainerInterface $c) => new TransactionReviewService(
        $c->get(TransactionRepositoryInterface::class),
        $c->get(AccountRepositoryInterface::class),
        $c->get(MonthInvalidator::class),
        new DateTimeImmutable('now'),
    ),
    PrincipalSync::class => fn (PrincipalRepositoryInterface $principals) => new PrincipalSync($principals),
    PostCsrfMiddleware::class => fn () => new PostCsrfMiddleware(new Slim\Psr7\Factory\ResponseFactory()),
    SyncPrincipalMiddleware::class => fn (ContainerInterface $c) => new SyncPrincipalMiddleware(
        $c->get(SessionAuthStore::class),
        $c->get(PrincipalSync::class),
    ),
    MethodLog::class => function () {
        $debug = (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
        $handlers = [new JsonStderrHandler()];
        $root = dirname(__DIR__);
        $logFile = $_ENV['DENARIUS_METHOD_LOG'] ?? ($debug ? $root . '/logs/method-trace.jsonl' : '');
        if ($logFile !== '') {
            $dir = dirname($logFile);
            $canWrite = is_file($logFile)
                ? is_writable($logFile)
                : (is_dir($dir) && is_writable($dir));
            if ($canWrite) {
                $stream = @fopen($logFile, 'ab');
                if ($stream !== false) {
                    $handlers[] = new JsonStderrHandler($stream);
                }
            }
        }

        return new StderrMethodLog(
            new Logger('denarius', [new WhatFailureGroupHandler($handlers)]),
            $debug,
        );
    },
    StderrMethodLog::class => fn (ContainerInterface $c) => $c->get(MethodLog::class),
    CorrelationMiddleware::class => fn () => new CorrelationMiddleware(),
    TwigEnvironment::class => function () {
        $root = dirname(__DIR__);
        $twig = new TwigEnvironment(new FilesystemLoader($root . '/templates'), [
            'cache' => __DIR__ . '/cache/twig',
            'auto_reload' => true,
        ]);
        $twig->addGlobal('appVersion', BuildInfo::version($root));
        $twig->addFunction(new Twig\TwigFunction('csrf_token', static fn (): string => Amtgard\Denarius\Utilities\Http\CsrfToken::issue()));
        return $twig;
    },
    TwigHtmlRenderer::class => fn (TwigEnvironment $twig) => new TwigHtmlRenderer($twig),
    HomeController::class => fn (ContainerInterface $c) => new HomeController(
        $c->get(TwigHtmlRenderer::class),
        $c->get(SessionAuthStore::class),
        $c->get(AccountNavBuilder::class),
        dirname(__DIR__),
    ),
    KingdomPageController::class => fn (ContainerInterface $c) => new KingdomPageController(
        $c->get(KingdomRepositoryInterface::class),
        $c->get(MonthReader::class),
        $c->get(KingdomAccess::class),
        $c->get(SessionAuthStore::class),
        $c->get(TwigHtmlRenderer::class),
    ),
    IdpUserDirectory::class => function (ContainerInterface $c) {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars(),
            $c->get(LoggingIdpHttpClient::class),
            $psr17,
        );
    },
    AdminGrantTargetResolver::class => fn (ContainerInterface $c) => new AdminGrantTargetResolver(
        $c->get(IdpUserDirectory::class),
        $c->get(PrincipalRepositoryInterface::class),
        $c->get(PrincipalSync::class),
    ),
    AdminGrantedRoleIndex::class => fn (ContainerInterface $c) => new AdminGrantedRoleIndex(
        $c->get(RoleGrantRepositoryInterface::class),
        $c->get(PrincipalRepositoryInterface::class),
        $c->get(OrkKingdomDirectory::class),
    ),
    OrkKingdomDirectory::class => fn (ContainerInterface $c) => new OrkKingdomDirectory(
        dirname(__DIR__),
        $_ENV['ORK_KINGDOMS_CACHE'] ?? null,
        $c->get(KingdomRepositoryInterface::class),
        $c->get(PrincipalRepositoryInterface::class),
    ),
    AdminController::class => fn (ContainerInterface $c) => new AdminController(
        $c->get(SessionAuthStore::class),
        $c->get(PermissionService::class),
        $c->get(PrincipalRepositoryInterface::class),
        $c->get(KingdomRepositoryInterface::class),
        $c->get(PolicyGateway::class),
        $c->get(RoleGrantRepositoryInterface::class),
        $c->get(TwigHtmlRenderer::class),
        new AdminCommandRegistry([
            new GrantAdminCommand(),
            new RevokeAdminCommand(),
            new GrantManagerCommand(),
            new RevokeManagerCommand(),
        ]),
        $c->get(OrkKingdomDirectory::class),
        $c->get(AdminGrantTargetResolver::class),
        $c->get(AdminGrantedRoleIndex::class),
    ),
    ManagerController::class => fn (ContainerInterface $c) => new ManagerController(
        $c->get(SessionAuthStore::class),
        $c->get(PermissionService::class),
        $c->get(KingdomRepositoryInterface::class),
        $c->get(AccountRepositoryInterface::class),
        $c->get(KingdomSettings::class),
        $c->get(EnrollmentService::class),
        $c->get(KingdomRefreshQueue::class),
        $c->get(TwigHtmlRenderer::class),
        $c->get(BankConnect::class),
        $c->get(SimpleFinConnectSession::class),
        $c->get(TransactionReviewQueue::class),
        $c->get(TransactionReviewService::class),
    ),
    WebhookController::class => fn (ProviderWebhookHandler $handler) => new WebhookController($handler),
    LedgerWorker::class => fn (ContainerInterface $c) => new LedgerWorker(
        $c->get(MessageQueue::class),
        new RefreshJobRegistry([
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
