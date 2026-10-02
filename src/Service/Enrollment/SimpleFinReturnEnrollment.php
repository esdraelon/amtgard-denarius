<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinSetupToken;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class SimpleFinReturnEnrollment
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly EnrollmentService $enrollments,
        private readonly SimpleFinConnectSession $session,
        private readonly PermissionService $permissions,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function complete(string $actorId, array $query): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($actorId, $query): string {
            $slug = Optional::ofNullable($this->session->pullKingdomSlug())
                ->orElseGet(fn (): ?string => $this->slugFromQuery($query));
            if ($slug === null || $slug === '') {
                throw new SimpleFinReturnException('missing_kingdom', 'Start bank connection from the kingdom manage page.');
            }
            $kingdom = $this->kingdoms->findBySlug($slug);
            if ($kingdom === null) {
                throw new SimpleFinReturnException('missing_kingdom', 'That kingdom is not assigned.');
            }
            if (!$this->permissions->isAdmin($actorId)
                && !in_array($kingdom->getOrkKingdomId(), $this->permissions->managedKingdomIds($actorId), true)) {
                throw new SimpleFinReturnException('forbidden', 'You do not manage this kingdom.');
            }
            $setupToken = $this->setupToken($query);
            CurrentActor::set($actorId);
            try {
                $this->enrollments->connect($kingdom, [
                    'provider' => 'simplefin',
                    'setupToken' => $setupToken,
                ]);
            } catch (\RuntimeException $exception) {
                throw new SimpleFinReturnException('claim_failed', $exception->getMessage(), $exception);
            }

            return '/manage/' . $slug;
        });
    }

    /**
     * @param array<string, mixed> $query
     */
    private function setupToken(array $query): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($query): string {
            foreach (['setup_token', 'setupToken', 'token'] as $key) {
                $value = trim((string) ($query[$key] ?? ''));
                if ($value !== '') {
                    SimpleFinSetupToken::decode($value);

                    return $value;
                }
            }

            throw new SimpleFinReturnException('missing_token', 'SimpleFIN did not include a setup token.');
        });
    }

    /**
     * @param array<string, mixed> $query
     */
    private function slugFromQuery(array $query): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($query): ?string {
            $slug = trim((string) ($query['kingdom'] ?? ''));

            return Optional::ofNullable($slug === '' ? null : $slug)->orElse(null);
        });
    }
}
