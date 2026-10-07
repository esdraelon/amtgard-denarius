<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: compile-check regex rules and reject catastrophic backtracking shapes. */
final class RegexPatternGuard
{
    public function assertSafe(string $pattern, string $ruleId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($pattern, $ruleId): void {
            if (preg_match('/\([^)]*[+*][^)]*\)[+*]/', $pattern) === 1) {
                throw new TaxonomyCatalogValidationException(
                    sprintf('Regex for rule %s exceeds backtracking budget.', $ruleId),
                );
            }

            set_error_handler(static function (int $severity, string $message) use ($ruleId): bool {
                throw new TaxonomyCatalogValidationException(
                    sprintf('Regex for rule %s failed to compile: %s', $ruleId, $message),
                );
            });
            try {
                $compiled = @preg_match('#' . $pattern . '#i', '');
            } finally {
                restore_error_handler();
            }
            if ($compiled === false) {
                throw new TaxonomyCatalogValidationException(
                    sprintf('Regex for rule %s failed to compile.', $ruleId),
                );
            }
        });
    }
}
