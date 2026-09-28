<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use PDO;

trait StoreSupport
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
        Orm::configure(true);
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @template T of Repository
     * @param class-string<T> $class
     * @return T
     */
    protected function repo(string $class): Repository
    {
        $repository = EntityManager::getManager()->getRepository($class);
        if (!$repository instanceof $class) {
            throw new \RuntimeException($class . ' was not created.');
        }

        return $repository;
    }
}
