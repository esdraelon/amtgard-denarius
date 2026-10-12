<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: clear kingdom ledger rows via AARO repositories before reconnecting a bank. */
final class BankConnectionReset implements KingdomBankReset
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly SecretRepositoryInterface $secrets,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function clearKingdom(int $kingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId): void {
            $this->transactions->removeAllForKingdom($kingdomId);
            $this->accounts->removeAllForKingdom($kingdomId);
            $this->secrets->removeForKingdom($kingdomId);
        });
    }
}
