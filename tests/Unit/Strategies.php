<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Providers\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\DisconnectLedgerNotice;
use Amtgard\Denarius\Domain\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Domain\Bank\Providers\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Providers\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\RefreshLedgerNotice;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerApi;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\RevokeManagerCommand;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Worker\Job\LedgerRefreshJob;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;

final class Strategies
{
    public static function admin(): AdminCommandRegistry
    {
        return new AdminCommandRegistry([
            new GrantAdminCommand(),
            new RevokeAdminCommand(),
            new GrantManagerCommand(),
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

    public static function jobs(TransactionSynchronizer $synchronizer): RefreshJobRegistry
    {
        return new RefreshJobRegistry([
            new LedgerRefreshJob($synchronizer),
        ]);
    }
}
