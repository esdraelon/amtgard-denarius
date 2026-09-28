<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\KeyValueStore;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\OrkKingdomClient;
use Amtgard\Denarius\Ork\OrkKingdom;

final class CachedKingdomDirectory
{
    public function __construct(
        private readonly OrkKingdomClient $client,
        private readonly KeyValueStore $cache,
        private readonly KingdomRefreshQueue $queue,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    /**
     * @return list<OrkKingdom>
     */
    public function list(): array
    {
        $cached = $this->cache->get('denarius:ork:kingdoms');
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->hydrate($decoded);
            }
        }

        $this->queue->publishDirectory();
        $kingdoms = $this->client->listKingdoms();
        $this->store($kingdoms);

        return $kingdoms;
    }

    public function refresh(): void
    {
        $this->store($this->client->listKingdoms());
    }

    /**
     * @param list<OrkKingdom> $kingdoms
     */
    private function store(array $kingdoms): void
    {
        $rows = [];
        foreach ($kingdoms as $kingdom) {
            $rows[] = ['id' => $kingdom->id, 'name' => $kingdom->name];
        }
        $this->cache->set('denarius:ork:kingdoms', json_encode($rows, JSON_THROW_ON_ERROR), $this->ttlSeconds);
    }

    /**
     * @param array<mixed> $rows
     * @return list<OrkKingdom>
     */
    private function hydrate(array $rows): array
    {
        $kingdoms = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'], $row['name'])) {
                continue;
            }
            $kingdoms[] = new OrkKingdom((int) $row['id'], (string) $row['name']);
        }

        return $kingdoms;
    }
}
