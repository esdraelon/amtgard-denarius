<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice;

use Amtgard\Denarius\Domain\Bank\Notice\Impl\IgnoredLedgerNotice;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class LedgerNoticeRegistry
{
    /** @var array<string, LedgerNotice> */
    private array $notices;

    /**
     * @param list<LedgerNotice> $notices
     */
    public function __construct(array $notices, private readonly IgnoredLedgerNotice $ignored = new IgnoredLedgerNotice())
    {
        $entered = DenariusLog::enter(__METHOD__);
        $indexed = [];
        foreach ($notices as $notice) {
            $indexed[$notice->action()] = $notice;
        }
        $this->notices = $indexed;
    }

    public function find(string $action): LedgerNotice
    {
        return DenariusLog::trace(__METHOD__, function () use ($action): LedgerNotice {
            return $this->notices[$action] ?? $this->ignored;
        });
    }
}
