<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Session;

final class RedisSessionHandler implements \SessionHandlerInterface
{
    public function __construct(
        private readonly object $redis,
        private readonly int $ttlSeconds = 1209600,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $value = $this->redis->get($this->key($id));
        if ($value === false || $value === null) {
            return '';
        }

        return (string) $value;
    }

    public function write(string $id, string $data): bool
    {
        return $this->redis->setex($this->key($id), $this->ttlSeconds, $data) !== false;
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->key($id));

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    private function key(string $id): string
    {
        return 'denarius:session:' . $id;
    }
}
