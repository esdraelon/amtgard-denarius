<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\Pattern\PublicationPatternRegistry;
use Amtgard\Denarius\Domain\Statement\Publication\Pattern\PublicationRulesetVersion;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: applies versioned SOFT patterns and merges pattern_ids with ingest HARD flags. */
final class PatternRegistryStage implements PublicationStage
{
    public function __construct(
        private readonly PublicationPatternRegistry $registry = new PublicationPatternRegistry(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $patterns = $this->registry->patternsForRuleset(PublicationRulesetVersion::CURRENT);
            $updated = [];
            $matchedIds = [];
            foreach ($envelope->lines() as $line) {
                $flags = PublicationFlags::parse($line->getPublicationFlags());
                $shaped = $line;
                foreach ($patterns as $pattern) {
                    if (!$pattern->matches($shaped)) {
                        continue;
                    }
                    DenariusLog::debugBranch('publication_soft_pattern_applied', $method, [
                        'pattern_id' => $pattern->id(),
                        'posted_on' => $line->getPostedOn(),
                    ]);
                    $flags = $flags->withPatternId($pattern->id());
                    $shaped = $pattern->apply($shaped);
                    $matchedIds[$pattern->id()] = true;
                }
                $encoded = $flags->encode();
                $updated[] = PublicationCandidateLine::builder()
                    ->tellerTransactionId($shaped->getTellerTransactionId())
                    ->postedOn($shaped->getPostedOn())
                    ->amountCents($shaped->getAmountCents())
                    ->categoryId($shaped->getCategoryId())
                    ->category($shaped->getCategory())
                    ->categoryFlow($shaped->getCategoryFlow())
                    ->description($shaped->getDescription())
                    ->counterparty($shaped->getCounterparty())
                    ->status($shaped->getStatus())
                    ->accountName($shaped->getAccountName())
                    ->publishedAt($shaped->getPublishedAt())
                    ->publishableAfter($shaped->getPublishableAfter())
                    ->publicationFlags($encoded)
                    ->build();
            }

            if ($matchedIds !== []) {
                DenariusLog::infoBranch('publication_treasurer_alert', $method, [
                    'ruleset_version' => PublicationRulesetVersion::CURRENT,
                    'pattern_ids' => array_keys($matchedIds),
                ]);
            }

            return $envelope->withLines($updated);
        });
    }
}
