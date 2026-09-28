<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Persistence\Repository\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\LedgerLine;
use Amtgard\Denarius\Domain\MonthStatement;
use Amtgard\Denarius\Domain\MonthStatementBuilder;
use Amtgard\Denarius\Domain\MonthWindow;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Service\Month\MonthReader;

final class KingdomPageQuery implements MonthReader
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly MonthStatementBuilder $builder,
    ) {
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        $names = [];
        foreach ($this->accounts->forKingdom((int) $kingdom->getId()) as $account) {
            if ($account->getPublished()) {
                $names[$account->getTellerAccountId()] = $account->getName();
            }
        }

        $lines = [];
        foreach ($this->transactions->forKingdom((int) $kingdom->getId()) as $transaction) {
            if (!isset($names[$transaction->getTellerAccountId()])) {
                continue;
            }
            $lines[] = LedgerLine::builder()
                ->postedOn($transaction->getPostedOn())
                ->amountCents($transaction->getAmountCents())
                ->category($transaction->getCategory())
                ->description($transaction->getDescription())
                ->counterparty($transaction->getCounterparty())
                ->status($transaction->getStatus())
                ->accountName($names[$transaction->getTellerAccountId()])
                ->build();
        }

        return $this->builder->build($lines, DisplayMode::fromStored($kingdom->getDisplayMode()), $month);
    }
}
