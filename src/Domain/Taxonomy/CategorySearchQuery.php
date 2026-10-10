<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: normalize manager typeahead query text (display prefixes are not searchable). */
final class CategorySearchQuery
{
    public static function normalize(string $query): string
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^(expense|income|transfer)\s*:\s*(.*)$/iu', $trimmed, $matches) === 1) {
            return trim($matches[2]);
        }

        return $trimmed;
    }
}
