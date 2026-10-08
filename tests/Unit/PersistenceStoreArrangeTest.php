<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\PersistenceStoreArrange;
use Amtgard\PHPUnit\AmtgardTestCase;
use PDO;

final class PersistenceStoreArrangeTest extends AmtgardTestCase
{
    public function testMigrateFreshRefusesDevDatabaseName(): void
    {
        $priorApp = $_ENV['APP_ENV'] ?? null;
        $priorDb = $_ENV['DB_NAME'] ?? null;
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_NAME'] = 'denarius';
        putenv('APP_ENV=testing');
        putenv('DB_NAME=denarius');

        $pdo = $this->createMock(PDO::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing migrateFresh on database "denarius"');

        try {
            PersistenceStoreArrange::migrateFresh($pdo);
        } finally {
            if ($priorApp === null) {
                unset($_ENV['APP_ENV']);
                putenv('APP_ENV');
            } else {
                $_ENV['APP_ENV'] = $priorApp;
                putenv('APP_ENV=' . $priorApp);
            }
            if ($priorDb === null) {
                unset($_ENV['DB_NAME']);
                putenv('DB_NAME');
            } else {
                $_ENV['DB_NAME'] = $priorDb;
                putenv('DB_NAME=' . $priorDb);
            }
        }
    }
}
