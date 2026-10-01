<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin;

use Amtgard\Denarius\Utilities\Http\AppPublicUrl;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SimpleFinApplicationConfig
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appToken,
        private readonly string $bridgeRoot,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function fromEnv(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            $root = trim((string) ($_ENV['SIMPLEFIN_BRIDGE_ROOT'] ?? ''));
            if ($root === '') {
                $root = 'https://bridge.simplefin.org/simplefin';
            }

            return new self(
                trim((string) ($_ENV['SIMPLEFIN_APP_ID'] ?? '')),
                trim((string) ($_ENV['SIMPLEFIN_APP_TOKEN'] ?? '')),
                rtrim($root, '/'),
            );
        });
    }

    public function configured(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return $this->appId !== '' && $this->appToken !== '';
        });
    }

    public function appId(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->appId;
        });
    }

    public function appToken(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->appToken;
        });
    }

    public function userCreateUrl(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            $returnUrl = AppPublicUrl::path('/bank/simplefin/return');
            $create = $this->bridgeRoot . '/apps/' . rawurlencode($this->appId) . '/create';
            if ($returnUrl === '') {
                return $create;
            }

            return $create . '?' . http_build_query(['return_url' => $returnUrl]);
        });
    }

    public function returnUrl(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return AppPublicUrl::path('/bank/simplefin/return');
        });
    }
}
