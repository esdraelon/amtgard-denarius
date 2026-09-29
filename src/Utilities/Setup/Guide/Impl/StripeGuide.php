<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Guide\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Setup\Field\RequiredSettings;
use Amtgard\Denarius\Utilities\Setup\Client\SetupClient;
use Amtgard\Denarius\Utilities\Setup\Field\SetupField;
use Amtgard\Denarius\Utilities\Setup\Guide\SetupGuide;

final class StripeGuide implements SetupGuide
{
    public function __construct(
        private readonly SetupClient $client,
        private readonly RequiredSettings $required,
        private readonly string $baseUrl = 'https://api.stripe.com',
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'stripe';
        });
    }

    public function instructions(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return <<<'TEXT'
Stripe
1. Open the Stripe Dashboard and complete Financial Connections registration.
2. Create a secret key and a publishable key.
3. Add a webhook for /webhooks/stripe. Subscribe to financial_connections.account.refreshed_transactions and financial_connections.account.disconnected.
4. Paste the keys when prompted. The check is a read-only account request.

TEXT;
        });
    }

    public function fields(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [
                new SetupField('STRIPE_SECRET_KEY', 'Stripe secret key', true),
                new SetupField('STRIPE_PUBLISHABLE_KEY', 'Stripe publishable key', false),
                new SetupField('STRIPE_WEBHOOK_SECRET', 'Stripe webhook secret', true),
            ];
        });
    }

    public function verify(array $values): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($values): bool {
            if (!$this->required->ready($values, ['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET'])) {
                return false;
            }

            return $this->client->status('GET', $this->accountUrl(), ['Authorization: Bearer ' . $values['STRIPE_SECRET_KEY']], '') === 200;
        });
    }

    public function failure(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'Stripe rejected the credentials.';
        });
    }

    private function accountUrl(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return rtrim($this->baseUrl, '/') . '/v1/account';
        });
    }
}
