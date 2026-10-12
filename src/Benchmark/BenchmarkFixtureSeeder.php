<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use PDO;

/** Seeds synthetic benchmark rows (PDO — harness only, not production persistence). */
final class BenchmarkFixtureSeeder
{
    private const TELLER_PREFIX = 'bench-aaro-';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function purgeBenchRows(): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM transactions WHERE teller_transaction_id LIKE :prefix',
        );
        $statement->execute(['prefix' => self::TELLER_PREFIX . '%']);
    }

    public function ensureKingdomId(int $kingdomId): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM kingdoms WHERE id = ? LIMIT 1');
        $statement->execute([$kingdomId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Kingdom id ' . $kingdomId . ' not found; pick an existing kingdom.');
        }

        return (int) $id;
    }

    public function seedTransactions(int $kingdomId, int $targetCount): string
    {
        $this->purgeBenchRows();
        $categoryId = $this->uncategorizedCategoryId();
        $existing = $this->countForKingdom($kingdomId);
        $toInsert = max(0, $targetCount - $existing);
        if ($toInsert === 0) {
            return $this->sampleTellerIdForKingdom($kingdomId);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO transactions (
                kingdom_id, teller_transaction_id, teller_account_id, posted_on, amount_cents,
                category_id, description, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $sampleTeller = self::TELLER_PREFIX . $kingdomId . '-0';
        for ($i = 0; $i < $toInsert; ++$i) {
            $day = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);
            $insert->execute([
                $kingdomId,
                self::TELLER_PREFIX . $kingdomId . '-' . $i,
                'bench-acct',
                '2026-01-' . $day,
                -100 - $i,
                $categoryId,
                'Benchmark row ' . $i,
                'posted',
            ]);
        }

        return $sampleTeller;
    }

    private function uncategorizedCategoryId(): int
    {
        $id = $this->pdo->query(
            "SELECT current_category_id FROM category_lineages WHERE lineage_key = 'uncategorized' LIMIT 1",
        )->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('category_lineages missing uncategorized; run migrations.');
        }

        return (int) $id;
    }

    private function countForKingdom(int $kingdomId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE kingdom_id = ?');
        $statement->execute([$kingdomId]);

        return (int) $statement->fetchColumn();
    }

    private function sampleTellerIdForKingdom(int $kingdomId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT teller_transaction_id FROM transactions WHERE kingdom_id = ? ORDER BY id ASC LIMIT 1',
        );
        $statement->execute([$kingdomId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return self::TELLER_PREFIX . $kingdomId . '-0';
        }

        return (string) $id;
    }
}
