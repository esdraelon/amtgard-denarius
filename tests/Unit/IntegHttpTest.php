<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class IntegHttpTest extends TestCase
{
    public function testParseCsrfTokenReadsDenariusHiddenFieldName(): void
    {
        $http = new IntegHttp('http://localhost:37180');
        $token = $http->parseCsrfToken('<input type="hidden" name="csrf" value="abc123">');
        $this->assertSame('abc123', $token);
    }

    public function testRedirectLocationResolvesRelativePathAgainstBaseUrl(): void
    {
        $http = new IntegHttp('http://localhost:37180');
        $location = $http->redirectLocation(new Response(302, ['Location' => '/manage/integ-kingdom']));
        $this->assertSame('http://localhost:37180/manage/integ-kingdom', $location);
    }

    public function testIsRedirectToPathMatchesNormalizedPath(): void
    {
        $http = new IntegHttp('http://localhost:37180');
        $response = new Response(302, ['Location' => 'http://localhost:37180/login']);
        $this->assertTrue($http->isRedirectToPath($response, '/login'));
        $this->assertFalse($http->isRedirectToPath(new Response(200), '/login'));
    }
}
