<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Contract\TellerApi;
use Amtgard\Denarius\Contract\TransactionStore;
use Amtgard\Denarius\Domain\Money;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Record\TransactionRecord;
use Amtgard\Denarius\Security\TokenCipher;

final class TransactionSynchronizer
{
    public function __construct(
        private readonly KingdomStore $kingdoms,
        private readonly AccountStore $accounts,
        private readonly SecretStore $secrets,
        private readonly TransactionStore $transactions,
        private readonly TellerApi $teller,
        private readonly TokenCipher $cipher,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function sync(int $orkKingdomId): bool
    {
        $kingdom = $this->kingdoms->findByOrkId($orkKingdomId);
        if ($kingdom === null || $kingdom->getEnrollmentStatus() !== 'connected' || $kingdom->getId() === null) {
            return false;
        }

        $ciphertext = $this->secrets->findCiphertext($kingdom->getId());
        if ($ciphertext === null) {
            return false;
        }

        $token = $this->cipher->decrypt($ciphertext);
        foreach ($this->accounts->forKingdom($kingdom->getId()) as $account) {
            if (!$account->getPublished()) {
                continue;
            }
            $this->pullAccount($kingdom, $token, $account->getTellerAccountId(), $account->getName());
        }

        $this->kingdoms->save(KingdomRecord::builder()
            ->id($kingdom->getId())
            ->orkKingdomId($kingdom->getOrkKingdomId())
            ->name($kingdom->getName())
            ->slug($kingdom->getSlug())
            ->visibility($kingdom->getVisibility())
            ->displayMode($kingdom->getDisplayMode())
            ->enrollmentId($kingdom->getEnrollmentId())
            ->institutionName($kingdom->getInstitutionName())
            ->enrollmentStatus($kingdom->getEnrollmentStatus())
            ->lastSyncedAt($this->now->format('c'))
            ->build());

        return true;
    }

    private function pullAccount(KingdomRecord $kingdom, string $token, string $accountId, string $accountName): void
    {
        $fromId = null;
        $seen = [];
        do {
            $page = $this->teller->transactions($token, $accountId, $fromId);
            if ($page === []) {
                return;
            }
            $lastId = null;
            foreach ($page as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $lastId = $id;
                $details = is_array($row['details'] ?? null) ? $row['details'] : [];
                $counterparty = is_array($details['counterparty'] ?? null) ? $details['counterparty'] : [];
                $this->transactions->upsert(TransactionRecord::builder()
                    ->kingdomId((int) $kingdom->getId())
                    ->tellerTransactionId($id)
                    ->tellerAccountId($accountId)
                    ->postedOn((string) ($row['date'] ?? ''))
                    ->amountCents(Money::centsFromDecimal((string) ($row['amount'] ?? '0')))
                    ->category((string) ($details['category'] ?? 'general'))
                    ->description((string) ($row['description'] ?? ''))
                    ->counterparty((string) ($counterparty['name'] ?? ''))
                    ->status((string) ($row['status'] ?? ''))
                    ->build());
            }
            if ($lastId === null || $lastId === $fromId) {
                return;
            }
            $fromId = $lastId;
        } while (true);
    }
}
