<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use PDO;

/** Removes local ledger rows so a kingdom can connect a bank again from a clean state. */
final class BankConnectionReset implements KingdomBankReset
{
    public function __construct(private readonly PDO $pdo)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function clearKingdom(int $kingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId): void {
            $stmt = $this->pdo->prepare('DELETE FROM transactions WHERE kingdom_id = ?');
            $stmt->execute([$kingdomId]);
            $stmt = $this->pdo->prepare('DELETE FROM published_accounts WHERE kingdom_id = ?');
            $stmt->execute([$kingdomId]);
            $stmt = $this->pdo->prepare('DELETE FROM enrollment_secrets WHERE kingdom_id = ?');
            $stmt->execute([$kingdomId]);
        });
    }
}
