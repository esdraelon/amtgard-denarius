<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class AdminGrantedRoleIndex
{
    public function __construct(
        private readonly RoleGrantRepositoryInterface $grants,
        private readonly PrincipalRepositoryInterface $principals,
        private readonly OrkKingdomDirectory $orkKingdoms,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{email: string, idpUserId: string, permission: string, kingdomName: string, grantedAt: string}>
     */
    public function search(?string $emailTerm, ?string $kingdomTerm): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($emailTerm, $kingdomTerm): array {
            $emailNeedle = mb_strtolower(trim($emailTerm ?? ''));
            $kingdomNeedle = mb_strtolower(trim($kingdomTerm ?? ''));

            /** @var array<int, string> $kingdomNames */
            $kingdomNames = [];
            foreach ($this->orkKingdoms->list() as $kingdom) {
                $kingdomNames[$kingdom['id']] = $kingdom['name'];
            }

            /** @var array<string, RoleGrantRecord> $effective */
            $effective = [];
            foreach ($this->grants->listChronological() as $grant) {
                $orkId = $grant->getOrkKingdomId();
                $key = $grant->getTargetIdpUserId()
                    . '|'
                    . $grant->getResource()
                    . '|'
                    . ($orkId === null ? '' : (string) $orkId);
                if ($grant->getAction() === 'grant') {
                    $effective[$key] = $grant;
                } elseif (isset($effective[$key])) {
                    unset($effective[$key]);
                }
            }

            $rows = [];
            foreach ($effective as $grant) {
                $principal = $this->principals->findByIdpUserId($grant->getTargetIdpUserId());
                $email = $principal?->getEmail() ?? '';
                $orkId = $grant->getOrkKingdomId();
                $kingdomName = '—';
                if ($orkId !== null && $orkId > 0) {
                    $kingdomName = $kingdomNames[$orkId] ?? ('ORK #' . $orkId);
                }
                $permission = match ($grant->getResource()) {
                    ClaimOrn::ADMIN => 'Denarius admin',
                    ClaimOrn::MANAGE => 'Kingdom manager',
                    default => $grant->getResource(),
                };
                $row = [
                    'email' => $email,
                    'idpUserId' => $grant->getTargetIdpUserId(),
                    'permission' => $permission,
                    'kingdomName' => $kingdomName,
                    'grantedAt' => $grant->getCreatedAt(),
                ];
                if ($emailNeedle !== '' && ! str_contains(mb_strtolower($row['email']), $emailNeedle)) {
                    continue;
                }
                if ($kingdomNeedle !== '' && ! str_contains(mb_strtolower($row['kingdomName']), $kingdomNeedle)) {
                    continue;
                }
                $rows[] = $row;
            }

            usort($rows, static function (array $a, array $b): int {
                $email = strcasecmp($a['email'], $b['email']);
                if ($email !== 0) {
                    return $email;
                }
                $kingdom = strcasecmp($a['kingdomName'], $b['kingdomName']);
                if ($kingdom !== 0) {
                    return $kingdom;
                }

                return strcasecmp($a['permission'], $b['permission']);
            });

            return $rows;
        });
    }
}
