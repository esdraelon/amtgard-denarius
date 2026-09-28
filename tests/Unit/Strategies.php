<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\RevokeManagerCommand;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Teller\Event\EnrollmentDisconnectedEvent;
use Amtgard\Denarius\Teller\Event\EnrollmentEventRegistry;
use Amtgard\Denarius\Teller\Event\TransactionsProcessedEvent;
use Amtgard\Denarius\Worker\Job\DirectoryRefreshJob;
use Amtgard\Denarius\Worker\Job\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;

final class Strategies
{
    public static function admin(CachedKingdomDirectory $directory): AdminCommandRegistry
    {
        return new AdminCommandRegistry([
            new GrantAdminCommand(),
            new RevokeAdminCommand(),
            new GrantManagerCommand($directory),
            new RevokeManagerCommand(),
        ]);
    }

    public static function events(KingdomRefreshQueue $queue, EnrollmentService $enrollments): EnrollmentEventRegistry
    {
        return new EnrollmentEventRegistry([
            new TransactionsProcessedEvent($queue),
            new EnrollmentDisconnectedEvent($enrollments),
        ]);
    }

    public static function jobs(CachedKingdomDirectory $directory, TransactionSynchronizer $synchronizer): RefreshJobRegistry
    {
        return new RefreshJobRegistry([
            new DirectoryRefreshJob($directory),
            new LedgerRefreshJob($synchronizer),
        ]);
    }
}
