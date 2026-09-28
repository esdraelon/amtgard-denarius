<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\DisconnectLedgerNotice;
use Amtgard\Denarius\Domain\Bank\Notice\LedgerNoticeRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Notice\Impl\RefreshLedgerNotice;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\Impl\GrantAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\GrantManagerCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeAdminCommand;
use Amtgard\Denarius\Service\Admin\Impl\RevokeManagerCommand;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;
use Amtgard\Denarius\Worker\Job\Impl\LedgerRefreshJob;
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
