<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: validate kingdom pattern payloads before persistence. */
final class KingdomPatternValidator
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
        private readonly RegexPatternGuard $regexGuard,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function assertCategorySlug(string $slug): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug): string {
            $resolved = $this->catalog->resolveSlug(trim($slug));
            if (! $this->catalog->hasSlug($resolved)) {
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            if (str_starts_with($resolved, 'system.')) {
                throw new \InvalidArgumentException('System categories cannot be used in patterns.');
            }

            return $resolved;
        });
    }

    public function assertMatch(string $matchType, string $token, string $regexPattern, string $anyOfRaw, string $ruleId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($matchType, $token, $regexPattern, $anyOfRaw, $ruleId): void {
            match ($matchType) {
                'token' => $this->assertToken($token),
                'regex' => $this->regexGuard->assertSafe($regexPattern, $ruleId),
                'anyOf' => $this->assertAnyOf($anyOfRaw),
                default => throw new \InvalidArgumentException('Unsupported match type.'),
            };
        });
    }

    private function assertToken(string $token): void
    {
        DenariusLog::trace(__METHOD__, function () use ($token): void {
            if (trim($token) === '') {
                throw new \InvalidArgumentException('Pattern text is required.');
            }
        });
    }

    private function assertAnyOf(string $raw): void
    {
        DenariusLog::trace(__METHOD__, function () use ($raw): void {
            $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $t): bool => $t !== ''));
            if ($tokens === []) {
                throw new \InvalidArgumentException('Provide at least one token.');
            }
        });
    }
}
