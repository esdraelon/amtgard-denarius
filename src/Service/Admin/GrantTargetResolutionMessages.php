<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Admin;

use Amtgard\Denarius\Utilities\Http\IdpEmailLookupResult;

final class GrantTargetResolutionMessages
{
    public static function forEmailLookupFailure(string $email, string $reason): string
    {
        return match ($reason) {
            IdpEmailLookupResult::REASON_UNKNOWN_EMAIL => sprintf(
                'The IDP has no account with email %s (Client IAM returned unknown email). '
                . 'Use the address stored on the IdP account, or create/link that login first.',
                $email,
            ),
            IdpEmailLookupResult::REASON_INVALID_EMAIL => sprintf(
                'Client IAM rejected the email address %s. Check the spelling and try again.',
                $email,
            ),
            IdpEmailLookupResult::REASON_ENDPOINT_UNAVAILABLE => sprintf(
                'This IDP does not expose Client IAM email lookup yet (GET /resources/client/users/by-email). '
                . 'Upgrade the IdP to a build that includes Client IAM user lookup, or ask %s to sign in to Denarius once '
                . 'so Denarius records their IdP user id from userinfo.',
                $email,
            ),
            IdpEmailLookupResult::REASON_UNAUTHORIZED => 'Client IAM rejected Denarius credentials (HTTP 401). '
                . 'Check IDP_CLIENT_ID and IDP_CLIENT_SECRET.',
            IdpEmailLookupResult::REASON_IAM_NOT_CONFIGURED => 'The OAuth client is not assigned an IAM service namespace on the IDP (HTTP 403). '
                . 'An IdP admin must set iam_service for this client before policy claims or email lookup work.',
            IdpEmailLookupResult::REASON_TRANSPORT => 'Denarius could not reach the IDP for Client IAM email lookup. Check network and IDP_BASE_URL.',
            default => sprintf(
                'Could not resolve an IdP user id for %s via Client IAM email lookup. '
                . 'Ask that person to sign in to Denarius once, or fix Client IAM on the IDP.',
                $email,
            ),
        };
    }

    public static function forLegacyFormId(string $email): string
    {
        return sprintf(
            'Denarius still has a legacy numeric id for %s, not the IdP UUID. '
            . 'Client IAM policy claims require the UUID (userinfo id / JWT sub). '
            . 'Resolve the account with Client IAM email lookup once the IDP supports it, or ask %s to sign in here once.',
            $email,
            $email,
        );
    }
}
