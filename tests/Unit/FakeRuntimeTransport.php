<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\HostEnvVault\Port\RuntimeTransport;
use Amtgard\HostEnvVault\RuntimeFetchKind;
use Amtgard\HostEnvVault\RuntimeFrameCodec;
use Amtgard\HostEnvVault\RuntimeResponseStatus;

final class FakeRuntimeTransport implements RuntimeTransport
{
    /** @param array<string, string> $envByEntry */
    public function __construct(private array $envByEntry)
    {
    }

    public function exchange(string $requestFrame): string
    {
        $codec = new RuntimeFrameCodec();
        if (strlen($requestFrame) < 5) {
            return $this->deny($codec);
        }
        $opcode = RuntimeFetchKind::Env->opcode()->value;
        if (ord($requestFrame[0]) !== $opcode) {
            return $this->deny($codec);
        }
        $length = unpack('V', substr($requestFrame, 1, 4))[1];
        $entry = substr($requestFrame, 5, $length);
        $value = $this->envByEntry[$entry] ?? null;
        if ($value === null) {
            return $this->deny($codec);
        }

        return $this->ok($codec, $value);
    }

    private function deny(RuntimeFrameCodec $codec): string
    {
        return chr(RuntimeResponseStatus::Denied->value) . pack('V', 0);
    }

    private function ok(RuntimeFrameCodec $codec, string $value): string
    {
        return chr(RuntimeResponseStatus::Ok->value) . pack('V', strlen($value)) . $value;
    }
}
