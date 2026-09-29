<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Session;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RedisSessionHandler implements \SessionHandlerInterface
{
    public function __construct(
        private readonly object $redis,
        private readonly int $ttlSeconds = 1209600,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function open(string $path, string $name): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($path, $name): bool {
            return true;
        });
    }

    public function close(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return true;
        });
    }

    public function read(string $id): string|false
    {
        return DenariusLog::trace(__METHOD__, function () use ($id): string|false {
            $value = $this->redis->get($this->key($id));
            if ($value === false || $value === null) {
                return '';
            }

            return (string) $value;
        });
    }

    public function write(string $id, string $data): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($id, $data): bool {
            return $this->redis->setex($this->key($id), $this->ttlSeconds, $data) !== false;
        });
    }

    public function destroy(string $id): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($id): bool {
            $this->redis->del($this->key($id));

            return true;
        });
    }

    public function gc(int $max_lifetime): int|false
    {
        return DenariusLog::trace(__METHOD__, function () use ($max_lifetime): int|false {
            return 0;
        });
    }

    private function key(string $id): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($id): string {
            return 'denarius:session:' . $id;
        });
    }
}
