<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

use Amtgard\Denarius\Tests\Support\StubOrkGetKingdomsGateway;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\PHPUnit\AmtgardTestCase;

/** End-to-end directory behavior: empty cache → ORK fail → bundled seed → non-empty list. */
final class AdminKingdomDirectoryTest extends AmtgardTestCase
{
    public function testListPopulatesWritableCacheFromBundledSnapshot(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $this->assertFileIsReadable($projectRoot . '/' . OrkKingdomDirectory::BUNDLED_CACHE_FILE);

        $root = sys_get_temp_dir() . '/denarius-int-ork-' . uniqid();
        mkdir($root . '/data', 0775, true);
        copy(
            $projectRoot . '/' . OrkKingdomDirectory::BUNDLED_CACHE_FILE,
            $root . '/' . OrkKingdomDirectory::BUNDLED_CACHE_FILE,
        );

        $cacheRelative = 'data/ork-kingdoms.json';
        $directory = new OrkKingdomDirectory(
            $root,
            $cacheRelative,
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $list = $directory->list();
        $this->assertNotEmpty($list);
        $this->assertFileIsReadable($root . '/' . $cacheRelative);
        $decoded = json_decode((string) file_get_contents($root . '/' . $cacheRelative), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($list, $decoded['kingdoms']);
    }
}
