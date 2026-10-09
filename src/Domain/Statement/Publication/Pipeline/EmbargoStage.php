<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: drop lines still inside the kingdom embargo window. */
final class EmbargoStage implements PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $asOf = $envelope->asOf();
            $kept = [];
            foreach ($envelope->lines() as $line) {
                if ($this->embargoOpen($line, $asOf)) {
                    $kept[] = $line;
                    continue;
                }

                DenariusLog::debugBranch('publication_embargo_hold', $method, [
                    'posted_on' => $line->getPostedOn(),
                    'publishable_after' => $line->getPublishableAfter(),
                ]);
            }

            return $envelope->withLines($kept);
        });
    }

    private function embargoOpen(PublicationCandidateLine $line, \DateTimeImmutable $asOf): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $asOf): bool {
            $after = $line->getPublishableAfter();
            if ($after === null || $after === '') {
                return true;
            }
            $eligible = new \DateTimeImmutable($after);

            return $asOf >= $eligible;
        });
    }
}
