<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Kingdom\KingdomRecordRebuilder;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Domain\Statement\Publication\Ingest\MicroDepositPairReconciler;
use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
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
        private readonly TransactionCategoryApplier $categories,
        private readonly TransactionPublicationApplier $publication,
        private readonly MicroDepositPairReconciler $microPairs,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function sync(int $orkKingdomId): bool
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $orkKingdomId): bool {
            $kingdom = $this->kingdoms->findByOrkId($orkKingdomId);
            if ($kingdom === null || $kingdom->getEnrollmentStatus() !== 'connected' || $kingdom->getId() === null) {
                return false;
            }

            $ciphertext = $this->secrets->findCiphertext($kingdom->getId());
            if ($ciphertext === null) {
                return false;
            }

            $backfillAmnesty = $kingdom->getInitialBackfillCompletedAt() === null;
            if ($backfillAmnesty) {
                DenariusLog::debugBranch('ledger_sync_backfill_amnesty', $method, [
                    'ork_kingdom_id' => $orkKingdomId,
                ]);
            }

            $token = $this->cipher->decrypt($ciphertext);
            $provider = $this->providers->find($this->providerId($kingdom));
            foreach ($this->accounts->forKingdom($kingdom->getId()) as $account) {
                if (!$account->getPublished()) {
                    continue;
                }
                $accountId = $account->getTellerAccountId();
                $this->pullAccount($provider, $kingdom, $token, $accountId, $backfillAmnesty);
                $this->microPairs->reconcileAccount($kingdom, $accountId);
            }

            $saved = KingdomRecordRebuilder::from($kingdom)
                ->lastSyncedAt($this->now->format('c'));
            if ($backfillAmnesty) {
                $saved = $saved->initialBackfillCompletedAt($this->now->format('c'));
                DenariusLog::infoBranch('ledger_backfill_completed', $method, [
                    'kingdom_id' => $kingdom->getId(),
                ]);
            }
            $this->kingdoms->save($saved->build());
            $this->months->forget((int) $kingdom->getId());

            return true;
        });
    }

    private function pullAccount(
        LedgerProvider $provider,
        KingdomRecord $kingdom,
        string $token,
        string $accountId,
        bool $backfillAmnesty,
    ): void {
        DenariusLog::trace(__METHOD__, function () use ($provider, $kingdom, $token, $accountId, $backfillAmnesty): mixed {
            $fromId = null;
            $seen = [];
            do {
                $page = $provider->transactions($token, $accountId, $fromId);
                if ($page === []) {
                    return null;
                }
                $lastId = $this->storePage($kingdom, $accountId, $page, $seen, $backfillAmnesty);
                if ($lastId === null || $lastId === $fromId) {
                    return null;
                }
                $fromId = $lastId;
            } while (true);
        });
    }

    /**
     * @param list<\Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction> $page
     * @param array<string, true> $seen
     */
    private function storePage(
        KingdomRecord $kingdom,
        string $accountId,
        array $page,
        array &$seen,
        bool $backfillAmnesty,
    ): ?string {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $accountId, $page, &$seen, $backfillAmnesty): ?string {
            $lastId = null;
            $fallbackCount = 0;
            foreach ($page as $row) {
                if ($row->id === '' || isset($seen[$row->id])) {
                    continue;
                }
                $seen[$row->id] = true;
                $lastId = $row->id;
                $incoming = $this->record($kingdom, $accountId, $row);
                $existing = $this->transactions->findByTellerTransactionId($incoming->getTellerTransactionId());
                $categorized = $this->categories->apply($kingdom, $incoming, $existing);
                if ($categorized->getCategory() === 'uncategorized'
                    && $categorized->getCategorySuggested() === null
                    && $categorized->getCategoryConfidence() === 0
                ) {
                    ++$fallbackCount;
                }
                $this->transactions->upsert($this->publication->apply($kingdom, $categorized, $backfillAmnesty));
            }
            if ($fallbackCount > 0) {
                DenariusLog::infoBranch('transaction_category_fallback', __METHOD__, [
                    'count' => $fallbackCount,
                ]);
            }

            return $lastId;
        });
    }

    private function providerId(KingdomRecord $kingdom): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): string {
            $stored = $kingdom->getProvider();

            return Optional::ofNullable($stored === null || $stored === '' ? null : $stored)
                ->orElse($this->providers->default()->id());
        });
    }

    private function record(KingdomRecord $kingdom, string $accountId, \Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction $row): TransactionRecord
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom, $accountId, $row): TransactionRecord {
            $hint = $row->category;
            if ($hint !== '') {
                DenariusLog::debugBranch('transaction_provider_hint_recorded', $method, [
                    'teller_transaction_id' => $row->id,
                ]);
            }

            return TransactionRecord::builder()
                ->kingdomId((int) $kingdom->getId())
                ->tellerTransactionId($row->id)
                ->tellerAccountId($accountId)
                ->postedOn($row->postedOn)
                ->amountCents(Money::centsFromDecimal($row->amount))
                ->category('uncategorized')
                ->providerCategory($hint === '' ? null : $hint)
                ->categorySource(CategorySource::Fallback->value)
                ->description($row->description)
                ->counterparty($row->counterparty)
                ->status($row->status)
                ->build();
        });
    }
}
