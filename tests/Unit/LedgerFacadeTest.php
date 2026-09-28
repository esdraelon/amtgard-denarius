<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Providers\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Enrollment\ConnectedEnrollment;
use Amtgard\Denarius\Domain\Bank\Providers\Support\InstitutionSupport;
use Amtgard\Denarius\Domain\Bank\Providers\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderAccount;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction;
use Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerApi;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerLedgerProvider;
use Amtgard\PHPUnit\AmtgardTestCase;

final class LedgerFacadeTest extends AmtgardTestCase
{
    public function testANonTellerProviderDrivesEnrollmentAndSync(): void
    {
        $kingdoms = new MemoryKingdoms();
        $secrets = new MemorySecrets();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $queue = new MemoryRefresh();
        $cipher = new TokenCipher('app-key');
        $provider = new class implements LedgerProvider {
            public function id(): string
            {
                return 'other';
            }

            public function signatureHeader(): string
            {
                return 'X-Bank-Signature';
            }

            public function supports(string $institution): InstitutionSupport
            {
                return InstitutionSupport::yes();
            }

            public function connectConfig(string $kingdomKey): array
            {
                return ['provider' => 'other', 'kingdomKey' => $kingdomKey];
            }

            public function enrollment(array $payload): ConnectedEnrollment
            {
                return new ConnectedEnrollment('other-token', 'ext-1', 'Other Bank');
            }

            public function accounts(string $accessToken): array
            {
                return [new ProviderAccount('acct', 'Operating', 'checking', '9999')];
            }

            public function transactions(string $accessToken, string $accountId, ?string $cursor): array
            {
                if ($cursor === 'txn') {
                    return [];
                }

                return [
                    new ProviderTransaction('', '2026-09-01', '0', 'general', '', '', ''),
                    new ProviderTransaction('txn', '2026-09-02', '-3.25', 'food', 'lunch', 'Cafe', 'posted'),
                ];
            }

            public function notice(string $body, ?string $signature, int $now): ProviderNotice
            {
                return ProviderNotice::of('ext-1', ProviderNotice::REFRESH);
            }
        };

        $kingdom = $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(8)
            ->name('Wetlands')
            ->slug('wetlands')
            ->build());
        $enrollment = new EnrollmentService($kingdoms, $secrets, $accounts, Strategies::providers($provider), $cipher, $queue, Strategies::months());
        $connected = $enrollment->connect($kingdom, ['opaque' => true]);
        $this->assertSame('ext-1', $connected->getEnrollmentId());
        $this->assertSame('Other Bank', $connected->getInstitutionName());
        $this->assertSame('other-token', $cipher->decrypt((string) $secrets->findCiphertext((int) $connected->getId())));
        $this->assertSame('9999', $accounts->forKingdom((int) $connected->getId())[0]->getLastFour());

        $sync = new TransactionSynchronizer(
            $kingdoms,
            $accounts,
            $secrets,
            $transactions,
            Strategies::providers($provider),
            $cipher,
            new \DateTimeImmutable('2026-09-01'),
            Strategies::months(),
        );
        $this->assertTrue($sync->sync(8));
        $this->assertCount(1, $transactions->forKingdom((int) $connected->getId()));

        $handler = new ProviderWebhookHandler(Strategies::providers($provider), $kingdoms, Strategies::events($queue, $enrollment));
        $this->assertSame('X-Bank-Signature', $handler->signatureHeader('other'));
        $before = count($queue->ledger);
        $this->assertTrue($handler->handle('other', '{}', null, 1));
        $this->assertSame($before + 1, count($queue->ledger));
    }

    public function testTellerAdapterMapsSparseRowsAndUnknownNotices(): void
    {
        $provider = new TellerLedgerProvider(new SparseTeller(), new \Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerWebhookVerifier('whsec', 300), TellerLedgerProvider::actions(), new AlwaysReady(), 'app_test', 'sandbox');
        $this->assertSame('Teller-Signature', $provider->signatureHeader());

        $accounts = $provider->accounts('token');
        $this->assertCount(2, $accounts);
        $this->assertNull($accounts[0]->lastFour);
        $this->assertSame('Account', $accounts[0]->name);
        $this->assertNull($accounts[1]->lastFour);

        $rows = $provider->transactions('token', 'acc', null);
        $this->assertCount(2, $rows);
        $this->assertSame('general', $rows[0]->category);
        $this->assertSame('', $rows[0]->counterparty);
        $this->assertSame('', $rows[1]->counterparty);

        $kingdoms = new MemoryKingdoms();
        $secrets = new MemorySecrets();
        $stored = new MemoryAccounts();
        $queue = new MemoryRefresh();
        $enrollment = new EnrollmentService($kingdoms, $secrets, $stored, Strategies::providers($provider), new TokenCipher('k'), $queue, Strategies::months());
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(3)->name('Celestial')->slug('celestial')->build());
        $connected = $enrollment->connect($kingdom, ['accessToken' => 'token', 'id' => 'enr_sparse']);
        $handler = new ProviderWebhookHandler(Strategies::providers($provider), $kingdoms, Strategies::events($queue, $enrollment));
        $now = 1_700_000_000;
        $ignored = json_encode(['type' => 'account.updated', 'enrollment_id' => 'enr_sparse'], JSON_THROW_ON_ERROR);
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $ignored, 'whsec');
        $this->assertTrue($handler->handle('teller', $ignored, $signature, $now));
        $this->assertSame('connected', $kingdoms->findByEnrollmentId('enr_sparse')->getEnrollmentStatus());

        $scalar = '1';
        $scalarSig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $scalar, 'whsec');
        $this->assertFalse($handler->handle('teller', $scalar, $scalarSig, $now));
        $this->assertSame('enr_sparse', $connected->getEnrollmentId());
    }
}

final class SparseTeller implements TellerApi
{
    public function accounts(string $accessToken): array
    {
        return [
            ['id' => ''],
            ['id' => 'acc_blank', 'last_four' => ''],
            ['id' => 'acc_num', 'last_four' => 12],
        ];
    }

    public function transactions(string $accessToken, string $accountId, ?string $fromId): array
    {
        return [
            ['id' => ''],
            ['id' => 'txn_plain'],
            ['id' => 'txn_bad', 'details' => 'nope'],
        ];
    }
}
