<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: only manager-published rows continue on the public path. */
final class PublicationStatusStage implements PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $kept = [];
            foreach ($envelope->lines() as $line) {
                if ($this->isPublished($line)) {
                    $kept[] = $line;
                    continue;
                }

                DenariusLog::debugBranch('publication_unpublished', $method, [
                    'posted_on' => $line->getPostedOn(),
                ]);
            }

            return $envelope->withLines($kept);
        });
    }

    private function isPublished(PublicationCandidateLine $line): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line): bool {
            $publishedAt = $line->getPublishedAt();

            return $publishedAt !== null && $publishedAt !== '';
        });
    }
}
