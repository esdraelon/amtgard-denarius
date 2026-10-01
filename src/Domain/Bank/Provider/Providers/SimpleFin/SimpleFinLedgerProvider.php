<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin;

use Amtgard\Denarius\Domain\Bank\Enrollment\ConnectedEnrollment;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\InstitutionSupport;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderAccount;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class SimpleFinLedgerProvider implements LedgerProvider
{
    public function __construct(
        private readonly SimpleFinApi $api,
        private readonly ProviderReady $ready,
        private readonly PreviousMonthWindow $window,
        private readonly SimpleFinApplicationConfig $application,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'simplefin';
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
        return DenariusLog::trace(__METHOD__, function () use ($institution): InstitutionSupport {
            if (!$this->ready->ready() || trim($institution) === '') {
                return InstitutionSupport::no();
            }

            return InstitutionSupport::unknown();
        });
    }

    public function connectConfig(string $kingdomKey): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomKey): array {
            return [
                'provider' => $this->id(),
                'kingdomKey' => $kingdomKey,
                'appId' => $this->application->appId(),
                'bridgeUrl' => $this->application->userCreateUrl(),
                'returnUrl' => $this->application->returnUrl(),
            ];
        });
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): ConnectedEnrollment {
            $claimUrl = $this->claimUrl($payload);
            $accessUrl = trim($this->api->claim($claimUrl));
            if ($accessUrl === '' || SimpleFinSetupToken::forbidden($accessUrl)) {
                throw new \RuntimeException('SimpleFIN rejected the setup token. Generate a new connection from the bridge.');
            }
            $user = parse_url($accessUrl, PHP_URL_USER);

            return new ConnectedEnrollment(
                $accessUrl,
                is_string($user) && $user !== '' ? rawurldecode($user) : hash('sha256', $accessUrl),
                $this->text($payload['institutionName'] ?? null),
                $this->id(),
            );
        });
    }

    public function accounts(string $accessToken): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessToken): array {
            $accounts = [];
            foreach ($this->rows($this->api->accounts($accessToken, $this->window->startsAt(), $this->window->endsAt())) as $row) {
                $account = $this->account($row);
                if ($account !== null) {
                    $accounts[] = $account;
                }
            }

            return $accounts;
        });
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessToken, $accountId, $cursor): array {
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
        });
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return DenariusLog::trace(__METHOD__, function (): ProviderNotice {
            return ProviderNotice::acknowledged();
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function claimUrl(array $payload): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): string {
            $token = $this->text($payload['setupToken'] ?? $payload['setup_token'] ?? null);

            return SimpleFinSetupToken::decode($token)->claimUrl();
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function account(array $row): ?ProviderAccount
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): ?ProviderAccount {
            $id = $this->text($row['id'] ?? null);
            if ($id === '') {
                return null;
            }
            $name = $this->text($row['name'] ?? null);

            return new ProviderAccount($id, $name !== '' ? $name : 'Account', 'depository', null);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transaction(array $row): ?ProviderTransaction
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): ?ProviderTransaction {
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
        });
    }

    private function postedOn(mixed $posted): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($posted): string {
            if (!is_int($posted) && !(is_string($posted) && ctype_digit($posted))) {
                return '';
            }

            return (new \DateTimeImmutable('@' . $posted))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        });
    }

    private function amount(mixed $value): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): string {
            $amount = trim((string) $value);
            if (!is_numeric($amount)) {
                return '0.00';
            }

            return sprintf('%.2f', (float) $amount);
        });
    }

    private function inside(string $postedOn): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($postedOn): bool {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $postedOn, new \DateTimeZone('UTC'));
            if ($day === false) {
                return false;
            }
            $start = (new \DateTimeImmutable('@' . $this->window->startsAt()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $end = (new \DateTimeImmutable('@' . $this->window->endsAt()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

            return $postedOn >= $start && $postedOn <= $end;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): array {
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
        });
    }

    private function text(mixed $value): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): string {
            $given = trim((string) $value);

            return Optional::ofNullable($given === '' ? null : $given)->orElse('');
        });
    }
}
