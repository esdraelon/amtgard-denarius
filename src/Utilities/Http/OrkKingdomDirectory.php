<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/**
 * ORK kingdom id + name pairs for admin pickers and kingdom provisioning.
 *
 * When the local cache file is missing or empty, Denarius fetches
 * {@see Kingdom/GetKingdoms} from ORK, seeds from {@see BUNDLED_CACHE_FILE}
 * when server-side fetch fails (e.g. Cloudflare), and accepts browser-posted ORK JSON.
 */
final class OrkKingdomDirectory
{
    public const DEFAULT_CACHE_FILE = 'data/ork-kingdoms.json';

    public const BUNDLED_CACHE_FILE = 'data/ork-kingdoms.bundled.json';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $cachePath,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly PrincipalRepositoryInterface $principals,
        private readonly OrkGetKingdomsGateway $orkApi,
        private readonly OrkKingdomCacheWriter $cacheWriter,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function list(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $this->ensureLocalRepository();

            /** @var array<int, array{id: int, name: string}> $byId */
            $byId = [];
            foreach ($this->fromBundledFile() as $kingdom) {
                $byId[$kingdom['id']] = $kingdom;
            }
            foreach ($this->fromCacheFile() as $kingdom) {
                $byId[$kingdom['id']] = $kingdom;
            }
            foreach ($this->fromDenariusKingdoms() as $kingdom) {
                $byId[$kingdom['id']] = $kingdom;
            }
            foreach ($this->fromPrincipalHints() as $kingdom) {
                if (! isset($byId[$kingdom['id']])) {
                    $byId[$kingdom['id']] = $kingdom;
                }
            }

            $list = array_values($byId);
            usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $list;
        });
    }

    public function nameForOrkId(int $orkKingdomId): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): ?string {
            foreach ($this->list() as $kingdom) {
                if ($kingdom['id'] === $orkKingdomId) {
                    return $kingdom['name'];
                }
            }

            return null;
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function parse(string $json): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($json): array {
            $payload = json_decode($json, true);
            if (! is_array($payload)) {
                return [];
            }

            if (isset($payload['kingdoms']) && is_array($payload['kingdoms'])) {
                return self::normalizeSimpleList($payload['kingdoms']);
            }

            $status = $payload['Status']['Status'] ?? null;
            if ($status !== 0 && $status !== '0' && $status !== true) {
                return [];
            }

            $rows = $payload['Kingdoms'] ?? $payload['Kingdom'] ?? [];
            if (! is_array($rows)) {
                return [];
            }

            if (! array_is_list($rows)) {
                $rows = array_values($rows);
            }

            $kingdoms = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = $row['KingdomId'] ?? $row['KingdomID'] ?? $row['id'] ?? null;
                $name = $row['KingdomName'] ?? $row['Name'] ?? $row['name'] ?? null;
                if ($id === null || ! is_string($name) || trim($name) === '') {
                    continue;
                }
                $kingdoms[] = ['id' => (int) $id, 'name' => trim($name)];
            }

            usort($kingdoms, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $kingdoms;
        });
    }

    /**
     * @param list<mixed> $rows
     * @return list<array{id: int, name: string}>
     */
    public static function normalizeSimpleList(array $rows): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($rows): array {
            $kingdoms = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = $row['id'] ?? $row['KingdomId'] ?? $row['KingdomID'] ?? null;
                $name = $row['name'] ?? $row['KingdomName'] ?? $row['Name'] ?? null;
                if ($id === null || ! is_string($name) || trim($name) === '') {
                    continue;
                }
                $kingdoms[] = ['id' => (int) $id, 'name' => trim($name)];
            }

            usort($kingdoms, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $kingdoms;
        });
    }

    private function ensureLocalRepository(): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            $path = $this->resolveCachePath();
            if ($path === null) {
                return null;
            }

            $cached = $this->readKingdomsJsonFile($path);
            $bundled = $this->readKingdomsJsonFile($this->bundledAbsolutePath());
            if ($cached !== [] && ($bundled === [] || count($cached) >= count($bundled))) {
                return null;
            }

            $raw = $this->orkApi->getKingdomsJson();
            if ($raw !== null) {
                $kingdoms = self::parse($raw);
                if ($kingdoms !== []) {
                    $this->cacheWriter->write($path, $kingdoms);

                    return null;
                }

                DenariusLog::infoBranch('ork_kingdoms_fetch_failed', __METHOD__, ['reason' => 'parse']);
            }

            $this->seedFromBundled($path);

            return null;
        });
    }

    /** Parses ORK or normalized JSON and writes the writable cache when kingdoms are present. */
    public function importOrkResponse(string $raw): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($raw): bool {
            $kingdoms = self::parse($raw);
            if ($kingdoms === []) {
                return false;
            }

            $path = $this->resolveCachePath();
            if ($path === null) {
                return false;
            }

            $this->cacheWriter->write($path, $kingdoms);

            return true;
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fromCacheFile(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return $this->readKingdomsJsonFile($this->resolveCachePath());
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fromBundledFile(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return $this->readKingdomsJsonFile($this->bundledAbsolutePath());
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function readKingdomsJsonFile(?string $path): array
    {
        if ($path === null || ! is_readable($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return [];
        }

        return self::parse($json);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fromDenariusKingdoms(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $kingdoms = [];
            foreach ($this->kingdoms->all() as $kingdom) {
                $id = $kingdom->getOrkKingdomId();
                $name = trim($kingdom->getName());
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $kingdoms[] = ['id' => $id, 'name' => $name];
            }

            return $kingdoms;
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fromPrincipalHints(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $kingdoms = [];
            foreach ($this->principals->listOrkKingdomHints() as $hint) {
                $kingdoms[] = $hint;
            }

            return $kingdoms;
        });
    }

    private function resolveCachePath(): ?string
    {
        return DenariusLog::trace(__METHOD__, function (): ?string {
            $relative = trim($this->cachePath ?? self::DEFAULT_CACHE_FILE);
            if ($relative === '') {
                return null;
            }

            if ($relative[0] === '/') {
                return $relative;
            }

            return rtrim($this->projectRoot, '/') . '/' . $relative;
        });
    }

    private function bundledAbsolutePath(): string
    {
        return rtrim($this->projectRoot, '/') . '/' . self::BUNDLED_CACHE_FILE;
    }

    private function seedFromBundled(string $targetPath): void
    {
        DenariusLog::trace(__METHOD__, function () use ($targetPath): mixed {
            $bundledPath = $this->bundledAbsolutePath();
            $kingdoms = $this->readKingdomsJsonFile($bundledPath);
            if ($kingdoms === []) {
                DenariusLog::infoBranch('ork_kingdoms_bundled_missing', __METHOD__, [
                    'path' => $bundledPath,
                ]);

                return null;
            }

            $this->cacheWriter->write($targetPath, $kingdoms);
            DenariusLog::infoBranch('ork_kingdoms_seeded_from_bundled', __METHOD__, [
                'count' => count($kingdoms),
            ]);

            return null;
        });
    }
}
