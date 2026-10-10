<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationMonthBalanceTest extends AmtgardTestCase
{
    /**
     * @return list<DisplayMode>
     */
    private function publicModes(): array
    {
        return [
            DisplayMode::Redacted,
            DisplayMode::LessRedacted,
            DisplayMode::Summarized,
        ];
    }

    public function testPublicStatementCoarsensOpeningAndClosingBalancesForEachMode(): void
    {
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(1)
            ->name('Test')
            ->slug('test')
            ->displayMode('redacted')
            ->balanceQuantumFloorCents(500)
            ->balanceQuantumCeilingCents(500)
            ->amountQuantumCents(PublicationPlatformLimits::MIN_AMOUNT_QUANTUM_CENTS)
            ->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(AccountRecord::builder()->kingdomId($kingdomId)->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $uncategorized = CategoryCatalogFixture::id('uncategorized');
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('prior')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-30')
            ->amountCents(10_000)
            ->categoryId($uncategorized)
            ->publishedAt('2026-08-31T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('sep')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-523)
            ->categoryId($uncategorized)
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->publishedAt('2026-09-03T00:00:00+00:00')
            ->build());

        $query = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $month = new MonthWindow(2026, 9);

        foreach ($this->publicModes() as $mode) {
            $statement = $query->statementForMode($kingdom, $month, $mode);
            self::assertSame(10_000, $statement->openingBalanceCents, $mode->value);
            self::assertSame(9_500, $statement->closingBalanceCents, $mode->value);
        }
    }
}
