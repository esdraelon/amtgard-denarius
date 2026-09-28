<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Bank\AlwaysReady;
use Amtgard\Denarius\Bank\DisconnectLedgerNotice;
use Amtgard\Denarius\Bank\LedgerNoticeRegistry;
use Amtgard\Denarius\Bank\LedgerProvider;
use Amtgard\Denarius\Bank\LedgerProviderRegistry;
use Amtgard\Denarius\Bank\RefreshLedgerNotice;
use Amtgard\Denarius\Contract\KeyValueStore;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\TellerApi;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\RevokeManagerCommand;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Teller\TellerWebhookVerifier;
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

    public static function events(KingdomRefreshQueue $queue, EnrollmentService $enrollments): LedgerNoticeRegistry
    {
        return new LedgerNoticeRegistry([
            new RefreshLedgerNotice($queue),
            new DisconnectLedgerNotice($enrollments),
        ]);
    }

    public static function providers(LedgerProvider $provider): LedgerProviderRegistry
    {
        return new LedgerProviderRegistry([$provider]);
    }

    public static function teller(?TellerApi $api = null, ?TellerWebhookVerifier $verifier = null): LedgerProvider
    {
        return new TellerLedgerProvider(
            $api ?? new FakeTeller(),
            $verifier ?? new TellerWebhookVerifier('whsec', 300),
            TellerLedgerProvider::actions(),
            new AlwaysReady(),
            'app_test',
            'sandbox',
        );
    }

    public static function months(?KeyValueStore $store = null): MonthInvalidator
    {
        return new MonthInvalidator($store ?? new ArrayStore());
    }

    public static function jobs(CachedKingdomDirectory $directory, TransactionSynchronizer $synchronizer): RefreshJobRegistry
    {
        return new RefreshJobRegistry([
            new DirectoryRefreshJob($directory),
            new LedgerRefreshJob($synchronizer),
        ]);
    }
}
