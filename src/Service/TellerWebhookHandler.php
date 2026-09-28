<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Teller\TellerWebhookVerifier;

final class TellerWebhookHandler
{
    public function __construct(
        private readonly TellerWebhookVerifier $verifier,
        private readonly KingdomStore $kingdoms,
        private readonly KingdomRefreshQueue $queue,
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function handle(string $body, ?string $signature, int $now): bool
    {
        if (!$this->verifier->verify($body, $signature, $now)) {
            return false;
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return false;
        }

        $type = (string) ($payload['type'] ?? '');
        $enrollmentId = (string) ($payload['payload']['enrollment_id'] ?? $payload['enrollment_id'] ?? '');
        if ($enrollmentId === '') {
            return true;
        }

        $kingdom = $this->kingdoms->findByEnrollmentId($enrollmentId);
        if ($kingdom === null) {
            return true;
        }

        if ($type === 'enrollment.disconnected') {
            $this->enrollments->markDisconnected($kingdom);
            return true;
        }

        if ($type === 'transactions.processed') {
            $this->queue->publishLedger($kingdom->getOrkKingdomId());
        }

        return true;
    }
}
