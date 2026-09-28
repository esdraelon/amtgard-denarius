<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Bank\AlwaysReady;
use Amtgard\Denarius\Bank\ConnectedEnrollment;
use Amtgard\Denarius\Bank\InstitutionSupport;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\LedgerProviderRegistry;
use Amtgard\Denarius\Bank\MissingLedgerProvider;
use Amtgard\Denarius\Bank\PresentCredentials;
use Amtgard\Denarius\Bank\ProviderNotice;
use Amtgard\Denarius\Bank\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Bank\Teller\TellerWebhookVerifier;
use Amtgard\PHPUnit\AmtgardTestCase;

final class ProviderRegistryTest extends AmtgardTestCase
{
    public function testVerdictsDistinguishARejectedBank(): void
    {
        $this->assertSame('yes', InstitutionSupport::yes()->verdict());
        $this->assertFalse(InstitutionSupport::yes()->rejected());
        $this->assertSame('unknown', InstitutionSupport::unknown()->verdict());
        $this->assertFalse(InstitutionSupport::unknown()->rejected());
        $this->assertSame('no', InstitutionSupport::no()->verdict());
        $this->assertTrue(InstitutionSupport::no()->rejected());
    }

    public function testCredentialsAreReadyOnlyWhenEveryValueIsPresent(): void
    {
        $this->assertTrue((new PresentCredentials([' app ']))->ready());
        $this->assertFalse((new PresentCredentials(['']))->ready());
        $this->assertFalse((new PresentCredentials(['   ']))->ready());
        $this->assertFalse((new PresentCredentials(['ok', '']))->ready());
        $this->assertFalse((new PresentCredentials([]))->ready());
        $this->assertTrue((new AlwaysReady())->ready());
    }

    public function testResolveSkipsRejectedAndSkippedProviders(): void
    {
        $stripe = new ScriptedProvider('stripe', ['Chase' => 'no']);
        $plaid = new ScriptedProvider('plaid', ['Chase' => 'yes']);
        $teller = new ScriptedProvider('teller', []);
        $registry = new LedgerProviderRegistry([$stripe, $plaid, $teller]);

        $this->assertSame('stripe', $registry->default()->id());
        $this->assertSame('plaid', $registry->resolve('Chase', [])->id());
        $this->assertSame('teller', $registry->resolve('Chase', ['plaid'])->id());
        $this->assertSame('stripe', $registry->resolve('First Bank', [])->id());
        $this->assertSame('plaid', $registry->resolve('First Bank', ['stripe'])->id());
        $this->assertSame('', $registry->resolve('Chase', ['plaid', 'teller'])->id());
        $this->assertSame('plaid', $registry->find('plaid')->id());
        $this->assertSame('', $registry->find('missing')->id());
        $this->assertSame([], $plaid->accounts('token'));
        $this->assertSame([], $plaid->transactions('token', 'acc', 'cursor'));
        $this->assertFalse($plaid->notice('{}', 'sig', 1)->accepted);
        $this->assertSame('plaid', $plaid->enrollment([])->enrollmentId);
    }

    public function testAnEmptyRegistryResolvesToTheMissingProvider(): void
    {
        $registry = new LedgerProviderRegistry([]);
        $missing = $registry->default();

        $this->assertInstanceOf(MissingLedgerProvider::class, $missing);
        $this->assertSame('', $missing->id());
        $this->assertSame('', $missing->signatureHeader());
        $this->assertTrue($missing->supports('Chase')->rejected());
        $this->assertSame([], $missing->connectConfig('golden-plains'));
        $this->assertSame([], $missing->accounts('token'));
        $this->assertSame([], $missing->transactions('token', 'acc', null));
        $this->assertFalse($missing->notice('{}', null, 1)->accepted);
        $this->expectException(\InvalidArgumentException::class);
        $missing->enrollment([]);
    }

    public function testTellerReportsUnknownCoverageWhenCredentialsArePresent(): void
    {
        $ready = new TellerLedgerProvider(new SparseTeller(), new TellerWebhookVerifier('whsec', 300), TellerLedgerProvider::actions(), new PresentCredentials(['app_test']), 'app_test', 'sandbox');
        $blank = new TellerLedgerProvider(new SparseTeller(), new TellerWebhookVerifier('whsec', 300), TellerLedgerProvider::actions(), new PresentCredentials(['']), 'app_test', 'development');

        $this->assertSame('teller', $ready->id());
        $this->assertSame('unknown', $ready->supports('Chase')->verdict());
        $this->assertTrue($ready->supports('')->rejected());
        $this->assertTrue($blank->supports('Chase')->rejected());
        $this->assertSame([
            'provider' => 'teller',
            'applicationId' => 'app_test',
            'environment' => 'sandbox',
            'kingdomKey' => 'golden-plains',
        ], $ready->connectConfig('golden-plains'));
    }
}

final class ScriptedProvider implements LedgerProvider
{
    /**
     * @param array<string, string> $verdicts
     */
    public function __construct(private readonly string $name, private readonly array $verdicts)
    {
    }

    public function id(): string
    {
        return $this->name;
    }

    public function signatureHeader(): string
    {
        return 'X-Test';
    }

    public function supports(string $institution): InstitutionSupport
    {
        $verdict = $this->verdicts[$institution] ?? 'unknown';
        if ($verdict === 'yes') {
            return InstitutionSupport::yes();
        }
        if ($verdict === 'no') {
            return InstitutionSupport::no();
        }

        return InstitutionSupport::unknown();
    }

    public function connectConfig(string $kingdomKey): array
    {
        return ['provider' => $this->name, 'kingdomKey' => $kingdomKey];
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        return new ConnectedEnrollment($this->name, $this->name, $this->name);
    }

    public function accounts(string $accessToken): array
    {
        return [];
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        return [];
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return ProviderNotice::rejected();
    }
}
