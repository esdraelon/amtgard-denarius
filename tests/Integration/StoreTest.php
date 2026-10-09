<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

use Amtgard\Denarius\Persistence\Repository\Account\Impl\AccountRepository;
use Amtgard\Denarius\Persistence\Repository\Kingdom\Impl\KingdomRepository;
use Amtgard\Denarius\Persistence\Repository\Principal\Impl\PrincipalRepository;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\Impl\RoleGrantRepository;
use Amtgard\Denarius\Persistence\Repository\Secret\Impl\SecretRepository;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\TransactionRepository;
use Amtgard\Denarius\Tests\Support\PersistenceStoreArrange;
use Amtgard\PHPUnit\AmtgardTestCase;
use PDO;

final class StoreTest extends AmtgardTestCase
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

    public function testRepositoriesRoundTripAuditedKingdoms(): void
    {
        $this->assertSame('kingdoms', KingdomRepository::getTableName());
        $this->assertSame(KingdomRepository::class, AccountRepository::getTableName() === 'published_accounts' ? KingdomRepository::class : '');
        $this->assertSame('principals', PrincipalRepository::getTableName());
        $this->assertSame('enrollment_secrets', SecretRepository::getTableName());
        $this->assertSame('transactions', TransactionRepository::getTableName());
        $this->assertSame('role_grants', RoleGrantRepository::getTableName());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\KingdomEntity::class, KingdomRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\AccountEntity::class, AccountRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\PrincipalEntity::class, PrincipalRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\SecretEntity::class, SecretRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\TransactionEntity::class, TransactionRepository::getEntityClass());
        $this->assertSame(\Amtgard\Denarius\Persistence\Entity\RoleGrantEntity::class, RoleGrantRepository::getEntityClass());

        $pdo = self::$pdo;
        PersistenceStoreArrange::exerciseRepositories();

        $this->assertSame('golden-plains', $pdo->query('SELECT slug FROM kingdoms LIMIT 1')->fetchColumn());
        $audit = $pdo->query('SELECT COUNT(*) FROM kingdoms_audit')->fetchColumn();
        $this->assertGreaterThan(0, (int) $audit);
        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM published_accounts_audit')->fetchColumn());
        $this->assertSame('cipher-2', $pdo->query('SELECT ciphertext FROM enrollment_secrets LIMIT 1')->fetchColumn());
        $this->assertSame('RENT', $pdo->query("SELECT provider_category FROM transactions WHERE teller_transaction_id = 'txn-rent'")->fetchColumn());
        $this->assertSame(70, (int) $pdo->query("SELECT category_confidence FROM transactions WHERE teller_transaction_id = 'txn-rent'")->fetchColumn());
        $this->assertSame(-200, (int) $pdo->query("SELECT amount_cents FROM transactions WHERE teller_transaction_id = 'txn-grocery'")->fetchColumn());
        $this->assertNull($pdo->query("SELECT publication_flags FROM transactions WHERE teller_transaction_id = 'txn-rent'")->fetchColumn());
        $groceryFlags = (string) $pdo->query("SELECT publication_flags FROM transactions WHERE teller_transaction_id = 'txn-grocery'")->fetchColumn();
        $this->assertTrue(\Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags::parse($groceryFlags)->isManagerEmbargoWaived());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM role_grants')->fetchColumn());
    }
}
