<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Domain\Statement\Presentation\Impl\AllFieldsPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\Impl\RedactedPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\Impl\SummarizedPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class StatementPresenterRegistry
{
    /** @var array<string, StatementPresenter> */
    private array $presenters;

    /**
     * @param list<StatementPresenter> $presenters
     */
    public function __construct(array $presenters)
    {
        $entered = DenariusLog::enter(__METHOD__);
        $indexed = [];
        foreach ($presenters as $presenter) {
            $indexed[$presenter->mode()->value] = $presenter;
        }
        $this->presenters = $indexed;
    }

    public static function standard(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self([
                new AllFieldsPresenter(),
                new RedactedPresenter(),
                new SummarizedPresenter(),
            ]);
        });
    }

    public function for(DisplayMode $mode): StatementPresenter
    {
        return DenariusLog::trace(__METHOD__, function () use ($mode): StatementPresenter {
            return $this->presenters[$mode->value];
        });
    }
}
