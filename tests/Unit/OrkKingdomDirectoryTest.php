<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\StubOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\PHPUnit\AmtgardTestCase;

final class OrkKingdomDirectoryTest extends AmtgardTestCase
{
    public function testFetchesFromOrkWhenLocalCacheMissing(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-fetch-' . uniqid();
        mkdir($root . '/data', 0775, true);

        $orkJson = json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [
                ['KingdomId' => 4, 'KingdomName' => 'Golden Plains'],
            ],
        ], JSON_THROW_ON_ERROR);

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway($orkJson),
            new OrkKingdomCacheWriter(),
        );

        $this->assertSame([['id' => 4, 'name' => 'Golden Plains']], $directory->list());
        $this->assertFileExists($root . '/data/ork-kingdoms.json');
        $this->assertSame('Golden Plains', $directory->nameForOrkId(4));
    }

    public function testParseOrkApiPayloadVariants(): void
    {
        $this->assertSame([], OrkKingdomDirectory::parse('not-json'));
        $this->assertSame([], OrkKingdomDirectory::parse(json_encode(['Status' => ['Status' => 1]], JSON_THROW_ON_ERROR)));
        $this->assertSame([], OrkKingdomDirectory::parse(json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => 'not-an-array',
        ], JSON_THROW_ON_ERROR)));

        $ork = json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [
                'skip' => 'row',
                '2' => ['KingdomId' => 2, 'KingdomName' => 'Zeta'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->assertSame([['id' => 2, 'name' => 'Zeta']], OrkKingdomDirectory::parse($ork));

        $this->assertSame([], OrkKingdomDirectory::normalizeSimpleList([['id' => 1, 'name' => '']]));
        $this->assertSame([], OrkKingdomDirectory::normalizeSimpleList(['not-a-row']));
        $this->assertSame([], OrkKingdomDirectory::parse(json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [['KingdomId' => 9, 'KingdomName' => '   ']],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testOrkFetchWithEmptyKingdomListSeedsFromBundled(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-empty-' . uniqid();
        mkdir($root . '/data', 0775, true);
        copy(
            dirname(__DIR__, 2) . '/data/ork-kingdoms.bundled.json',
            $root . '/data/ork-kingdoms.bundled.json',
        );

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(json_encode([
                'Status' => ['Status' => 0],
                'Kingdoms' => [],
            ], JSON_THROW_ON_ERROR)),
            new OrkKingdomCacheWriter(),
        );

        $this->assertCount(27, $directory->list());
    }

    public function testImportOrkResponseReturnsFalseWhenParseYieldsNoKingdoms(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-import-' . uniqid();
        mkdir($root . '/data', 0775, true);

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $this->assertFalse($directory->importOrkResponse('{}'));
    }

    public function testListSkipsInvalidDenariusKingdomRows(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(0)->name('')->slug('empty')->build());
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(42)->name('Local Kingdom')->slug('local')->build());

        $root = sys_get_temp_dir() . '/denarius-ork-local-' . uniqid();
        mkdir($root . '/data', 0775, true);

        $directory = new OrkKingdomDirectory(
            $root,
            '',
            $kingdoms,
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $list = $directory->list();
        $this->assertSame(42, $list[0]['id']);
        $this->assertSame('Local Kingdom', $list[0]['name']);
    }

    public function testFetchFailureSeedsFromBundledSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-fail-' . uniqid();
        mkdir($root . '/data', 0775, true);
        copy(
            dirname(__DIR__, 2) . '/data/ork-kingdoms.bundled.json',
            $root . '/data/ork-kingdoms.bundled.json',
        );

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $this->assertNotEmpty($directory->list());
        $this->assertFileExists($root . '/data/ork-kingdoms.json');
    }

    public function testListAlwaysIncludesCommittedBundledKingdoms(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-partial-' . uniqid();
        mkdir($root . '/data', 0775, true);
        copy(
            dirname(__DIR__, 2) . '/data/ork-kingdoms.bundled.json',
            $root . '/data/ork-kingdoms.bundled.json',
        );
        file_put_contents($root . '/data/ork-kingdoms.json', json_encode([
            'kingdoms' => [['id' => 5, 'name' => 'Sync Kingdom']],
        ], JSON_THROW_ON_ERROR));

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $this->assertCount(27, $directory->list());
    }

    public function testCacheWriterCreatesParentDirectories(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-mkdir-' . uniqid();
        $path = $root . '/nested/cache.json';
        (new OrkKingdomCacheWriter())->write($path, [['id' => 3, 'name' => 'Deep']]);
        $this->assertFileExists($path);
    }

    public function testBlankCacheFileIsIgnored(): void
    {
        $root = sys_get_temp_dir() . '/denarius-ork-blank-' . uniqid();
        mkdir($root . '/data', 0775, true);
        file_put_contents($root . '/data/ork-kingdoms.json', '   ');

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $this->assertSame([], $directory->list());
    }
}
