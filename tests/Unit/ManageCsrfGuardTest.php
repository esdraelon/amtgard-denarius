<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

require_once __DIR__ . '/ApplicationTest.php';

use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ManageCsrfGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testValidTokenAllowsHtmlPost(): void
    {
        $_SESSION = [];
        $token = CsrfToken::issue();
        $guard = Strategies::manageCsrfGuard(new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}']))));

        $reject = $guard->rejectHtmlIfInvalid(new Response(), ['csrf' => $token]);

        $this->assertNull($reject);
    }

    public function testInvalidTokenReturnsHtmlForbidden(): void
    {
        $_SESSION = [];
        CsrfToken::issue();
        $guard = Strategies::manageCsrfGuard(new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}']))));

        $reject = $guard->rejectHtmlIfInvalid(new Response(), ['csrf' => 'nope']);

        $this->assertNotNull($reject);
        $this->assertSame(403, $reject->getStatusCode());
        $this->assertStringContainsString('Forbidden', (string) $reject->getBody());
    }

    public function testMissingTokenReturnsHtmlForbidden(): void
    {
        $_SESSION = [];
        $guard = Strategies::manageCsrfGuard(new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}']))));

        $reject = $guard->rejectHtmlIfInvalid(new Response(), []);

        $this->assertNotNull($reject);
        $this->assertSame(403, $reject->getStatusCode());
    }

    public function testValidTokenAllowsJsonPost(): void
    {
        $_SESSION = [];
        $token = CsrfToken::issue();
        $guard = Strategies::manageCsrfGuard(new TwigHtmlRenderer(new Environment(new ArrayLoader([]))));

        $this->assertNull($guard->rejectJsonIfInvalid(new Response(), ['csrf' => $token]));
    }

    public function testInvalidTokenReturnsJsonForbidden(): void
    {
        $_SESSION = [];
        CsrfToken::issue();
        $guard = Strategies::manageCsrfGuard(new TwigHtmlRenderer(new Environment(new ArrayLoader([]))));

        $reject = $guard->rejectJsonIfInvalid(new Response(), ['csrf' => 'bad']);

        $this->assertNotNull($reject);
        $this->assertSame(403, $reject->getStatusCode());
        $this->assertStringContainsString('form token did not match', (string) $reject->getBody());
    }
}
