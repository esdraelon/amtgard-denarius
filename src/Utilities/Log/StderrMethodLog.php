<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use DateTimeImmutable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

/** Processor: method enter/leave/fail as one JSON object per event on stderr. */
final class StderrMethodLog implements MethodLog
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public static function create(?bool $debug = null): self
    {
        $resolved = $debug ?? (($_ENV['APP_DEBUG'] ?? 'false') === 'true');

        return self::withHandler(new JsonStderrHandler(), $resolved);
    }

    public static function withHandler(AbstractProcessingHandler $handler, bool $debug): self
    {
        return new self(new Logger('denarius', [new WhatFailureGroupHandler([$handler])]), $debug);
    }

    public function trace(string $method, callable $body): mixed
    {
        $this->emit('enter', $method, Level::Info);
        try {
            $result = $body();
            $this->emit('leave', $method, Level::Info);

            return $result;
        } catch (Throwable $thrown) {
            $this->emit('fail', $method, Level::Error);
            throw $thrown;
        }
    }

    public function enter(string $method): string
    {
        $this->emit('enter', $method, Level::Info);

        return $method;
    }

    public function branch(BranchLogLevel $level, string $branch, string $method, array $context = []): void
    {
        if (! $this->shouldEmitBranch($level)) {
            return;
        }

        $monolog = match ($level) {
            BranchLogLevel::Debug => Level::Debug,
            BranchLogLevel::Info => Level::Info,
            BranchLogLevel::Warn => Level::Warning,
        };

        $payload = [
            'time' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'level' => strtolower($monolog->getName()),
            'channel' => LogChannel::fromMethod($method),
            'event' => 'branch',
            'branch' => $branch,
            'method' => $method,
            'request_id' => RequestLogContext::id(),
            'context' => RedactingContext::redact($context),
        ];

        $this->logger->log($monolog, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function shouldEmitBranch(BranchLogLevel $level): bool
    {
        if ($level === BranchLogLevel::Info || $level === BranchLogLevel::Warn) {
            return true;
        }

        return $this->debug;
    }

    private function emit(string $event, string $method, Level $level): void
    {
        if (!$this->debug && $event !== 'fail') {
            return;
        }

        $payload = [
            'time' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'level' => strtolower($level->getName()),
            'channel' => LogChannel::fromMethod($method),
            'event' => $event,
            'method' => $method,
            'request_id' => RequestLogContext::id(),
            'context' => RedactingContext::redact([]),
        ];

        $this->logger->log($level, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
