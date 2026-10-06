<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Secret\SecretRepositoryInterface;
use Amtgard\Denarius\Domain\Kingdom\KingdomRecordRebuilder;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class EnrollmentService
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly SecretRepositoryInterface $secrets,
        private readonly AccountRepositoryInterface $accounts,
        private readonly LedgerProviderRegistry $providers,
        private readonly TokenCipher $cipher,
        private readonly KingdomRefreshQueue $queue,
        private readonly MonthInvalidator $months,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $enrollment
     */
    public function connect(KingdomRecord $kingdom, array $enrollment): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $enrollment): KingdomRecord {
            $provider = $this->providers->find($this->requested($enrollment));
            $connected = $provider->enrollment($enrollment);
            $this->secrets->saveCiphertext((int) $kingdom->getId(), $this->cipher->encrypt($connected->accessToken));

            $saved = $this->kingdoms->save($this->copy(
                $kingdom,
                $connected->enrollmentId,
                $connected->institutionName,
                $this->storedProvider($connected->provider, $provider->id()),
                'connected',
            ));
            $this->importAccounts($saved, $connected->accessToken);
            $this->months->forget((int) $saved->getId());
            $this->queue->publishLedger($saved->getOrkKingdomId());

            return $saved;
        });
    }

    /**
     * @param array<string, bool> $publishedByTellerId
     */
    public function setPublished(KingdomRecord $kingdom, array $publishedByTellerId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $publishedByTellerId): mixed {
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

            return null;
        });
    }

    public function markDisconnected(KingdomRecord $kingdom): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): KingdomRecord {
            return $this->kingdoms->save($this->copy(
                $kingdom,
                (string) $kingdom->getEnrollmentId(),
                (string) $kingdom->getInstitutionName(),
                (string) $kingdom->getProvider(),
                'disconnected',
            ));
        });
    }

    private function importAccounts(KingdomRecord $kingdom, string $token): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $token): mixed {
            $existing = [];
            foreach ($this->accounts->forKingdom((int) $kingdom->getId()) as $account) {
                $existing[$account->getTellerAccountId()] = $account;
            }

            foreach ($this->providers->find((string) $kingdom->getProvider())->accounts($token) as $account) {
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

            return null;
        });
    }

    private function copy(KingdomRecord $kingdom, string $enrollmentId, string $institution, string $provider, string $status): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $enrollmentId, $institution, $provider, $status): KingdomRecord {
            return KingdomRecordRebuilder::from($kingdom)
                ->enrollmentId($enrollmentId !== '' ? $enrollmentId : null)
                ->institutionName($institution !== '' ? $institution : null)
                ->provider($provider !== '' ? $provider : null)
                ->enrollmentStatus($status)
                ->build();
        });
    }

    /**
     * @param array<string, mixed> $enrollment
     */
    private function requested(array $enrollment): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($enrollment): string {
            $given = trim((string) ($enrollment['provider'] ?? ''));

            return Optional::ofNullable($given === '' ? null : $given)->orElse($this->providers->default()->id());
        });
    }

    private function storedProvider(string $connected, string $selected): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($connected, $selected): string {
            return Optional::ofNullable($connected === '' ? null : $connected)->orElse($selected);
        });
    }
}
