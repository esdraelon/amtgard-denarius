<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Record\AccountRecord;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\Month\MonthInvalidator;

final class EnrollmentService
{
    public function __construct(
        private readonly KingdomStore $kingdoms,
        private readonly SecretStore $secrets,
        private readonly AccountStore $accounts,
        private readonly LedgerProvider $provider,
        private readonly TokenCipher $cipher,
        private readonly KingdomRefreshQueue $queue,
        private readonly MonthInvalidator $months,
    ) {
    }

    /**
     * @param array<string, mixed> $enrollment
     */
    public function connect(KingdomRecord $kingdom, array $enrollment): KingdomRecord
    {
        $connected = $this->provider->enrollment($enrollment);
        $this->secrets->saveCiphertext((int) $kingdom->getId(), $this->cipher->encrypt($connected->accessToken));

        $saved = $this->kingdoms->save($this->copy($kingdom, $connected->enrollmentId, $connected->institutionName, 'connected'));
        $this->importAccounts($saved, $connected->accessToken);
        $this->months->forget((int) $saved->getId());
        $this->queue->publishLedger($saved->getOrkKingdomId());

        return $saved;
    }

    /**
     * @param array<string, bool> $publishedByTellerId
     */
    public function setPublished(KingdomRecord $kingdom, array $publishedByTellerId): void
    {
        foreach ($this->accounts->forKingdom((int) $kingdom->getId()) as $account) {
            $published = $publishedByTellerId[$account->getTellerAccountId()] ?? false;
            $this->accounts->save(AccountRecord::builder()
                ->id($account->getId())
                ->kingdomId($account->getKingdomId())
                ->tellerAccountId($account->getTellerAccountId())
                ->name($account->getName())
                ->type($account->getType())
                ->lastFour($account->getLastFour())
                ->published($published)
                ->build());
        }
        $this->months->forget((int) $kingdom->getId());
    }

    public function markDisconnected(KingdomRecord $kingdom): KingdomRecord
    {
        return $this->kingdoms->save($this->copy(
            $kingdom,
            (string) $kingdom->getEnrollmentId(),
            (string) $kingdom->getInstitutionName(),
            'disconnected',
        ));
    }

    private function importAccounts(KingdomRecord $kingdom, string $token): void
    {
        $existing = [];
        foreach ($this->accounts->forKingdom((int) $kingdom->getId()) as $account) {
            $existing[$account->getTellerAccountId()] = $account;
        }

        foreach ($this->provider->accounts($token) as $account) {
            $previous = $existing[$account->id] ?? null;
            $this->accounts->save(AccountRecord::builder()
                ->id($previous?->getId())
                ->kingdomId((int) $kingdom->getId())
                ->tellerAccountId($account->id)
                ->name($account->name)
                ->type($account->type)
                ->lastFour($account->lastFour)
                ->published($previous?->getPublished() ?? true)
                ->build());
        }
    }

    private function copy(KingdomRecord $kingdom, string $enrollmentId, string $institution, string $status): KingdomRecord
    {
        return KingdomRecord::builder()
            ->id($kingdom->getId())
            ->orkKingdomId($kingdom->getOrkKingdomId())
            ->name($kingdom->getName())
            ->slug($kingdom->getSlug())
            ->visibility($kingdom->getVisibility())
            ->displayMode($kingdom->getDisplayMode())
            ->enrollmentId($enrollmentId !== '' ? $enrollmentId : null)
            ->institutionName($institution !== '' ? $institution : null)
            ->enrollmentStatus($status)
            ->lastSyncedAt($kingdom->getLastSyncedAt())
            ->build();
    }
}
