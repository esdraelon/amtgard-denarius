<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategoryConfidence;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\ProviderAmountSignRegistry;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: build normalized input and apply confidence bands to chain output. */
final class TransactionCategorizer
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
        private readonly DescriptionNormalizer $normalizer,
        private readonly ProviderAmountSignRegistry $amountSigns,
        private readonly CategoryMatcherChain $chain,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function decide(
        string $providerId,
        TransactionRecord $incoming,
        ?TransactionRecord $existing,
        ?int $kingdomId = null,
    ): CategoryDecision {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $providerId, $incoming, $existing, $kingdomId): CategoryDecision {
            $input = $this->input($providerId, $incoming, $existing, $kingdomId);
            $chain = $this->chain->resolve($input);
            $decision = $this->decisionFromChain($chain);
            DenariusLog::debugBranch('transaction_categorized', self::class . '::decide', [
                'category' => $decision->category,
                'category_source' => $decision->source->value,
                'category_rule_id' => $decision->ruleId,
                'category_confidence' => $decision->confidence,
            ]);

            return $decision;
        });
    }

    private function input(
        string $providerId,
        TransactionRecord $incoming,
        ?TransactionRecord $existing,
        ?int $kingdomId,
    ): CategorizationInput {
        return DenariusLog::trace(__METHOD__, function () use ($providerId, $incoming, $existing, $kingdomId): CategorizationInput {
            $signedCents = $this->amountSigns->forProvider($providerId)->signedCents(
                $this->decimalAmount($incoming->getAmountCents()),
            );
            $defaultFlow = TransactionFlow::defaultFromSignedCents($signedCents);
            $builder = CategorizationInput::builder()
                ->normalizedDescription($this->normalizer->normalize($incoming->getDescription()))
                ->normalizedCounterparty($this->normalizer->normalize($incoming->getCounterparty()))
                ->providerId($providerId)
                ->providerCategory((string) ($incoming->getProviderCategory() ?? ''))
                ->defaultFlow($defaultFlow)
                ->kingdomId($kingdomId ?? $incoming->getKingdomId());
            if ($existing !== null) {
                $builder
                    ->existingCategory($existing->getCategory())
                    ->existingSource($existing->getCategorySource())
                    ->existingConfidence($existing->getCategoryConfidence())
                    ->existingRuleId($existing->getCategoryRuleId())
                    ->existingSuggested($existing->getCategorySuggested())
                    ->existingTaxonomyVersion($existing->getTaxonomyVersion());
            }

            return $builder->build();
        });
    }

    private function decimalAmount(int $amountCents): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($amountCents): string {
            $sign = $amountCents < 0 ? '-' : '';
            $abs = abs($amountCents);

            return $sign . number_format($abs / 100, 2, '.', '');
        });
    }

    private function decisionFromChain(CategoryChainResult $chain): CategoryDecision
    {
        return DenariusLog::trace(__METHOD__, function () use ($chain): CategoryDecision {
            $match = $chain->match;
            $version = $this->catalog->taxonomyVersion();
            if ($match->confidence >= CategoryConfidence::AUTO_ACCEPT) {
                return new CategoryDecision(
                    $match->slug,
                    $match->source,
                    $match->ruleId,
                    $match->confidence,
                    null,
                    $version,
                );
            }

            $suggestion = $chain->suggested;
            $suggested = $suggestion?->slug;
            $source = $suggestion?->source ?? CategorySource::Fallback;
            $ruleId = $suggestion?->ruleId;
            $confidence = $suggestion?->confidence ?? 0;

            return new CategoryDecision(
                'uncategorized',
                $source,
                $ruleId,
                $confidence,
                $suggested,
                $version,
            );
        });
    }
}
