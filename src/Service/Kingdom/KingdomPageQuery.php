<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
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
