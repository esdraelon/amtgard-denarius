<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Ingest;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: applies ingest-time HARD flags from keyword rules. */
final class TransactionHardRedactAnnotator
{
    public function __construct(
        private readonly VerificationKeywordHardMatcher $keywords,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function annotate(TransactionRecord $built): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($built): TransactionRecord {
            $flags = PublicationFlags::parse($built->getPublicationFlags());
            if ($this->keywords->matches($built)) {
                $flags = $flags->withHardPattern(PublicationHardPatternIds::VERIFY_KEYWORD);
            }
            $encoded = $flags->encode();

            return TransactionRecordRebuilder::from($built)
                ->publicationFlags($encoded ?? $built->getPublicationFlags())
                ->build();
        });
    }
}
