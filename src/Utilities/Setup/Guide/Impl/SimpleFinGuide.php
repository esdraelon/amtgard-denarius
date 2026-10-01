<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Guide\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Setup\Field\RequiredSettings;
use Amtgard\Denarius\Utilities\Setup\Field\SetupField;
use Amtgard\Denarius\Utilities\Setup\Guide\SetupGuide;

final class SimpleFinGuide implements SetupGuide
{
    public function __construct(private readonly RequiredSettings $required)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'simplefin';
        });
    }

    public function instructions(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return <<<'TEXT'
SimpleFIN
1. In SimpleFIN Bridge Apps, create Denarius (for example amtgard_denarius_dev) and copy the app id and setup token.
2. Set SIMPLEFIN_APP_ID and SIMPLEFIN_APP_TOKEN in .env. Set APP_PUBLIC_URL or IDP_REDIRECT_URI so return URLs resolve.
3. In the SimpleFIN app settings, set the return URL shown on the kingdom manage page after Add bank (defaults to /bank/simplefin/return).
4. Kingdom managers use Add bank; Denarius opens https://bridge.simplefin.org/simplefin/apps/{appId}/create and claims the connection token on return.

TEXT;
        });
    }

    public function fields(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [
                new SetupField('SIMPLEFIN_APP_ID', 'SimpleFIN app id', false),
                new SetupField('SIMPLEFIN_APP_TOKEN', 'SimpleFIN app setup token', true),
            ];
        });
    }

    public function verify(array $values): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($values): bool {
            return $this->required->ready($values, ['SIMPLEFIN_APP_ID', 'SIMPLEFIN_APP_TOKEN']);
        });
    }

    public function failure(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'SimpleFIN needs SIMPLEFIN_APP_ID and SIMPLEFIN_APP_TOKEN.';
        });
    }
}
