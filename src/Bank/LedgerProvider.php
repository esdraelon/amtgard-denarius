<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

interface LedgerProvider
{
    public function id(): string;

    public function signatureHeader(): string;

    public function supports(string $institution): InstitutionSupport;

    /**
     * @return array<string, mixed>
     */
    public function connectConfig(string $kingdomKey): array;

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
