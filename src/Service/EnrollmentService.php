<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\AccountStore;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\SecretStore;
use Amtgard\Denarius\Contract\TellerApi;
use Amtgard\Denarius\Record\AccountRecord;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Security\TokenCipher;

final class EnrollmentService
{
    public function __construct(
        private readonly KingdomStore $kingdoms,
        private readonly SecretStore $secrets,
        private readonly AccountStore $accounts,
        private readonly TellerApi $teller,
        private readonly TokenCipher $cipher,
        private readonly KingdomRefreshQueue $queue,
    ) {
    }

    /**
     * @param array<string, mixed> $enrollment
     */
    public function connect(KingdomRecord $kingdom, array $enrollment): KingdomRecord
    {
        $token = (string) ($enrollment['accessToken'] ?? '');
        $enrollmentId = (string) ($enrollment['enrollment']['id'] ?? $enrollment['id'] ?? '');
        if ($token === '' || $enrollmentId === '') {
            throw new \InvalidArgumentException('Teller enrollment is missing an access token or id.');
        }

        $institution = (string) ($enrollment['enrollment']['institution']['name'] ?? '');
        $this->secrets->saveCiphertext((int) $kingdom->getId(), $this->cipher->encrypt($token));

        $saved = $this->kingdoms->save($this->copy($kingdom, $enrollmentId, $institution, 'connected'));
        $this->importAccounts($saved, $token);
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

        foreach ($this->teller->accounts($token) as $row) {
            $tellerId = (string) ($row['id'] ?? '');
            if ($tellerId === '') {
                continue;
            }
            $previous = $existing[$tellerId] ?? null;
            $lastFour = $row['last_four'] ?? null;
            $this->accounts->save(AccountRecord::builder()
                ->id($previous?->getId())
                ->kingdomId((int) $kingdom->getId())
                ->tellerAccountId($tellerId)
                ->name((string) ($row['name'] ?? 'Account'))
                ->type((string) ($row['type'] ?? 'depository'))
                ->lastFour(is_string($lastFour) && $lastFour !== '' ? $lastFour : null)
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
