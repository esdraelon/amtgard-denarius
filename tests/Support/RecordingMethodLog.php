<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Throwable;

/** Test double: records enter/leave/fail and runs the traced body. */
final class RecordingMethodLog implements MethodLog
{
    /** @var list<string> */
    private array $entered = [];

    /** @var list<string> */
    private array $left = [];

    /** @var list<string> */
    private array $failed = [];

    /** @var list<string> */
    private array $open = [];

    /** @var list<array{level: BranchLogLevel, branch: string, method: string, context: array<string, mixed>}> */
    private array $branches = [];

    public function trace(string $method, callable $body): mixed
    {
        $this->entered[] = $method;
        $this->open[] = $method;
        try {
            $result = $body();
            $this->left[] = $method;
            array_pop($this->open);

            return $result;
        } catch (Throwable $thrown) {
            $this->failed[] = $method;
            array_pop($this->open);
            throw $thrown;
        }
    }

    public function enter(string $method): string
    {
        $this->entered[] = $method;
        $this->left[] = $method;

        return $method;
    }

    public function branch(BranchLogLevel $level, string $branch, string $method, array $context = []): void
    {
        $this->branches[] = [
            'level' => $level,
            'branch' => $branch,
            'method' => $method,
            'context' => $context,
        ];
    }

    /** @return list<array{level: BranchLogLevel, branch: string, method: string, context: array<string, mixed>}> */
    public function branches(): array
    {
        return $this->branches;
    }

    /** @return list<string> */
    public function entered(): array
    {
        return $this->entered;
    }

    /** @return list<string> */
    public function left(): array
    {
        return $this->left;
    }

    /** @return list<string> */
    public function failed(): array
    {
        return $this->failed;
    }

    /** @return list<string> */
    public function openMethods(): array
    {
        return $this->open;
    }

    public function reset(): void
    {
        $this->entered = [];
        $this->left = [];
        $this->failed = [];
        $this->open = [];
        $this->branches = [];
    }
}
