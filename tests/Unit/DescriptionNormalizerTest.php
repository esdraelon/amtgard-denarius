<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\PHPUnit\AmtgardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DescriptionNormalizerTest extends AmtgardTestCase
{
    private DescriptionNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DescriptionNormalizer();
    }

    #[DataProvider('normalizationCases')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    public function testEmptyStringNormalizesToEmpty(): void
    {
        $this->assertSame('', $this->normalizer->normalize('   '));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function normalizationCases(): array
    {
        return [
            ['pos debit recreation.gov', 'RECREATION.GOV'],
            ['SQ *AMTGARD FEAST', 'AMTGARD FEAST'],
            ['TST*CAMPGROUND', 'CAMPGROUND'],
            ['PAYPAL *KINGDOM DUES', 'KINGDOM DUES'],
            ['CHECKCARD KROGER #1234', 'KROGER'],
            ['STORE 123456789 PURCHASE', 'STORE PURCHASE'],
            ['  mixed   whitespace  ', 'MIXED WHITESPACE'],
        ];
    }
}
