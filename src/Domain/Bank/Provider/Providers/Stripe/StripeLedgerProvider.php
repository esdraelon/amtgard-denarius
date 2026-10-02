<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe;

use Amtgard\Denarius\Domain\Bank\Enrollment\ConnectedEnrollment;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\InstitutionSupport;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderAccount;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderTransaction;
use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class StripeLedgerProvider implements LedgerProvider
{
    /**
     * @param array<string, string> $actions
     */
    public function __construct(
        private readonly StripeApi $api,
        private readonly StripeWebhookVerifier $verifier,
        private readonly array $actions,
        private readonly ProviderReady $ready,
        private readonly PreviousMonthWindow $window,
        private readonly string $publishableKey = '',
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'stripe';
        });
    }

    public function signatureHeader(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'Stripe-Signature';
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
            $customerId = $this->required($this->api->createCustomer($kingdomKey), 'id', 'Stripe did not return a customer.');
            $clientSecret = $this->required($this->api->createSession($customerId), 'client_secret', 'Stripe did not return a session.');

            return [
                'provider' => $this->id(),
                'clientSecret' => $clientSecret,
                'customerId' => $customerId,
                'publishableKey' => $this->publishableKey,
                'kingdomKey' => $kingdomKey,
            ];
        });
    }

    public function enrollment(array $payload): ConnectedEnrollment
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): ConnectedEnrollment {
            $customerId = $this->text($payload['customerId'] ?? $payload['customer'] ?? null);
            if ($customerId === '') {
                throw new \InvalidArgumentException('Stripe enrollment is missing a customer.');
            }

            return new ConnectedEnrollment($customerId, $customerId, $this->text($payload['institutionName'] ?? null), $this->id());
        });
    }

    public function accounts(string $accessToken): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessToken): array {
            $accounts = [];
            foreach ($this->api->accounts($accessToken) as $row) {
                $account = $this->account($row);
                if ($account === null) {
                    continue;
                }
                $this->api->subscribe($account->id);
                $accounts[] = $account;
            }

            return $accounts;
        });
    }

    public function transactions(string $accessToken, string $accountId, ?string $cursor): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accountId, $cursor): array {
            if ($cursor !== null && $cursor !== '') {
                return [];
            }

            $transactions = [];
            foreach ($this->api->transactions($accountId, $this->window->startsAt(), $this->window->endsAt()) as $row) {
                $transaction = $this->transaction($row);
                if ($transaction !== null) {
                    $transactions[] = $transaction;
                }
            }

            return $transactions;
        });
    }

    public function notice(string $body, ?string $signature, int $now): ProviderNotice
    {
        return DenariusLog::trace(__METHOD__, function () use ($body, $signature, $now): ProviderNotice {
            if (!$this->verifier->verify($body, $signature, $now)) {
                return ProviderNotice::rejected();
            }

            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                return ProviderNotice::rejected();
            }

            $customerId = $this->customerId($payload);
            if ($customerId === '') {
                return ProviderNotice::acknowledged();
            }

            return ProviderNotice::of($customerId, $this->actions[(string) ($payload['type'] ?? '')] ?? '');
        });
    }

    /**
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return DenariusLog::trace(__METHOD__, static function (): array {
            return [
                'financial_connections.account.refreshed_transactions' => ProviderNotice::REFRESH,
                'financial_connections.account.disconnected' => ProviderNotice::DISCONNECT,
            ];
        });
    }

    /**
     * @param array<string, mixed> $object
     */
    private function required(array $object, string $key, string $message): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($object, $key, $message): string {
            $value = $this->text($object[$key] ?? null);
            if ($value === '') {
                throw new \RuntimeException($message);
            }

            return $value;
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
            $lastFour = $this->text($row['last4'] ?? null);

            return new ProviderAccount(
                $id,
                $this->label($row),
                $this->text($row['subcategory'] ?? $row['category'] ?? null) !== ''
                    ? $this->text($row['subcategory'] ?? $row['category'] ?? null)
                    : 'depository',
                $lastFour !== '' ? $lastFour : null,
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function label(array $row): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): string {
            $name = $this->text($row['display_name'] ?? $row['institution_name'] ?? null);

            return $name !== '' ? $name : 'Account';
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transaction(array $row): ?ProviderTransaction
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): ?ProviderTransaction {
            $id = $this->text($row['id'] ?? null);
            $postedOn = $this->postedOn($row['transacted_at'] ?? null);
            if ($id === '' || $postedOn === '' || !$this->inside($postedOn)) {
                return null;
            }

            return new ProviderTransaction(
                $id,
                $postedOn,
                Money::format((int) ($row['amount'] ?? 0)),
                'general',
                $this->text($row['description'] ?? null),
                '',
                $this->text($row['status'] ?? null),
            );
        });
    }

    private function postedOn(mixed $timestamp): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($timestamp): string {
            if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
                return '';
            }

            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
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
     * @param array<string, mixed> $payload
     */
    private function customerId(array $payload): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): string {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $object = is_array($data['object'] ?? null) ? $data['object'] : [];
            $holder = is_array($object['account_holder'] ?? null) ? $object['account_holder'] : [];

            return $this->text($holder['customer'] ?? null);
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
