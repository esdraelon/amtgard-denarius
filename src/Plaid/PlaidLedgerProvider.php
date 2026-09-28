<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Plaid;

use Amtgard\Denarius\Bank\ConnectedEnrollment;
use Amtgard\Denarius\Bank\InstitutionSupport;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\PreviousMonthWindow;
use Amtgard\Denarius\Bank\ProviderAccount;
use Amtgard\Denarius\Bank\ProviderNotice;
use Amtgard\Denarius\Bank\ProviderReady;
use Amtgard\Denarius\Bank\ProviderTransaction;
use Amtgard\Denarius\Contract\PlaidApi;
use Optional\Optional;

final class PlaidLedgerProvider implements LedgerProvider
{
    /**
     * @param array<string, string> $actions
     */
    public function __construct(
        private readonly PlaidApi $api,
        private readonly PlaidWebhookVerifier $verifier,
        private readonly array $actions,
        private readonly ProviderReady $ready,
        private readonly PreviousMonthWindow $window,
    ) {
    }

    public function id(): string
    {
        return 'plaid';
    }

    public function signatureHeader(): string
    {
        return 'Plaid-Verification';
    }

    public function supports(string $institution): InstitutionSupport
    {
        $name = trim($institution);
        if (!$this->ready->ready() || $name === '') {
            return InstitutionSupport::no();
        }

        try {
            $matches = $this->api->institutions($name);
        } catch (\Throwable) {
            return InstitutionSupport::unknown();
        }

        return $matches === [] ? InstitutionSupport::no() : InstitutionSupport::yes();
    }

    public function connectConfig(string $kingdomKey): array
    {
        $token = $this->text($this->api->linkToken($kingdomKey)['link_token'] ?? null);
        if ($token === '') {
            throw new \RuntimeException('Plaid did not return a link token.');
        }

        return [
            'provider' => $this->id(),
            'linkToken' => $token,
            'kingdomKey' => $kingdomKey,
        ];
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        $publicToken = $this->text($payload['publicToken'] ?? $payload['public_token'] ?? null);
        if ($publicToken === '') {
            throw new \InvalidArgumentException('Plaid enrollment is missing a public token.');
        }
        $exchanged = $this->api->exchange($publicToken);
        $accessToken = $this->text($exchanged['access_token'] ?? null);
        $itemId = $this->text($exchanged['item_id'] ?? null);
        if ($accessToken === '' || $itemId === '') {
            throw new \RuntimeException('Plaid did not return an access token.');
        }

        return new ConnectedEnrollment($accessToken, $itemId, $this->text($payload['institutionName'] ?? null), $this->id());
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
        if ($cursor !== null && $cursor !== '') {
            return [];
        }

        $transactions = [];
        foreach ($this->api->transactions($accessToken) as $row) {
            $transaction = $this->transaction($row, $accountId);
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
        $itemId = $this->text($payload['item_id'] ?? null);
        if ($itemId === '') {
            return ProviderNotice::acknowledged();
        }

        return ProviderNotice::of($itemId, $this->actions[(string) ($payload['webhook_code'] ?? '')] ?? '');
    }

    /**
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            'SYNC_UPDATES_AVAILABLE' => ProviderNotice::REFRESH,
            'DEFAULT_UPDATE' => ProviderNotice::REFRESH,
            'INITIAL_UPDATE' => ProviderNotice::REFRESH,
            'HISTORICAL_UPDATE' => ProviderNotice::REFRESH,
            'USER_PERMISSION_REVOKED' => ProviderNotice::DISCONNECT,
            'USER_ACCOUNT_REVOKED' => ProviderNotice::DISCONNECT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function account(array $row): ?ProviderAccount
    {
        $id = $this->text($row['account_id'] ?? null);
        if ($id === '') {
            return null;
        }
        $mask = $this->text($row['mask'] ?? null);
        $name = $this->text($row['name'] ?? null);

        return new ProviderAccount($id, $name !== '' ? $name : 'Account', $this->kind($row), $mask !== '' ? $mask : null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function kind(array $row): string
    {
        $type = $this->text($row['type'] ?? null);

        return $type !== '' ? $type : 'depository';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transaction(array $row, string $accountId): ?ProviderTransaction
    {
        $id = $this->text($row['transaction_id'] ?? null);
        $postedOn = $this->text($row['date'] ?? null);
        if ($id === '' || ($row['account_id'] ?? '') !== $accountId || !$this->inside($postedOn)) {
            return null;
        }
        $category = $this->category($row);

        return new ProviderTransaction(
            $id,
            $postedOn,
            $this->amount($row['amount'] ?? 0),
            $category !== '' ? $category : 'general',
            $this->text($row['name'] ?? null),
            $this->text($row['merchant_name'] ?? null),
            ($row['pending'] ?? false) === true ? 'pending' : 'posted',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function category(array $row): string
    {
        $finance = is_array($row['personal_finance_category'] ?? null) ? $row['personal_finance_category'] : [];

        return $this->text($finance['primary'] ?? null);
    }

    private function amount(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return '0.00';
        }

        return sprintf('%.2f', (float) $value);
    }

    private function inside(string $postedOn): bool
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $postedOn, new \DateTimeZone('UTC'));
        if ($day === false) {
            return false;
        }
        $start = (new \DateTimeImmutable('@' . $this->window->startsAt()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('@' . $this->window->endsAt()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

        return $postedOn >= $start && $postedOn <= $end;
    }

    private function text(mixed $value): string
    {
        $given = trim((string) $value);

        return Optional::ofNullable($given === '' ? null : $given)->orElse('');
    }
}
