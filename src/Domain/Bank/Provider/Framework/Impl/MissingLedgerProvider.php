<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Impl;

use Amtgard\Denarius\Domain\Bank\Enrollment\ConnectedEnrollment;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\InstitutionSupport;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MissingLedgerProvider implements LedgerProvider
{
    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return '';
        });
    }

    public function signatureHeader(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return '';
        });
    }

    public function supports(string $institution): InstitutionSupport
    {
        return DenariusLog::trace(__METHOD__, function (): InstitutionSupport {
            return InstitutionSupport::no();
        });
    }

    public function connectConfig(string $kingdomKey): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [];
        });
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        return DenariusLog::trace(__METHOD__, function (): ConnectedEnrollment {
            throw new \InvalidArgumentException('No ledger provider is available.');
        });
    }

    public function accounts(string $accessToken): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [];
        });
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [];
        });
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return DenariusLog::trace(__METHOD__, function (): ProviderNotice {
            return ProviderNotice::rejected();
        });
    }
}
