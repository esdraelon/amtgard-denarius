<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Utilities\Http\IdpEmailLookupResult;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class AdminGrantTargetResolver
{
    public function __construct(
        private readonly IdpUserDirectory $idpUsers,
        private readonly PrincipalRepositoryInterface $principals,
        private readonly PrincipalSync $principalSync,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function resolveIdpUserId(array $body): string
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($body, $method): string {
            $email = trim((string) ($body['target_email'] ?? ''));
            $fromForm = trim((string) ($body['idp_user_id'] ?? ''));

            if ($email !== '') {
                $lookup = $this->resolveByEmail($email);
                if ($lookup->isResolved()) {
                    DenariusLog::infoBranch('grant_target_resolved', $method, [
                        'target_email' => $email,
                        'source' => 'client_iam_email_lookup',
                    ]);

                    return (string) $lookup->idpUserId();
                }

                DenariusLog::warnBranch('grant_target_email_unresolved', $method, [
                    'target_email' => $email,
                    'lookup_reason' => $lookup->reason(),
                    'form_idp_user_id_legacy' => $fromForm !== '' && self::isLegacyPublicId($fromForm),
                ]);

                if ($fromForm === '' || self::isLegacyPublicId($fromForm)) {
                    throw new GrantTargetResolutionException(
                        (string) $lookup->reason(),
                        GrantTargetResolutionMessages::forEmailLookupFailure($email, (string) $lookup->reason()),
                    );
                }
            }

            if ($fromForm !== '' && ! self::isLegacyPublicId($fromForm)) {
                DenariusLog::infoBranch('grant_target_resolved', $method, [
                    'source' => 'form_idp_user_id',
                    'idp_user_id' => $fromForm,
                ]);

                return $fromForm;
            }

            if ($email !== '' && self::isLegacyPublicId($fromForm)) {
                throw new GrantTargetResolutionException(
                    'legacy_form_id',
                    GrantTargetResolutionMessages::forLegacyFormId($email),
                );
            }

            throw new GrantTargetResolutionException(
                'missing_email',
                'Grant target email is missing from the form.',
            );
        });
    }

    public function viewForAdmin(PrincipalRecord $principal): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($principal): array {
            $record = $this->refreshLegacyPrincipal($principal);

            return $record->view();
        });
    }

    public static function isLegacyPublicId(string $idpUserId): bool
    {
        return strlen($idpUserId) >= 8 && ctype_digit($idpUserId);
    }

    private function resolveByEmail(string $email): IdpEmailLookupResult
    {
        return DenariusLog::trace(__METHOD__, function () use ($email): IdpEmailLookupResult {
            $existing = $this->principals->findByEmail($email);
            $lookup = $this->idpUsers->lookupByEmail($email);
            if (! $lookup->isResolved()) {
                return $lookup;
            }

            $uuid = (string) $lookup->idpUserId();
            $this->principalSync->upsert(
                $uuid,
                $email,
                $existing?->getOrkKingdomId(),
                $existing?->getOrkKingdomName(),
            );

            return IdpEmailLookupResult::resolved($uuid);
        });
    }

    private function refreshLegacyPrincipal(PrincipalRecord $principal): PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($principal): PrincipalRecord {
            if (! self::isLegacyPublicId($principal->getIdpUserId())) {
                return $principal;
            }

            $email = $principal->getEmail();
            if ($email === '') {
                return $principal;
            }

            $lookup = $this->idpUsers->lookupByEmail($email);
            if (! $lookup->isResolved()) {
                return $principal;
            }

            return $this->principalSync->upsert(
                (string) $lookup->idpUserId(),
                $email,
                $principal->getOrkKingdomId(),
                $principal->getOrkKingdomName(),
            );
        });
    }
}
