<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

use Amtgard\Denarius\Worker\Job\Impl\IgnoredRefreshJob;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RefreshJobRegistry
{
    /** @var array<string, RefreshJob> */
    private array $jobs;

    /**
     * @param list<RefreshJob> $jobs
     */
    public function __construct(array $jobs, private readonly IgnoredRefreshJob $ignored = new IgnoredRefreshJob())
    {
        $entered = DenariusLog::enter(__METHOD__);
        $indexed = [];
        foreach ($jobs as $job) {
            $indexed[$job->type()] = $job;
        }
        $this->jobs = $indexed;
    }

    public function find(string $type): RefreshJob
    {
        return DenariusLog::trace(__METHOD__, function () use ($type): RefreshJob {
            return $this->jobs[$type] ?? $this->ignored;
        });
    }
}
