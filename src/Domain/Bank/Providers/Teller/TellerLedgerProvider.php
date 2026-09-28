<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Teller;

use Amtgard\Denarius\Domain\Bank\Enrollment\ConnectedEnrollment;
use Amtgard\Denarius\Domain\Bank\Providers\Support\InstitutionSupport;
use Amtgard\Denarius\Domain\Bank\Providers\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderAccount;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Domain\Bank\Providers\Readiness\ProviderReady;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction;

final class TellerLedgerProvider implements LedgerProvider
{
    /**
     * @param array<string, string> $actions
     */
    public function __construct(
        private readonly TellerApi $api,
        private readonly TellerWebhookVerifier $verifier,
        private readonly array $actions,
        private readonly ProviderReady $ready,
        private readonly string $applicationId,
        private readonly string $environment,
    ) {
    }

    public function id(): string
    {
        return 'teller';
    }

    public function signatureHeader(): string
    {
        return 'Teller-Signature';
    }

    public function supports(string $institution): InstitutionSupport
    {
        if (!$this->ready->ready() || trim($institution) === '') {
            return InstitutionSupport::no();
        }

        return InstitutionSupport::unknown();
    }

    public function connectConfig(string $kingdomKey): array
    {
        return [
            'provider' => $this->id(),
            'applicationId' => $this->applicationId,
            'environment' => $this->environment,
            'kingdomKey' => $kingdomKey,
        ];
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        $token = (string) ($payload['accessToken'] ?? '');
        $enrollmentId = $this->enrollmentId($payload);
        if ($token === '' || $enrollmentId === '') {
            throw new \InvalidArgumentException('Teller enrollment is missing an access token or id.');
        }

        return new ConnectedEnrollment($token, $enrollmentId, $this->institution($payload), $this->id());
    }

    public function accounts(string $accessToken): array
    {
        $accounts = [];
        foreach ($this->api->accounts($accessToken) as $row) {
            $account = $this->account($row);
            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        $transactions = [];
        foreach ($this->api->transactions($accessToken, $accountId, $cursor) as $row) {
            $transaction = $this->transaction($row);
            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        if (!$this->verifier->verify($body, $signature, $now)) {
            return ProviderNotice::rejected();
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ProviderNotice::rejected();
        }

        $enrollmentId = $this->noticeEnrollmentId($payload);
        if ($enrollmentId === '') {
            return ProviderNotice::acknowledged();
        }

        return ProviderNotice::of($enrollmentId, $this->action((string) ($payload['type'] ?? '')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enrollmentId(array $payload): string
    {
        $nested = is_array($payload['enrollment'] ?? null) ? $payload['enrollment'] : [];

        return (string) ($nested['id'] ?? $payload['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function institution(array $payload): string
    {
        $enrollment = is_array($payload['enrollment'] ?? null) ? $payload['enrollment'] : [];
        $institution = is_array($enrollment['institution'] ?? null) ? $enrollment['institution'] : [];

        return (string) ($institution['name'] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function account(array $row): ?ProviderAccount
    {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $lastFour = $row['last_four'] ?? null;

        return new ProviderAccount(
            $id,
            (string) ($row['name'] ?? 'Account'),
            (string) ($row['type'] ?? 'depository'),
            is_string($lastFour) && $lastFour !== '' ? $lastFour : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transaction(array $row): ?ProviderTransaction
    {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
        $counterparty = is_array($details['counterparty'] ?? null) ? $details['counterparty'] : [];

        return new ProviderTransaction(
            $id,
            (string) ($row['date'] ?? ''),
            (string) ($row['amount'] ?? '0'),
            (string) ($details['category'] ?? 'general'),
            (string) ($row['description'] ?? ''),
            (string) ($counterparty['name'] ?? ''),
            (string) ($row['status'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function noticeEnrollmentId(array $payload): string
    {
        $nested = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        return (string) ($nested['enrollment_id'] ?? $payload['enrollment_id'] ?? '');
    }

    private function action(string $type): string
    {
        return $this->actions[$type] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            'transactions.processed' => ProviderNotice::REFRESH,
            'enrollment.disconnected' => ProviderNotice::DISCONNECT,
        ];
    }
}
