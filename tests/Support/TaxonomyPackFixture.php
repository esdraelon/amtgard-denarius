<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

/** Builder: minimal valid taxonomy pack directories for loader tests. */
final class TaxonomyPackFixture
{
    public static function bundledRoot(): string
    {
        return dirname(__DIR__, 2) . '/data/taxonomy';
    }

    public static function writeMinimalPack(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $matchers = $directory . '/matchers';
        if (! is_dir($matchers)) {
            mkdir($matchers, 0777, true);
        }

        $taxonomy = [
            'taxonomyVersion' => 'taxonomy/v1',
            'retired' => [],
            'categories' => [
                ['slug' => 'income.dues', 'label' => 'Dues', 'flows' => ['income']],
                ['slug' => 'expense.bank_fees', 'label' => 'Bank fees', 'flows' => ['expense']],
                ['slug' => 'transfer.internal', 'label' => 'Transfer', 'flows' => ['transfer']],
                ['slug' => 'uncategorized', 'label' => 'Uncategorized', 'flows' => ['income', 'expense', 'transfer']],
                ['slug' => 'system.bank_verification', 'label' => 'Bank verification', 'flows' => ['expense']],
            ],
        ];
        file_put_contents(
            $directory . '/taxonomy.json',
            json_encode($taxonomy, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $keywords = [
            'taxonomyVersion' => 'taxonomy/v1',
            'rules' => [
                [
                    'id' => 'kw.test.token',
                    'category' => 'expense.bank_fees',
                    'fields' => ['description'],
                    'match' => ['type' => 'token', 'token' => 'FEE'],
                    'flows' => ['expense'],
                    'confidence' => 80,
                ],
            ],
        ];
        file_put_contents(
            $matchers . '/keywords.json',
            json_encode($keywords, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $hints = [
            'taxonomyVersion' => 'taxonomy/v1',
            'hints' => [
                [
                    'id' => 'hint.plaid.fees',
                    'provider' => 'plaid',
                    'hint' => 'BANK_FEES',
                    'category' => 'expense.bank_fees',
                    'flows' => ['expense'],
                    'confidence' => 50,
                ],
            ],
        ];
        file_put_contents(
            $matchers . '/provider-hints.json',
            json_encode($hints, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    /**
     * @param callable(array<string, mixed>): void $mutate
     */
    public static function tempPack(callable $mutate): string
    {
        $dir = sys_get_temp_dir() . '/denarius-taxonomy-' . bin2hex(random_bytes(4));
        self::writeMinimalPack($dir);

        $taxonomyPath = $dir . '/taxonomy.json';
        $taxonomy = json_decode((string) file_get_contents($taxonomyPath), true, 512, JSON_THROW_ON_ERROR);
        $mutate($taxonomy);
        file_put_contents($taxonomyPath, json_encode($taxonomy, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $dir;
    }
}
