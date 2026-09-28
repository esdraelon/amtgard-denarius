<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class StripeGuide implements SetupGuide
{
    public function __construct(
        private readonly SetupClient $client,
        private readonly RequiredSettings $required,
        private readonly string $baseUrl = 'https://api.stripe.com',
    ) {
    }

    public function id(): string
    {
        return 'stripe';
    }

    public function instructions(): string
    {
        return <<<'TEXT'
Stripe
1. Open the Stripe Dashboard and complete Financial Connections registration.
2. Create a secret key and a publishable key.
3. Add a webhook for /webhooks/stripe. Subscribe to financial_connections.account.refreshed_transactions and financial_connections.account.disconnected.
4. Paste the keys when prompted. The check is a read-only account request.

TEXT;
    }

    public function fields(): array
    {
        return [
            new SetupField('STRIPE_SECRET_KEY', 'Stripe secret key', true),
            new SetupField('STRIPE_PUBLISHABLE_KEY', 'Stripe publishable key', false),
            new SetupField('STRIPE_WEBHOOK_SECRET', 'Stripe webhook secret', true),
        ];
    }

    public function verify(array $values): bool
    {
        if (!$this->required->ready($values, ['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET'])) {
            return false;
        }

        return $this->client->status('GET', $this->accountUrl(), ['Authorization: Bearer ' . $values['STRIPE_SECRET_KEY']], '') === 200;
    }

    public function failure(): string
    {
        return 'Stripe rejected the credentials.';
    }

    private function accountUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/v1/account';
    }
}
