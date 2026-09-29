<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\AaroExtensions\Audit\AuditConfiguration;
use Amtgard\AaroExtensions\Audit\AuditTableFactory;
use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Entity\Policy\UncachedPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Factory\TableFactory;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class Orm
{
    /** @var list<string> */
    private const AUDITED = ['kingdoms', 'published_accounts'];

    public static function configure(bool $reconfigure = false): EntityManager
    {
        return DenariusLog::trace(__METHOD__, static function () use ($reconfigure): EntityManager {
            $config = DatabaseConfiguration::fromEnvironment();
            $database = Database::fromProvider(MysqlPdoProvider::fromConfiguration($config));
            $policy = UncachedDataAccessPolicy::builder()->database($database)->build();
            $audit = AuditConfiguration::builder()
                ->editedBySupplier(static fn (): ?int => CurrentActor::editedById())
                ->build();

            $manager = EntityManager::builder()
                ->database($database)
                ->dataAccessPolicy($policy)
                ->repositoryPolicy(UncachedPolicy::builder()->build())
                ->preventShutdown(true)
                ->mapperSupplier(static function ($db, $accessPolicy, string $name) use ($audit) {
                    if (in_array($name, self::AUDITED, true)) {
                        return AuditTableFactory::mapperSupplier($db, $accessPolicy, $name, $audit);
                    }

                    return EntityMapper::builder()
                        ->table(TableFactory::build($db, $accessPolicy, $name))
                        ->name($name)
                        ->build();
                })
                ->build();

            EntityManager::configure($manager, $reconfigure);

            return $manager;
        });
    }

    public static function repository(string $class): object
    {
        return DenariusLog::trace(__METHOD__, static function () use ($class): object {
            self::configure(true);
            $repository = EntityManager::getManager()->getRepository($class);
            if (!$repository instanceof $class) {
                throw new \RuntimeException($class . ' was not created.');
            }

            return $repository;
        });
    }
}
