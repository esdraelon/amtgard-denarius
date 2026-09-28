<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class TellerGuide implements SetupGuide
{
    public function __construct(
        private readonly SetupClient $client,
        private readonly RequiredSettings $required,
    ) {
    }

    public function id(): string
    {
        return 'teller';
    }

    public function instructions(): string
    {
        return <<<'TEXT'
Teller
1. Open the Teller dashboard and create an application. Use the development environment before production KYB.
2. Download the mTLS certificate and private key. Production access is arranged with Teller. This script does not submit KYB.
3. Paste the application id, certificate path, key path, and webhook secret. The check only reads that the id is present and both files are readable.

TEXT;
    }

    public function fields(): array
    {
        return [
            new SetupField('TELLER_APPLICATION_ID', 'Teller application id', false),
            new SetupField('TELLER_CERT_PATH', 'Teller certificate path', false),
            new SetupField('TELLER_KEY_PATH', 'Teller key path', false),
            new SetupField('TELLER_WEBHOOK_SECRET', 'Teller webhook secret', true),
        ];
    }

    public function verify(array $values): bool
    {
        if (!$this->required->ready($values, ['TELLER_APPLICATION_ID', 'TELLER_CERT_PATH', 'TELLER_KEY_PATH', 'TELLER_WEBHOOK_SECRET'])) {
            return false;
        }

        return $this->client->readable($values['TELLER_CERT_PATH']) && $this->client->readable($values['TELLER_KEY_PATH']);
    }

    public function failure(): string
    {
        return 'Teller rejected the credentials.';
    }
}
