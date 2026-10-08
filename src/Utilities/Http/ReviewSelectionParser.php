<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationSelection;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Factory: maps the manage batch review form (`review_id[]`, `review[id][publish|redact|embargo]`) to selections. */
final class ReviewSelectionParser
{
    /**
     * @param array<string, mixed> $body
     *
     * @return list<PublicationSelection>
     */
    public static function fromBody(array $body): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, static function () use ($method, $body): array {
            $ids = $body['review_id'] ?? [];
            if (!is_array($ids)) {
                DenariusLog::debugBranch('review_selection_ids_invalid', $method, []);

                return [];
            }
            $review = is_array($body['review'] ?? null) ? $body['review'] : [];
            $selections = [];
            foreach ($ids as $id) {
                $key = is_string($id) || is_int($id) ? trim((string) $id) : '';
                if ($key === '') {
                    continue;
                }
                $fields = is_array($review[$key] ?? null) ? $review[$key] : [];
                $selections[] = new PublicationSelection(
                    $key,
                    isset($fields['publish']),
                    isset($fields['redact']),
                    isset($fields['embargo']),
                );
            }

            return $selections;
        });
    }
}
