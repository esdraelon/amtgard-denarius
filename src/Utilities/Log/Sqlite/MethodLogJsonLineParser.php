<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log\Sqlite;

/** Adapter: decode one stderr/spool JSON line into insert columns. */
final class MethodLogJsonLineParser
{
    /**
     * @return array{
     *     ts: string,
     *     level: string,
     *     channel: string,
     *     event: string,
     *     method: string,
     *     request_id: ?string,
     *     branch: ?string,
     *     context_json: string,
     *     raw_line: string
     * }|null
     */
    public function parse(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $context = $decoded['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        return [
            'ts' => (string) ($decoded['time'] ?? ''),
            'level' => (string) ($decoded['level'] ?? ''),
            'channel' => (string) ($decoded['channel'] ?? ''),
            'event' => (string) ($decoded['event'] ?? ''),
            'method' => (string) ($decoded['method'] ?? ''),
            'request_id' => isset($decoded['request_id']) && $decoded['request_id'] !== null
                ? (string) $decoded['request_id']
                : null,
            'branch' => isset($decoded['branch']) ? (string) $decoded['branch'] : null,
            'context_json' => json_encode($context, JSON_THROW_ON_ERROR),
            'raw_line' => $trimmed,
        ];
    }
}
