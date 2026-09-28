<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

interface SetupGuide
{
    public function id(): string;

    public function instructions(): string;

    /**
     * @return list<SetupField>
     */
    public function fields(): array;

    /**
     * @param array<string, string> $values
     */
    public function verify(array $values): bool;

    public function failure(): string;
}
