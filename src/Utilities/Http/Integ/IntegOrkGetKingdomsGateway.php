<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http\Integ;

use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** DEV_INTEG: canned ORK GetKingdoms JSON from bundled cache (Fake Object). */
final class IntegOrkGetKingdomsGateway implements OrkGetKingdomsGateway
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function getKingdomsJson(): ?string
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method): ?string {
            $path = $this->projectRoot . '/' . OrkKingdomDirectory::BUNDLED_CACHE_FILE;
            if (! is_readable($path)) {
                DenariusLog::infoBranch('integ_ork_kingdoms_stub_empty', $method, ['path' => $path]);

                return null;
            }

            $json = file_get_contents($path);
            if (! is_string($json) || trim($json) === '') {
                DenariusLog::infoBranch('integ_ork_kingdoms_stub_empty', $method, ['path' => $path]);

                return null;
            }

            DenariusLog::infoBranch('integ_ork_kingdoms_stub_answered', $method, ['bytes' => strlen($json)]);

            return $json;
        });
    }
}
