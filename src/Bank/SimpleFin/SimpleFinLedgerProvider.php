<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank\SimpleFin;

use Amtgard\Denarius\Bank\ConnectedEnrollment;
use Amtgard\Denarius\Bank\InstitutionSupport;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\PreviousMonthWindow;
use Amtgard\Denarius\Bank\ProviderAccount;
use Amtgard\Denarius\Bank\ProviderNotice;
use Amtgard\Denarius\Bank\ProviderReady;
use Amtgard\Denarius\Bank\ProviderTransaction;
use Optional\Optional;

final class SimpleFinLedgerProvider implements LedgerProvider
{
    public function __construct(
        private readonly SimpleFinApi $api,
        private readonly ProviderReady $ready,
        private readonly PreviousMonthWindow $window,
    ) {
    }

    public function id(): string
    {
        return 'simplefin';
    }

    public function signatureHeader(): string
    {
        return '';
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
            'kingdomKey' => $kingdomKey,
        ];
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        $claimUrl = $this->claimUrl($payload);
        $accessUrl = trim($this->api->claim($claimUrl));
        if ($accessUrl === '') {
            throw new \RuntimeException('SimpleFIN did not return an access URL.');
        }
        $user = parse_url($accessUrl, PHP_URL_USER);

        return new ConnectedEnrollment(
            $accessUrl,
            is_string($user) && $user !== '' ? rawurldecode($user) : hash('sha256', $accessUrl),
            $this->text($payload['institutionName'] ?? null),
            $this->id(),
        );
    }

    public function accounts(string $accessToken): array
    {
        $accounts = [];
        foreach ($this->rows($this->api->accounts($accessToken, $this->window->startsAt(), $this->window->endsAt())) as $row) {
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
        foreach ($this->rows($this->api->accounts($accessToken, $this->window->startsAt(), $this->window->endsAt())) as $row) {
            if ((string) ($row['id'] ?? '') !== $accountId) {
                continue;
            }
            foreach ($this->rows($row['transactions'] ?? null) as $transaction) {
                $mapped = $this->transaction($transaction);
                if ($mapped !== null) {
                    $transactions[] = $mapped;
                }
            }
        }

        return $transactions;
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return ProviderNotice::acknowledged();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function claimUrl(array $payload): string
    {
        $token = $this->text($payload['setupToken'] ?? $payload['setup_token'] ?? null);
        $decoded = base64_decode($token, true);
        $url = is_string($decoded) ? trim($decoded) : '';
        if ($url === '' || !str_starts_with($url, 'http')) {
            throw new \InvalidArgumentException('SimpleFIN enrollment is missing a setup token.');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function account(array $row): ?ProviderAccount
    {
        $id = $this->text($row['id'] ?? null);
        if ($id === '') {
            return null;
        }
        $name = $this->text($row['name'] ?? null);

        return new ProviderAccount($id, $name !== '' ? $name : 'Account', 'depository', null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transaction(array $row): ?ProviderTransaction
    {
        $id = $this->text($row['id'] ?? null);
        $postedOn = $this->postedOn($row['posted'] ?? null);
        if ($id === '' || !$this->inside($postedOn)) {
            return null;
        }

        return new ProviderTransaction(
            $id,
            $postedOn,
            $this->amount($row['amount'] ?? '0'),
            'general',
            $this->text($row['description'] ?? null),
            '',
            ($row['pending'] ?? false) === true ? 'pending' : 'posted',
        );
    }

    private function postedOn(mixed $posted): string
    {
        if (!is_int($posted) && !(is_string($posted) && ctype_digit($posted))) {
            return '';
        }

        return (new \DateTimeImmutable('@' . $posted))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    private function amount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!is_numeric($amount)) {
            return '0.00';
        }

        return sprintf('%.2f', (float) $amount);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function text(mixed $value): string
    {
        $given = trim((string) $value);

        return Optional::ofNullable($given === '' ? null : $given)->orElse('');
    }
}
