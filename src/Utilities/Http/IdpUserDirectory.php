<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class IdpUserDirectory
{
    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function findPublicIdByEmail(string $email): ?string
    {
        return $this->lookupByEmail($email)->idpUserId();
    }

    public function lookupByEmail(string $email): IdpEmailLookupResult
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($email, $method): IdpEmailLookupResult {
            $email = trim($email);
            if ($email === '') {
                return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_INVALID_EMAIL);
            }

            $secret = $this->environment->clientSecret();
            if ($secret === null || $secret === '') {
                DenariusLog::warnBranch('idp_user_directory_missing_secret', $method, [
                    'target_email' => $email,
                ]);

                return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_UNAUTHORIZED);
            }

            $url = $this->environment->idpBaseUrl()
                . '/resources/client/users/by-email?email='
                . rawurlencode($email);
            $request = $this->requests
                ->createRequest('GET', $url)
                ->withHeader('Accept', 'application/json')
                ->withHeader(
                    'Authorization',
                    'Basic ' . base64_encode($this->environment->clientId() . ':' . $secret),
                )
                ->withHeader('User-Agent', $this->environment->httpUserAgent());

            try {
                $response = $this->http->sendRequest($request);
            } catch (\Throwable $thrown) {
                DenariusLog::warnBranch('idp_user_directory_transport_error', $method, [
                    'target_email' => $email,
                    'error' => $thrown->getMessage(),
                ]);

                return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_TRANSPORT);
            }

            if ($response->getStatusCode() === 200) {
                return $this->parseSuccess($email, $response, $method);
            }

            return $this->parseFailure($email, $response, $method);
        });
    }

    private function parseSuccess(string $email, ResponseInterface $response, string $method): IdpEmailLookupResult
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            DenariusLog::warnBranch('idp_user_directory_invalid_json', $method, [
                'target_email' => $email,
            ]);

            return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_UNEXPECTED);
        }

        if (! is_array($decoded)) {
            return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_UNEXPECTED);
        }

        $id = $decoded['idp_user_id'] ?? null;
        if (! is_string($id) || $id === '') {
            DenariusLog::warnBranch('idp_user_directory_missing_id', $method, [
                'target_email' => $email,
            ]);

            return IdpEmailLookupResult::failed(IdpEmailLookupResult::REASON_UNEXPECTED);
        }

        DenariusLog::infoBranch('idp_user_directory_resolved', $method, [
            'target_email' => $email,
            'idp_user_id' => $id,
        ]);

        return IdpEmailLookupResult::resolved($id);
    }

    private function parseFailure(string $email, ResponseInterface $response, string $method): IdpEmailLookupResult
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $idpError = $this->idpErrorFromBody($body);

        $reason = match (true) {
            $status === 404 && $idpError === 'unknown email' => IdpEmailLookupResult::REASON_UNKNOWN_EMAIL,
            $status === 404 => IdpEmailLookupResult::REASON_ENDPOINT_UNAVAILABLE,
            $status === 400 && ($idpError === 'email is required' || str_contains((string) $idpError, 'email')) => IdpEmailLookupResult::REASON_INVALID_EMAIL,
            $status === 401 => IdpEmailLookupResult::REASON_UNAUTHORIZED,
            $status === 403 => IdpEmailLookupResult::REASON_IAM_NOT_CONFIGURED,
            default => IdpEmailLookupResult::REASON_UNEXPECTED,
        };

        DenariusLog::warnBranch('idp_user_directory_lookup_failed', $method, [
            'target_email' => $email,
            'http_status' => $status,
            'idp_error' => $idpError,
            'outcome' => $reason,
        ]);

        return IdpEmailLookupResult::failed($reason);
    }

    private function idpErrorFromBody(string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        return is_string($error) ? $error : null;
    }
}
