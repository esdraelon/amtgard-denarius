<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Log\IdpHttpTrafficLog;
use Amtgard\Denarius\Utilities\Log\RedactingContext;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;
use Amtgard\PHPUnit\AmtgardTestCase;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class IdpHttpTrafficLogTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    /** @var array<string, string> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
        MethodLogAssert::reset();
        class_exists(ApplicationTest::class);
        foreach (['APP_DEBUG', 'DENARIUS_IDP_HTTP_LOG', 'DENARIUS_IDP_HTTP_LOG_PLAINTEXT'] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testExchangeBranchIncludesPlaintextAuthorizationInDev(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG_PLAINTEXT'] = 'true';
        $request = new Request(
            'GET',
            'https://idp.example.test/resources/client/users/by-email?email=a%40b.c',
            ['Authorization' => ['Basic c2VjcmV0Og=='], 'Accept' => ['application/json']],
            '{"ignored":true}',
        );
        IdpHttpTrafficLog::record($request, new Response(403, [], '<html>cf</html>'));
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            IdpHttpTrafficLog::BRANCH,
            IdpHttpTrafficLog::LOG_METHOD,
        );
        $branch = $this->recorder->branches()[0];
        $this->assertSame('Basic c2VjcmV0Og==', $branch['context']['request']['headers']['Authorization'][0]);
    }

    public function testExchangeContextRedactsWhenPlaintextDisabled(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG_PLAINTEXT'] = 'false';
        $context = [
            'request' => [
                'headers' => ['Authorization' => ['Basic abc']],
            ],
        ];
        $redacted = RedactingContext::redact($context, IdpHttpTrafficLog::BRANCH);
        $this->assertSame('[redacted]', $redacted['request']['headers']['Authorization']);
    }

    public function testLoggingClientRecordsExchange(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG'] = 'true';
        $inner = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{"idp_user_id":"uuid"}');
            }
        };
        $client = new LoggingIdpHttpClient($inner);
        $client->sendRequest(new Request('GET', 'https://idp.example.test/x'));
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            IdpHttpTrafficLog::BRANCH,
            IdpHttpTrafficLog::LOG_METHOD,
        );
    }

    public function testStderrJsonKeepsAuthorizationWhenPlaintextEnabled(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG_PLAINTEXT'] = 'true';
        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), true);
        $log->branch(BranchLogLevel::Debug, IdpHttpTrafficLog::BRANCH, IdpHttpTrafficLog::LOG_METHOD, [
            'request' => ['headers' => ['Authorization' => ['Basic xyz']]],
        ]);
        $payload = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Basic xyz'], $payload['context']['request']['headers']['Authorization']);
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
