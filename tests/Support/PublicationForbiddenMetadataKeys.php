<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use PHPUnit\Framework\Assert;

/** Asserts manager-only category metadata never appears in public payloads. */
final class PublicationForbiddenMetadataKeys
{
    /** @var list<string> */
    public const FORBIDDEN = [
        'provider_category',
        'category_rule_id',
        'category_source',
        'category_confidence',
        'category_suggested',
        'providerCategory',
        'categoryRuleId',
        'categorySource',
        'categoryConfidence',
        'categorySuggested',
    ];

    public static function assertAbsent(mixed $payload): void
    {
        if (! is_array($payload)) {
            $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (self::collectKeys($payload) as $key) {
            Assert::assertNotContains($key, self::FORBIDDEN, 'Forbidden category metadata key in public payload: ' . $key);
        }
    }

    /**
     * @return list<string>
     */
    private static function collectKeys(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return [];
        }
        $keys = [];
        foreach ($value as $key => $nested) {
            if (! is_string($key)) {
                continue;
            }
            $keys[] = $key;
            $keys = array_merge($keys, self::collectKeys($nested));
        }

        return $keys;
    }
}
