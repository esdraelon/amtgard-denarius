<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionSynchronizerSyncStatusTest extends AmtgardTestCase
{
    public function testSyncRecordsSucceededStatusOnHappyPath(): void
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $secrets = new MemorySecrets();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(8)->name('Wetlands')->slug('wetlands')->enrollmentStatus('connected')->build());
        $kingdomId = (int) $kingdom->getId();
        $cipher = new TokenCipher('k');
        $secrets->saveCiphertext($kingdomId, $cipher->encrypt('token'));
        $accounts->save(AccountRecord::builder()->kingdomId($kingdomId)->tellerAccountId('acc')->name('Checking')->published(true)->build());

        $sync = Strategies::synchronizer(
            $kingdoms,
            $accounts,
            $secrets,
            $transactions,
            Strategies::providers(Strategies::teller()),
            $cipher,
            new \DateTimeImmutable('2026-10-07T12:00:00+00:00'),
            Strategies::months(),
        );

        $this->assertTrue($sync->sync(8));
        $saved = $kingdoms->findByOrkId(8);
        $this->assertNotNull($saved);
        $this->assertSame('succeeded', $saved->getLastSyncStatus());
        $this->assertSame('2026-10-07T12:00:00+00:00', $saved->getLastSyncAttemptedAt());
    }

    public function testSyncRecordsFailedStatusWhenDecryptFails(): void
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $secrets = new MemorySecrets();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(8)->name('Wetlands')->slug('wetlands')->enrollmentStatus('connected')->build());
        $kingdomId = (int) $kingdom->getId();
        $secrets->saveCiphertext($kingdomId, 'not-valid-ciphertext');
        $accounts->save(AccountRecord::builder()->kingdomId($kingdomId)->tellerAccountId('acc')->name('Checking')->published(true)->build());

        $sync = Strategies::synchronizer(
            $kingdoms,
            $accounts,
            $secrets,
            $transactions,
            Strategies::providers(Strategies::teller()),
            new TokenCipher('k'),
            new \DateTimeImmutable('2026-10-07T12:00:00+00:00'),
            Strategies::months(),
        );

        try {
            $sync->sync(8);
            $this->fail('Expected decrypt failure.');
        } catch (\Throwable) {
        }

        $saved = $kingdoms->findByOrkId(8);
        $this->assertNotNull($saved);
        $this->assertSame('failed', $saved->getLastSyncStatus());
        $this->assertNotSame('', (string) $saved->getLastSyncError());
    }
}
