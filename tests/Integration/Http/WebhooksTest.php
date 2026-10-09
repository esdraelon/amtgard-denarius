<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Support\WebhookSignatureFixtures;
use GuzzleHttp\Cookie\CookieJar;

/** Live HTTP coverage for signed provider webhooks without session cookies (D3). */
final class WebhooksTest extends IntegTestCase
{
    public function testTellerWebhookAcceptsSignedPayload(): void
    {
        $http = $this->integHttp(new CookieJar());
        $this->assertFalse($http->hasSessionCookie(), 'Webhook calls must not send session cookies');

        $body = '{"type":"transactions.processed"}';
        $now = time();
        $signature = WebhookSignatureFixtures::tellerSignature($body, $now);
        WebhookSignatureFixtures::assertTellerVerifierAccepts($body, $signature, $now);

        $response = $http->postRaw('/webhooks/teller', $body, ['Teller-Signature' => $signature]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testStripeWebhookAcceptsSignedPayload(): void
    {
        $http = $this->integHttp(new CookieJar());
        $this->assertFalse($http->hasSessionCookie());

        $body = '{"type":"financial_connections.account.refreshed_transactions","data":{"object":{"account_holder":{"customer":""}}}}';
        $now = time();
        $signature = WebhookSignatureFixtures::stripeSignature($body, $now);
        WebhookSignatureFixtures::assertStripeVerifierAccepts($body, $signature, $now);

        $response = $http->postRaw('/webhooks/stripe', $body, ['Stripe-Signature' => $signature]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testPlaidWebhookAcceptsSignedPayload(): void
    {
        $http = $this->integHttp(new CookieJar());
        $this->assertFalse($http->hasSessionCookie());

        $body = '{"webhook_code":"SYNC_UPDATES_AVAILABLE"}';
        $now = time();
        $jwt = WebhookSignatureFixtures::plaidVerificationJwt($body, $now);
        WebhookSignatureFixtures::assertPlaidVerifierAccepts($body, $jwt, $now);

        $response = $http->postRaw('/webhooks/plaid', $body, ['Plaid-Verification' => $jwt]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }
}
