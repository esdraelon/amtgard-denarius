<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Presentation\SummarizedStatementComposer;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SummarizedPresenter implements StatementPresenter
{
    public function __construct(
        private readonly SummarizedStatementComposer $composer = new SummarizedStatementComposer(),
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function mode(): DisplayMode
    {
        return DenariusLog::trace(__METHOD__, function (): DisplayMode {
            return DisplayMode::Summarized;
        });
    }

    public function present(array $lines, int $summarizedCategoryMinLines = PublicationPlatformLimits::DEFAULT_SUMMARIZED_CATEGORY_MIN_LINES): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines, $summarizedCategoryMinLines): array {
            return $this->composer->compose($lines, $summarizedCategoryMinLines);
        });
    }
}
