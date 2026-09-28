<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Contract\TransactionStore;
use Amtgard\Denarius\Domain\Money;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Record\TransactionRecord;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\Month\MonthInvalidator;

final class TransactionSynchronizer
{
    public function __construct(
        private readonly KingdomStore $kingdoms,
        private readonly AccountStore $accounts,
        private readonly SecretStore $secrets,
        private readonly TransactionStore $transactions,
        private readonly LedgerProvider $provider,
        private readonly TokenCipher $cipher,
        private readonly \DateTimeImmutable $now,
        private readonly MonthInvalidator $months,
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
        $this->months->forget((int) $kingdom->getId());

        return true;
    }

    private function pullAccount(KingdomRecord $kingdom, string $token, string $accountId, string $accountName): void
    {
        $fromId = null;
        $seen = [];
        do {
            $page = $this->provider->transactions($token, $accountId, $fromId);
            if ($page === []) {
                return;
            }
            $lastId = $this->storePage($kingdom, $accountId, $page, $seen);
            if ($lastId === null || $lastId === $fromId) {
                return;
            }
            $fromId = $lastId;
        } while (true);
    }

    /**
     * @param list<\Amtgard\Denarius\Bank\ProviderTransaction> $page
     * @param array<string, true> $seen
     */
    private function storePage(KingdomRecord $kingdom, string $accountId, array $page, array &$seen): ?string
    {
        $lastId = null;
        foreach ($page as $row) {
            if ($row->id === '' || isset($seen[$row->id])) {
                continue;
            }
            $seen[$row->id] = true;
            $lastId = $row->id;
            $this->transactions->upsert($this->record($kingdom, $accountId, $row));
        }

        return $lastId;
    }

    private function record(KingdomRecord $kingdom, string $accountId, \Amtgard\Denarius\Bank\ProviderTransaction $row): TransactionRecord
    {
        return TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId($row->id)
            ->tellerAccountId($accountId)
            ->postedOn($row->postedOn)
            ->amountCents(Money::centsFromDecimal($row->amount))
            ->category($row->category)
            ->description($row->description)
            ->counterparty($row->counterparty)
            ->status($row->status)
            ->build();
    }
}
