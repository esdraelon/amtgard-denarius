<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

interface SecretRepositoryInterface
{
    public function findCiphertext(int $kingdomId): ?string;

    public function saveCiphertext(int $kingdomId, string $ciphertext): void;
}
