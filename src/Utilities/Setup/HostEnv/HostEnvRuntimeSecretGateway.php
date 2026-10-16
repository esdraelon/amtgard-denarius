<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\HostEnv;

use Amtgard\Denarius\Contract\HostEnvRuntimeSecret;
use Amtgard\HostEnvVault\Exception\RuntimeFetchDeniedException;
use Amtgard\HostEnvVault\RuntimeVaultClient;
use Amtgard\Traits\Builder\Builder;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Runtime socket first, then optional inject tree fallback for the same profile (Gateway).
 */
final class HostEnvRuntimeSecretGateway implements HostEnvRuntimeSecret
{
    use Builder;

    private string $runtimeDir = '';

    /** @var array<string, string> profile name => rendered inject directory */
    private array $injectFallbackDirs = [];

    private ?RuntimeVaultClient $client = null;

    private ?LoggerInterface $logger = null;

    public function runtimeDir(string $runtimeDir): self
    {
        $this->runtimeDir = $runtimeDir;

        return $this;
    }

    /**
     * @param array<string, string> $profileToDir
     */
    public function injectFallbackDirs(array $profileToDir): self
    {
        $this->injectFallbackDirs = $profileToDir;

        return $this;
    }

    public function client(RuntimeVaultClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function fetchEnv(string $profile, string $entry): string
    {
        if ($this->envValue($entry) !== '') {
            return $this->envValue($entry);
        }

        if ($this->runtimeDir !== '') {
            try {
                $client = $this->client ?? RuntimeVaultClient::builder()->build()->forRuntimeProfile($this->runtimeDir, $profile);
                if ($this->logger !== null) {
                    $client->logger($this->logger);
                }
                $value = $client->fetchEnv($entry);
                $this->log(LogLevel::DEBUG, 'host_env_runtime_fetch_ok', ['profile' => $profile, 'entry' => $entry]);

                return $value;
            } catch (RuntimeFetchDeniedException) {
                $this->log(LogLevel::INFO, 'host_env_runtime_fetch_denied', ['profile' => $profile, 'entry' => $entry]);
            }
        }

        $fallbackDir = $this->injectFallbackDirs[$profile] ?? '';
        if ($fallbackDir !== '') {
            $fromInject = $this->readInjectEntry($fallbackDir, $entry);
            if ($fromInject !== '') {
                $this->log(LogLevel::DEBUG, 'host_env_runtime_inject_fallback', ['profile' => $profile, 'entry' => $entry]);

                return $fromInject;
            }
        }

        throw new \RuntimeException(sprintf('Host-env entry %s/%s is not available.', $profile, $entry));
    }

    private function readInjectEntry(string $dir, string $entry): string
    {
        $path = rtrim($dir, '/') . '/secrets.env';
        if (!is_readable($path)) {
            return '';
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = substr($line, 0, $eq);
            if ($key !== $entry) {
                continue;
            }

            return substr($line, $eq + 1);
        }

        return '';
    }

    private function envValue(string $key): string
    {
        $fromGetenv = getenv($key);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }

        $fromSuperglobal = $_ENV[$key] ?? null;

        return is_string($fromSuperglobal) && $fromSuperglobal !== '' ? $fromSuperglobal : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
