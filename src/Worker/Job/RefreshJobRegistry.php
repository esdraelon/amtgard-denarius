<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

use Amtgard\Denarius\Worker\Job\Impl\IgnoredRefreshJob;

final class RefreshJobRegistry
{
    /** @var array<string, RefreshJob> */
    private array $jobs;

    /**
     * @param list<RefreshJob> $jobs
     */
    public function __construct(array $jobs, private readonly IgnoredRefreshJob $ignored = new IgnoredRefreshJob())
    {
        $indexed = [];
        foreach ($jobs as $job) {
            $indexed[$job->type()] = $job;
        }
        $this->jobs = $indexed;
    }

    public function find(string $type): RefreshJob
    {
        return $this->jobs[$type] ?? $this->ignored;
    }
}
