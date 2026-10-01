<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/**
 * ORK kingdom id + name pairs for admin pickers.
 *
 * Cloudflare blocks browser and server calls to ork.amtgard.com from most dev hosts.
 * Import a {@see Kingdom/GetKingdoms} JSON snapshot into {@see OrkKingdomDirectory::DEFAULT_CACHE_FILE}.
 */
final class OrkKingdomDirectory
{
    public const DEFAULT_CACHE_FILE = 'data/ork-kingdoms.json';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $cachePath,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly PrincipalRepositoryInterface $principals,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function list(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            /** @var array<int, array{id: int, name: string}> $byId */
            $byId = [];
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

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fromCacheFile(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $path = $this->resolveCachePath();
            if ($path === null || ! is_readable($path)) {
                return [];
            }

            $json = file_get_contents($path);
            if ($json === false || trim($json) === '') {
                return [];
            }

            return self::parse($json);
        });
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
}
