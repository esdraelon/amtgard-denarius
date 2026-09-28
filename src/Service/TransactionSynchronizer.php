<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Repository\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\SecretRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\Money;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Optional\Optional;

final class TransactionSynchronizer
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly AccountRepositoryInterface $accounts,
        private readonly SecretRepositoryInterface $secrets,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly LedgerProviderRegistry $providers,
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
        $provider = $this->providers->find($this->providerId($kingdom));
        foreach ($this->accounts->forKingdom($kingdom->getId()) as $account) {
            if (!$account->getPublished()) {
                continue;
            }
            $this->pullAccount($provider, $kingdom, $token, $account->getTellerAccountId(), $account->getName());
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
            ->provider($kingdom->getProvider())
            ->enrollmentStatus($kingdom->getEnrollmentStatus())
            ->lastSyncedAt($this->now->format('c'))
            ->build());
        $this->months->forget((int) $kingdom->getId());

        return true;
    }

    private function pullAccount(LedgerProvider $provider, KingdomRecord $kingdom, string $token, string $accountId, string $accountName): void
    {
        $fromId = null;
        $seen = [];
        do {
            $page = $provider->transactions($token, $accountId, $fromId);
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

    private function providerId(KingdomRecord $kingdom): string
    {
        $stored = $kingdom->getProvider();

        return Optional::ofNullable($stored === null || $stored === '' ? null : $stored)
            ->orElse($this->providers->default()->id());
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
