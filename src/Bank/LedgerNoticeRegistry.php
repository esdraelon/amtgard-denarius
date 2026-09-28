<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final class LedgerNoticeRegistry
{
    /** @var array<string, LedgerNotice> */
    private array $notices;

    /**
     * @param list<LedgerNotice> $notices
     */
    public function __construct(array $notices, private readonly IgnoredLedgerNotice $ignored = new IgnoredLedgerNotice())
    {
        $indexed = [];
        foreach ($notices as $notice) {
            $indexed[$notice->action()] = $notice;
        }
        $this->notices = $indexed;
    }

    public function find(string $action): LedgerNotice
    {
        return $this->notices[$action] ?? $this->ignored;
    }
}
