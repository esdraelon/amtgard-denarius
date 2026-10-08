<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Persists normalized ORK kingdom lists to the local cache file. */
final class OrkKingdomCacheWriter
{
    /**
     * @param list<array{id: int, name: string}> $kingdoms
     */
    public function write(string $absolutePath, array $kingdoms): void
    {
        DenariusLog::trace(__METHOD__, function () use ($absolutePath, $kingdoms): mixed {
            $dir = dirname($absolutePath);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Cannot create ORK kingdom cache directory: ' . $dir);
            }

            $payload = json_encode(['kingdoms' => $kingdoms], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (file_put_contents($absolutePath, $payload . "\n") === false) {
                throw new \RuntimeException('Cannot write ORK kingdom cache: ' . $absolutePath);
            }

            DenariusLog::infoBranch('ork_kingdom_cache_written', __METHOD__, [
                'path' => $absolutePath,
                'count' => count($kingdoms),
            ]);

            return null;
        });
    }
}
