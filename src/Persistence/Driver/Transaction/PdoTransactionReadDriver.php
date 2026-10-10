<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Driver\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use PDO;

/** Driver: PDO implementation for kingdom transaction listing. */
final class PdoTransactionReadDriver implements TransactionReadDriver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionRecordMapper $mapper,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function listForKingdom(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            $statement = $this->pdo->prepare(
                'SELECT * FROM transactions WHERE kingdom_id = :kingdom_id ORDER BY posted_on ASC, id ASC',
            );
            $statement->bindValue(':kingdom_id', $kingdomId, PDO::PARAM_INT);
            $statement->execute();

            /** @var list<TransactionRecord> $rows */
            $rows = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $this->mapper->fromDatabaseRow($row);
            }

            return $rows;
        });
    }

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            $statement = $this->pdo->prepare(
                'SELECT * FROM transactions WHERE teller_transaction_id = :teller_transaction_id LIMIT 1',
            );
            $statement->bindValue(':teller_transaction_id', $tellerTransactionId);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (! is_array($row)) {
                return null;
            }

            return $this->mapper->fromDatabaseRow($row);
        });
    }
}
