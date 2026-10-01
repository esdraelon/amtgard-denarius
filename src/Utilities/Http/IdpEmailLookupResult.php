<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

/** Outcome of Client IAM GET /resources/client/users/by-email (see IDP client-iam-handoff). */
final class IdpEmailLookupResult
{
    public const REASON_UNKNOWN_EMAIL = 'unknown_email';
    public const REASON_INVALID_EMAIL = 'invalid_email';
    public const REASON_ENDPOINT_UNAVAILABLE = 'endpoint_unavailable';
    public const REASON_UNAUTHORIZED = 'unauthorized';
    public const REASON_IAM_NOT_CONFIGURED = 'iam_not_configured';
    public const REASON_TRANSPORT = 'transport_error';
    public const REASON_UNEXPECTED = 'unexpected_response';

    private function __construct(
        private readonly ?string $idpUserId,
        private readonly ?string $reason,
    ) {
    }

    public static function resolved(string $idpUserId): self
    {
        return new self($idpUserId, null);
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isResolved(): bool
    {
        return $this->idpUserId !== null;
    }

    public function idpUserId(): ?string
    {
        return $this->idpUserId;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
