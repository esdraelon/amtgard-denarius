<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log\Sqlite;

use DateTimeImmutable;
use PDO;
use PDOStatement;

/** Command: move spool JSONL lines into hourly SQLite WAL files. */
final class LogSpoolDrainer
{
    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly SqliteLogSchema $schema,
        private readonly MethodLogJsonLineParser $parser,
    ) {
    }

    /** @return int Number of lines persisted. */
    public function drainOnce(): int
    {
        $spool = $this->paths->spoolFile();
        if (! is_file($spool)) {
            return 0;
        }

        $lines = $this->readSpoolLines($spool);
        if ($lines === []) {
            $this->truncateSpool($spool);

            return 0;
        }

        $inserted = 0;
        $pdo = null;
        $statement = null;
        $currentPath = '';

        foreach ($lines as $line) {
            $row = $this->parser->parse($line);
            if ($row === null) {
                continue;
            }

            $instant = $this->instantFromRow($row['ts']);
            $sqlitePath = $this->paths->sqlitePathForInstant($instant);
            if ($sqlitePath !== $currentPath) {
                $pdo = $this->schema->open($sqlitePath, $this->paths);
                $statement = $this->prepareInsert($pdo);
                $currentPath = $sqlitePath;
            }

            $this->bindInsert($statement, $row);
            $statement->execute();
            ++$inserted;
        }

        $this->truncateSpool($spool);

        return $inserted;
    }

    /**
     * @return list<string>
     */
    private function readSpoolLines(string $spool): array
    {
        $handle = fopen($spool, 'cb+');
        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return [];
            }

            $contents = stream_get_contents($handle);
            if ($contents === false || $contents === '') {
                return [];
            }

            return array_values(array_filter(
                explode("\n", $contents),
                static fn (string $line): bool => trim($line) !== '',
            ));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function truncateSpool(string $spool): void
    {
        $handle = fopen($spool, 'cb+');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function instantFromRow(string $ts): DateTimeImmutable
    {
        if ($ts === '') {
            return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        try {
            return new DateTimeImmutable($ts);
        } catch (\Exception) {
            return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    private function prepareInsert(PDO $pdo): PDOStatement
    {
        return $pdo->prepare(
            'INSERT INTO log_entries (ts, level, channel, event, method, request_id, branch, context_json, raw_line)
             VALUES (:ts, :level, :channel, :event, :method, :request_id, :branch, :context_json, :raw_line)',
        );
    }

    /**
     * @param array{
     *     ts: string,
     *     level: string,
     *     channel: string,
     *     event: string,
     *     method: string,
     *     request_id: ?string,
     *     branch: ?string,
     *     context_json: string,
     *     raw_line: string
     * } $row
     */
    private function bindInsert(PDOStatement $statement, array $row): void
    {
        $statement->bindValue(':ts', $row['ts']);
        $statement->bindValue(':level', $row['level']);
        $statement->bindValue(':channel', $row['channel']);
        $statement->bindValue(':event', $row['event']);
        $statement->bindValue(':method', $row['method']);
        $statement->bindValue(':request_id', $row['request_id']);
        $statement->bindValue(':branch', $row['branch']);
        $statement->bindValue(':context_json', $row['context_json']);
        $statement->bindValue(':raw_line', $row['raw_line']);
    }
}
