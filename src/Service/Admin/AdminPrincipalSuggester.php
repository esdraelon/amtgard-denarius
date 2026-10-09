<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Resolves admin principal pickers via IdP Client IAM email lookup (local principals are synced caches only). */
final class AdminPrincipalSuggester
{
    public function __construct(
        private readonly PrincipalRepositoryInterface $principals,
        private readonly IdpUserDirectory $idpUsers,
        private readonly PrincipalSync $principalSync,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<PrincipalRecord>
     */
    public function match(string $term): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($term, $method): array {
            $term = trim($term);
            if ($term === '') {
                return [];
            }

            /** @var array<string, PrincipalRecord> $byIdpUserId */
            $byIdpUserId = [];

            if (str_contains($term, '@')) {
                $this->mergeIdpEmailLookup($term, $byIdpUserId, $method);
            } elseif (self::isEmailLocalPart($term)) {
                $this->probeIdpByLocalPart($term, $byIdpUserId, $method);
            }

            return array_values($byIdpUserId);
        });
    }

    /**
     * @param array<string, PrincipalRecord> $byIdpUserId
     */
    private function probeIdpByLocalPart(string $localPart, array &$byIdpUserId, string $logMethod): void
    {
        DenariusLog::trace(__METHOD__, function () use ($localPart, &$byIdpUserId, $logMethod): void {
            foreach ($this->idpGuessDomains() as $domain) {
                $this->mergeIdpEmailLookup($localPart . '@' . $domain, $byIdpUserId, $logMethod);
            }
        });
    }

    /** @return list<string> */
    private function idpGuessDomains(): array
    {
        $fromEnv = trim((string) ($_ENV['IDP_SUGGEST_EMAIL_DOMAINS'] ?? ''));

        /** @var list<string> $domains */
        $domains = $fromEnv !== ''
            ? array_map(trim(...), explode(',', $fromEnv))
            : ['esdraelon.com', 'amtgard.com'];

        $unique = [];
        foreach ($domains as $domain) {
            if ($domain !== '') {
                $unique[$domain] = $domain;
            }
        }

        return array_values($unique);
    }

    private static function isEmailLocalPart(string $term): bool
    {
        return strlen($term) >= 2 && preg_match('/^[a-z0-9._+-]+$/i', $term) === 1;
    }

    /**
     * @param array<string, PrincipalRecord> $byIdpUserId
     */
    private function mergeIdpEmailLookup(string $email, array &$byIdpUserId, string $logMethod): void
    {
        DenariusLog::trace(__METHOD__, function () use ($email, &$byIdpUserId, $logMethod): void {
            $lookup = $this->idpUsers->lookupByEmail($email);
            if (! $lookup->isResolved()) {
                return;
            }

            DenariusLog::debugBranch('admin_principal_suggest_idp', $logMethod, [
                'target_email' => $email,
            ]);

            $existing = $this->principals->findByEmail($email);
            $synced = $this->principalSync->upsert(
                (string) $lookup->idpUserId(),
                $email,
                $existing?->getOrkKingdomId(),
                $existing?->getOrkKingdomName(),
            );
            $byIdpUserId[$synced->getIdpUserId()] = $synced;
        });
    }
}
