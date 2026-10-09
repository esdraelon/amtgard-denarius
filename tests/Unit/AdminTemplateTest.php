<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\PHPUnit\AmtgardTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AdminTemplateTest extends AmtgardTestCase
{
    public function testLookupHintsFollowTheSearchState(): void
    {
        $empty = $this->render('', []);
        $this->assertStringContainsString('Search by full Amtgard ID email or username', $empty);
        $this->assertStringNotContainsString('IdP did not resolve that address', $empty);

        $missed = $this->render('nobody@example.com', []);
        $this->assertStringContainsString('IdP did not resolve that address', $missed);
        $this->assertStringNotContainsString('Search by full Amtgard ID email or username', $missed);

        $found = $this->render('person@example.com', [['email' => 'person@example.com', 'idpUserId' => '9', 'grants' => []]]);
        $this->assertStringNotContainsString('IdP did not resolve that address', $found);
        $this->assertStringNotContainsString('Search by full Amtgard ID email or username', $found);
    }

    /**
     * @param list<array<string, mixed>> $principals
     */
    private function render(string $email, array $principals): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));

        return $twig->render('admin.twig', [
            'csrf' => 'token',
            'email' => $email,
            'perm_email' => '',
            'perm_kingdom' => '',
            'principals' => $principals,
            'grantedPermissions' => [],
            'ork_api_base_url' => 'https://ork.amtgard.com',
        ]);
    }
}
