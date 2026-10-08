<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\KeyValue;

interface KeyValueStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function setPersistent(string $key, string $value): void;

    public function delete(string $key): void;
}
