<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log\Sqlite;

use PDO;

/** Factory: create log_entries schema in an hourly SQLite file. */
final class SqliteLogSchema
{
    public function open(string $path, LogPathResolver $paths): PDO
    {
        $paths->ensureDirectory($path);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->apply($pdo);

        return $pdo;
    }

    public function apply(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS log_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts TEXT NOT NULL,
                level TEXT NOT NULL,
                channel TEXT NOT NULL,
                event TEXT NOT NULL,
                method TEXT NOT NULL,
                request_id TEXT,
                branch TEXT,
                context_json TEXT NOT NULL,
                raw_line TEXT NOT NULL
            );
            SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_request ON log_entries(request_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_ts ON log_entries(ts);');
    }
}
