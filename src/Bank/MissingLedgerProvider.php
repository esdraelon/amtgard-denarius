<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final class MissingLedgerProvider implements LedgerProvider
{
    public function id(): string
    {
        return '';
    }

    public function signatureHeader(): string
    {
        return '';
    }

    public function supports(string $institution): InstitutionSupport
    {
        return InstitutionSupport::no();
    }

    public function connectConfig(string $kingdomKey): array
    {
        return [];
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        throw new \InvalidArgumentException('No ledger provider is available.');
    }

    public function accounts(string $accessToken): array
    {
        return [];
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        return [];
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return ProviderNotice::rejected();
    }
}
