<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\MethodLogRecorder;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\CorrelationMiddleware;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\LogChannel;
use Amtgard\Denarius\Utilities\Log\RedactingContext;
use Amtgard\Denarius\Utilities\Log\RequestLogContext;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;
use Amtgard\PHPUnit\AmtgardTestCase;
use LogicException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class LoggingCoreTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        $active = MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
        RequestLogContext::reset();
    }

    protected function tearDown(): void
    {
        RequestLogContext::reset();
        DenariusLog::install($this->recorder);
    }

    public function testFacadeRequiresInstall(): void
    {
        $property = (new \ReflectionClass(DenariusLog::class))->getProperty('logger');
        $property->setValue(null, null);
        $this->assertThrows(LogicException::class, static fn () => DenariusLog::trace('m', static fn () => 1));
        $this->assertThrows(LogicException::class, static fn () => DenariusLog::enter('m'));
        DenariusLog::install($this->recorder);
    }

    public function testTraceReturnsValueAndRecordsLeave(): void
    {
        $value = DenariusLog::trace('Amtgard\\Denarius\\Controller\\HomeController::home', static fn (): int => 7);
        $this->assertSame(7, $value);
        $this->assertSame(['Amtgard\\Denarius\\Controller\\HomeController::home'], $this->recorder->entered());
        $this->assertSame(['Amtgard\\Denarius\\Controller\\HomeController::home'], $this->recorder->left());
        $this->assertSame([], $this->recorder->failed());
        $this->assertSame([], $this->recorder->openMethods());
    }

    public function testTraceRecordsFailAndRethrows(): void
    {
        $this->assertThrows(RuntimeException::class, static function (): void {
            DenariusLog::trace('failing', static function (): void {
                throw new RuntimeException('boom');
            });
        });
        $this->assertSame(['failing'], $this->recorder->entered());
        $this->assertSame([], $this->recorder->left());
        $this->assertSame(['failing'], $this->recorder->failed());
        $this->assertSame([], $this->recorder->openMethods());
    }

    public function testEnterBalancesImmediately(): void
    {
        $token = DenariusLog::enter('Ctor::construct');
        $this->assertSame('Ctor::construct', $token);
        $this->assertSame(['Ctor::construct'], $this->recorder->entered());
        $this->assertSame(['Ctor::construct'], $this->recorder->left());
        $this->assertSame([], $this->recorder->openMethods());
    }

    public function testRecorderOpenDuringBody(): void
    {
        $open = null;
        $this->recorder->trace('m', function () use (&$open): void {
            $open = $this->recorder->openMethods();
        });
        $this->assertSame(['m'], $open);
        $this->assertSame([], $this->recorder->openMethods());
    }

    public function testRequestLogContext(): void
    {
        $this->assertNull(RequestLogContext::id());
        RequestLogContext::set('req-12345678');
        $this->assertSame('req-12345678', RequestLogContext::id());
        RequestLogContext::reset();
        $this->assertNull(RequestLogContext::id());
    }

    public function testLogChannelMapping(): void
    {
        $this->assertSame('http', LogChannel::fromMethod('Amtgard\\Denarius\\Controller\\HomeController::home'));
        $this->assertSame('http', LogChannel::fromMethod('Amtgard\\Denarius\\Utilities\\Http\\JsonBody::decode'));
        $this->assertSame('auth', LogChannel::fromMethod('Amtgard\\Denarius\\Utilities\\Auth\\CurrentActor::id'));
        $this->assertSame('auth', LogChannel::fromMethod('Amtgard\\Denarius\\Domain\\Access\\KingdomAccess::allow'));
        $this->assertSame('auth', LogChannel::fromMethod('Amtgard\\Denarius\\Service\\Access\\PermissionService::can'));
        $this->assertSame('ledger', LogChannel::fromMethod('Amtgard\\Denarius\\Domain\\Bank\\Provider\\Framework\\LedgerProvider::id'));
        $this->assertSame('ledger', LogChannel::fromMethod('Amtgard\\Denarius\\Service\\Ledger\\TransactionSynchronizer::sync'));
        $this->assertSame('ledger', LogChannel::fromMethod('Amtgard\\Denarius\\Service\\Enrollment\\EnrollmentService::connect'));
        $this->assertSame('ledger', LogChannel::fromMethod('Amtgard\\Denarius\\Worker\\LedgerWorker::run'));
        $this->assertSame('app', LogChannel::fromMethod('Amtgard\\Denarius\\Utilities\\Security\\TokenCipher::seal'));
    }

    public function testRedactingContextCopiesAndRedactsNested(): void
    {
        $input = [
            'user' => 'a',
            'password' => 'secret',
            'nested' => [
                'TOKEN' => 't',
                'ok' => 1,
                'deeper' => ['refresh_token' => 'r', 'keep' => true],
            ],
            'Authorization' => 'Bearer x',
            'cookie' => 'c',
            'client_secret' => 'cs',
            'access_token' => 'at',
            'Secret' => 's',
        ];
        $redacted = RedactingContext::redact($input);
        $this->assertSame('a', $redacted['user']);
        $this->assertSame('[redacted]', $redacted['password']);
        $this->assertSame('[redacted]', $redacted['nested']['TOKEN']);
        $this->assertSame(1, $redacted['nested']['ok']);
        $this->assertSame('[redacted]', $redacted['nested']['deeper']['refresh_token']);
        $this->assertTrue($redacted['nested']['deeper']['keep']);
        $this->assertSame('[redacted]', $redacted['Authorization']);
        $this->assertSame('secret', $input['password']);
        $this->assertSame('t', $input['nested']['TOKEN']);
    }

    public function testStderrDebugWritesEnterLeaveFail(): void
    {
        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), true);
        RequestLogContext::set('abcd1234efgh5678');
        $result = $log->trace('Amtgard\\Denarius\\Controller\\HomeController::home', static fn (): string => 'ok');
        $this->assertSame('ok', $result);
        $this->assertCount(2, $lines);
        $enter = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $leave = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('enter', $enter['event']);
        $this->assertSame('leave', $leave['event']);
        $this->assertSame('http', $enter['channel']);
        $this->assertSame('abcd1234efgh5678', $enter['request_id']);
        $this->assertSame([], $enter['context']);

        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), true);
        try {
            $log->trace('m', static function (): void {
                throw new RuntimeException('x');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('fail', json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR)['event']);
    }

    public function testStderrNonDebugWritesFailOnly(): void
    {
        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), false);
        $this->assertSame(1, $log->trace('m', static fn (): int => 1));
        $this->assertSame([], $lines);

        try {
            $log->trace('m', static function (): void {
                throw new RuntimeException('x');
            });
        } catch (RuntimeException) {
        }
        $this->assertCount(1, $lines);
        $this->assertSame('fail', json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['event']);
    }

    public function testStderrEnterAndHandlerFailureSwallowed(): void
    {
        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), true);
        $this->assertSame('Ctor', $log->enter('Ctor'));
        $this->assertSame('enter', json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['event']);

        $failing = new class extends AbstractProcessingHandler {
            protected function write(LogRecord $record): void
            {
                throw new RuntimeException('handler down');
            }
        };
        $log = StderrMethodLog::withHandler($failing, true);
        $this->assertSame(2, $log->trace('m', static fn (): int => 2));
    }

    public function testCorrelationMiddlewareAcceptsAndRejectsRequestId(): void
    {
        $middleware = new CorrelationMiddleware();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $good = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('X-Request-Id', 'valid_id-1');
        $middleware->process($good, $handler);
        $this->assertSame('valid_id-1', RequestLogContext::id());

        $bad = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('X-Request-Id', 'bad id');
        $middleware->process($bad, $handler);
        $generated = RequestLogContext::id();
        $this->assertNotNull($generated);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $generated);

        $short = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('X-Request-Id', 'short');
        $middleware->process($short, $handler);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) RequestLogContext::id());

        $missing = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $middleware->process($missing, $handler);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) RequestLogContext::id());
    }

    public function testStderrCreateUsesEnv(): void
    {
        $previous = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';
        $log = StderrMethodLog::create();
        $this->assertInstanceOf(StderrMethodLog::class, $log);
        $_ENV['APP_DEBUG'] = 'true';
        $this->assertInstanceOf(StderrMethodLog::class, StderrMethodLog::create(true));
        if ($previous === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $previous;
        }
    }

    public function testJsonStderrHandlerDefaultStreamUsesGlobalStderr(): void
    {
        $this->assertInstanceOf(
            \Amtgard\Denarius\Utilities\Log\JsonStderrHandler::class,
            new \Amtgard\Denarius\Utilities\Log\JsonStderrHandler(),
        );
    }

    public function testJsonStderrHandlerWritesLine(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        $handler = new \Amtgard\Denarius\Utilities\Log\JsonStderrHandler($stream);
        $log = StderrMethodLog::withHandler($handler, true);
        $log->enter('Handled');
        rewind($stream);
        $written = stream_get_contents($stream);
        $this->assertIsString($written);
        $this->assertStringContainsString('"event":"enter"', $written);
        fclose($stream);
    }

    /** @param list<string> $lines */
    private function collectingHandler(array &$lines): AbstractProcessingHandler
    {
        return new class($lines) extends AbstractProcessingHandler {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
                parent::__construct();
            }

            protected function write(LogRecord $record): void
            {
                $this->lines[] = $record->message;
            }
        };
    }
}
