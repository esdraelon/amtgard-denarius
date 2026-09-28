<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Ork;

final class OrkKingdomParser
{
    /**
     * @param array<string, mixed> $payload
     * @return list<OrkKingdom>
     */
    public function parse(array $payload): array
    {
        $status = $payload['Status']['Status'] ?? null;
        if ($status !== 0 && $status !== '0') {
            return [];
        }

        $rows = $payload['Kingdoms'] ?? $payload['Kingdom'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $kingdoms = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['KingdomId'] ?? $row['KingdomID'] ?? $row['id'] ?? null;
            $name = $row['KingdomName'] ?? $row['Name'] ?? $row['name'] ?? null;
            if (!is_numeric($id) || !is_string($name) || trim($name) === '') {
                continue;
            }
            $kingdoms[] = new OrkKingdom((int) $id, trim($name));
        }

        return $kingdoms;
    }
}
