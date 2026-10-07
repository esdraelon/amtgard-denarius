<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: uppercases and strips POS noise before keyword matching. */
final class DescriptionNormalizer
{
    public function normalize(string $text): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($text): string {
            $upper = strtoupper(trim($text));
            if ($upper === '') {
                return '';
            }

            $upper = $this->stripPosPrefixes($upper);
            $upper = preg_replace('/#\S*/', ' ', $upper) ?? $upper;
            $upper = preg_replace('/\d{4,}/', ' ', $upper) ?? $upper;
            $upper = preg_replace('/\s+/', ' ', $upper) ?? $upper;

            return trim($upper);
        });
    }

    private function stripPosPrefixes(string $upper): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($upper): string {
            $patterns = [
                '/^POS DEBIT\s+/',
                '/^CHECKCARD\s+/',
                '/^SQ \*/',
                '/^TST\*/',
                '/^PAYPAL \*/',
            ];
            foreach ($patterns as $pattern) {
                $next = preg_replace($pattern, '', $upper);
                if (is_string($next)) {
                    $upper = $next;
                }
            }

            return $upper;
        });
    }
}
