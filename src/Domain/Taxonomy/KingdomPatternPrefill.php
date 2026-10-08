<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Builder: derive a token match from review row text fields. */
final class KingdomPatternPrefill
{
    public function __construct(private readonly DescriptionNormalizer $normalizer)
    {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array{counterparty: string, description: string, category: string, token: string, matchType: string}
     */
    public function fromReviewQuery(string $counterparty, string $description, string $category): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($counterparty, $description, $category): array {
            $normalizedCounterparty = $this->normalizer->normalize($counterparty);
            $normalizedDescription = $this->normalizer->normalize($description);
            $token = $normalizedCounterparty !== '' ? $normalizedCounterparty : $normalizedDescription;
            if (strlen($token) > 120) {
                $token = substr($token, 0, 120);
            }

            return [
                'counterparty' => $counterparty,
                'description' => $description,
                'category' => $category,
                'token' => $token,
                'matchType' => 'token',
            ];
        });
    }
}
