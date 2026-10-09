<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Documents IDP integ prerequisite; skips when the IDP stack is not running. */
final class IdpPrerequisiteTest extends IntegTestCase
{
    public function testIdpOpenIdDiscoveryReachableWhenStackIsUp(): void
    {
        IntegAuth::skipIfIdpUnavailable($this);

        $response = (new IntegHttp(IntegAuth::idpBaseUrl()))
            ->get('/.well-known/openid-configuration');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
