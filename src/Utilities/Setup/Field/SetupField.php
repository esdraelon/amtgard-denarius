<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Field;

final class SetupField
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly bool $hidden,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function hidden(): bool
    {
        return $this->hidden;
    }
}
