<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

interface SecretStore
{
    public function findCiphertext(int $kingdomId): ?string;

    public function saveCiphertext(int $kingdomId, string $ciphertext): void;
}
