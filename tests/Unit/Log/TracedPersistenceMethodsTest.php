<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\PersistenceStoreArrange;
use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Tests\Unit\MemoryTransactions;
use Amtgard\PHPUnit\AmtgardTestCase;
use PDO;

final class TracedPersistenceMethodsTest extends AmtgardTestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = PersistenceStoreArrange::tryPdo();
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MariaDB is not available.');
        }
        PersistenceStoreArrange::migrateFresh(self::$pdo);
    }

    public function testEveryPersistenceTraceSiteIsAsserted(): void
    {
        MethodLogAssert::resetTraces();
        class_exists(ApplicationTest::class);

        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->visibility('public')
            ->displayMode('all')
            ->enrollmentStatus('connected')
            ->build());
        $kingdom->view();

        AccountRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerAccountId('acc')
            ->name('Checking')
            ->type('depository')
            ->published(true)
            ->build()
            ->view();

        PrincipalRecord::builder()
            ->idpUserId('9')
            ->email('person@example.com')
            ->orkKingdomId(4)
            ->orkKingdomName('Golden Plains')
            ->build()
            ->view();

        RoleGrantRecord::builder()
            ->actorIdpUserId('15')
            ->targetIdpUserId('9')
            ->action('grant')
            ->resource('Denarius/Admin')
            ->createdAt('2026-09-01T00:00:00+00:00')
            ->build();

        TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('txn')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('office'))
            ->build();

        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(100)->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('office'))->build());

        PersistenceStoreArrange::exerciseRepositories();

        $scope = $this->methodsInScope();
        $this->assertCount(96, $scope);
        foreach ($scope as $method) {
            if (str_ends_with($method, '::__construct')) {
                MethodLogAssert::assertConstructorEntered($method);
            } else {
                MethodLogAssert::assertTraced($method);
            }
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function methodsInScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if (str_contains($method, '\\Persistence\\')) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }
}
