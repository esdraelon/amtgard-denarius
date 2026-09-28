<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Teller\Event\EnrollmentEventRegistry;
use Amtgard\Denarius\Teller\TellerWebhookVerifier;

final class TellerWebhookHandler
{
    public function __construct(
        private readonly TellerWebhookVerifier $verifier,
        private readonly KingdomStore $kingdoms,
        private readonly EnrollmentEventRegistry $events,
    ) {
    }

    public function handle(string $body, ?string $signature, int $now): bool
    {
        if (!$this->verifier->verify($body, $signature, $now)) {
            return false;
        }

        $payload = $this->payload($body);
        if ($payload === null) {
            return false;
        }

        $enrollmentId = $this->enrollmentId($payload);
        if ($enrollmentId === '') {
            return true;
        }

        $kingdom = $this->kingdoms->findByEnrollmentId($enrollmentId);
        if ($kingdom === null) {
            return true;
        }

        $this->events->find($this->type($payload))->apply($kingdom);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(string $body): ?array
    {
        $payload = json_decode($body, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function type(array $payload): string
    {
        return (string) ($payload['type'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enrollmentId(array $payload): string
    {
        $nested = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        return (string) ($nested['enrollment_id'] ?? $payload['enrollment_id'] ?? '');
    }
}
