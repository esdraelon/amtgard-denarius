<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Service\Kingdom;

use Amtgard\Denarius\Service\Kingdom\ManagedKingdomResolver;
use Amtgard\Denarius\Tests\Support\StubOrkGetKingdomsGateway;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\PHPUnit\AmtgardTestCase;

final class ManagedKingdomResolverTest extends AmtgardTestCase
{
    public function testProvisionsKingdomRowFromOrkDirectory(): void
    {
        $root = sys_get_temp_dir() . '/denarius-provision-' . uniqid();
        mkdir($root . '/data', 0775, true);
        file_put_contents($root . '/data/ork-kingdoms.json', json_encode([
            'kingdoms' => [['id' => 4, 'name' => 'Golden Plains']],
        ], JSON_THROW_ON_ERROR));

        $kingdoms = new MemoryKingdoms();
        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            $kingdoms,
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(),
            new \Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter(),
        );
        $resolver = new ManagedKingdomResolver($kingdoms, $directory);

        $record = $resolver->resolve(4);
        $this->assertNotNull($record);
        $this->assertSame('golden-plains', $record->getSlug());
        $this->assertSame('Golden Plains', $record->getName());
    }

    public function testReturnsExistingRowWithoutProvisioning(): void
    {
        $kingdoms = new MemoryKingdoms();
        $existing = $kingdoms->save(\Amtgard\Denarius\Persistence\Record\KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Existing')
            ->slug('existing')
            ->build());

        $directory = new OrkKingdomDirectory(
            sys_get_temp_dir(),
            'missing/ork-kingdoms.json',
            $kingdoms,
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(),
            new \Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter(),
        );
        $resolver = new ManagedKingdomResolver($kingdoms, $directory);

        $this->assertSame($existing, $resolver->resolve(4));
    }

    public function testSkipsReservedSlug(): void
    {
        $root = sys_get_temp_dir() . '/denarius-reserved-' . uniqid();
        mkdir($root . '/data', 0775, true);
        file_put_contents($root . '/data/ork-kingdoms.json', json_encode([
            'kingdoms' => [['id' => 9, 'name' => 'Admin']],
        ], JSON_THROW_ON_ERROR));

        $kingdoms = new MemoryKingdoms();
        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            $kingdoms,
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(),
            new \Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter(),
        );
        $resolver = new ManagedKingdomResolver($kingdoms, $directory);

        $this->assertNull($resolver->resolve(9));
        $this->assertNull($kingdoms->findByOrkId(9));
    }

    public function testUnknownOrkIdReturnsNull(): void
    {
        $root = sys_get_temp_dir() . '/denarius-unknown-' . uniqid();
        mkdir($root . '/data', 0775, true);
        file_put_contents($root . '/data/ork-kingdoms.json', json_encode(['kingdoms' => []], JSON_THROW_ON_ERROR));

        $kingdoms = new MemoryKingdoms();
        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            $kingdoms,
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(),
            new \Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter(),
        );

        $this->assertNull((new ManagedKingdomResolver($kingdoms, $directory))->resolve(404));
    }
}
