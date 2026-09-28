<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

interface LedgerProvider
{
    public function signatureHeader(): string;

    /**
     * @param array<string, mixed> $payload
     */
    public function enrollment(array $payload): ConnectedEnrollment;

    /**
     * @return list<ProviderAccount>
     */
    public function accounts(string $accessToken): array;

    /**
     * @return list<ProviderTransaction>
     */
    public function transactions(string $accessToken, string $accountId, ?string $cursor): array;

    public function notice(string $body, ?string $signature, int $now): ProviderNotice;
}
