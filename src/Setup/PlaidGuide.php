<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class PlaidGuide implements SetupGuide
{
    public function __construct(
        private readonly SetupClient $client,
        private readonly RequiredSettings $required,
        private readonly string $baseUrl = 'https://sandbox.plaid.com',
    ) {
    }

    public function id(): string
    {
        return 'plaid';
    }

    public function instructions(): string
    {
        return <<<'TEXT'
Plaid
1. Open the Plaid Dashboard and start a Trial or pay-as-you-go plan. The dollar rate is not published here.
2. Copy the client id and secret for the environment you will use.
3. Point the webhook at /webhooks/plaid for SYNC_UPDATES_AVAILABLE and USER_PERMISSION_REVOKED.
4. The check is a read-only institutions request.

TEXT;
    }

    public function fields(): array
    {
        return [
            new SetupField('PLAID_CLIENT_ID', 'Plaid client id', false),
            new SetupField('PLAID_SECRET', 'Plaid secret', true),
        ];
    }

    public function verify(array $values): bool
    {
        if (!$this->required->ready($values, ['PLAID_CLIENT_ID', 'PLAID_SECRET'])) {
            return false;
        }

        return $this->client->status('POST', $this->institutionsUrl(), ['Content-Type: application/json'], $this->body($values)) === 200;
    }

    public function failure(): string
    {
        return 'Plaid rejected the credentials.';
    }

    /**
     * @param array<string, string> $values
     */
    private function body(array $values): string
    {
        return json_encode([
            'client_id' => $values['PLAID_CLIENT_ID'],
            'secret' => $values['PLAID_SECRET'],
            'count' => 1,
            'offset' => 0,
            'country_codes' => ['US'],
        ], JSON_THROW_ON_ERROR);
    }

    private function institutionsUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/institutions/get';
    }
}
