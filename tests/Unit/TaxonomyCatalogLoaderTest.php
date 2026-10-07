<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogValidationException;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\TaxonomyPackFixture;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TaxonomyCatalogLoaderTest extends AmtgardTestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        MethodLogAssert::reset();
    }

    public function testLoadsBundledPack(): void
    {
        $loader = new TaxonomyCatalogLoader($this->projectRoot, 'data/taxonomy');
        $catalog = $loader->load();
        $this->assertSame('taxonomy/v1', $catalog->taxonomyVersion());
        $this->assertTrue($catalog->hasSlug('expense.site_rental'));
        $this->assertSame('Site rental', $catalog->label('expense.site_rental'));
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'taxonomy_catalog_loaded',
            TaxonomyCatalogLoader::class . '::load',
        );
    }

    public function testRejectsUnknownCategorySlug(): void
    {
        $dir = $this->packWithKeywordCategory('expense.not_in_catalog');
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsUnknownFlow(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['flows'] = ['not_a_flow'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsDuplicateRuleId(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][] = $keywords['rules'][0];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsMatcherTargetingSystemSlug(): void
    {
        $dir = $this->packWithKeywordCategory('system.bank_verification');
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsMatcherTargetingOtherSlug(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
            $taxonomy['categories'][] = [
                'slug' => 'expense.other',
                'label' => 'Other expenses',
                'flows' => ['expense'],
            ];
        });
        $dir = $this->packWithKeywordCategory('expense.other', $dir);
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsRegexCompileFailure(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['match'] = ['type' => 'regex', 'pattern' => '(unclosed'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsRegexBacktrackingBudget(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['match'] = ['type' => 'regex', 'pattern' => '(a+)+'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsFlowMismatchForCategory(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['flows'] = ['income'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsEmptyPackPath(): void
    {
        $loader = new TaxonomyCatalogLoader($this->projectRoot, '   ');
        $this->expectException(TaxonomyCatalogValidationException::class);
        $loader->load();
    }

    public function testRejectsTaxonomyVersionMismatch(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['taxonomyVersion'] = 'taxonomy/v0';
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsDuplicateCategorySlug(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
            $taxonomy['categories'][] = $taxonomy['categories'][0];
        });
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsRetiredTargetUnknown(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
            $taxonomy['retired'] = ['legacy.slug' => 'missing.category'];
        });
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsInvalidKeywordField(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['fields'] = ['memo'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testAcceptsTokenMatchType(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $loader = new TaxonomyCatalogLoader($dir, '.');
        $catalog = $loader->load();
        $this->assertSame('token', $catalog->keywordRules()[0]->matchType);
    }

    public function testRejectsMissingTaxonomyFile(): void
    {
        $dir = sys_get_temp_dir() . '/denarius-taxonomy-empty-' . bin2hex(random_bytes(3));
        mkdir($dir, 0777, true);
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsUnknownMatchType(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['match'] = ['type' => 'wildcard'];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsProviderHintWithUnknownCategory(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $hints = json_decode(
            (string) file_get_contents($dir . '/matchers/provider-hints.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $hints['hints'][0]['category'] = 'expense.unknown';
        file_put_contents($dir . '/matchers/provider-hints.json', json_encode($hints, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsAnyOfWithoutTokens(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['match'] = ['type' => 'anyOf', 'tokens' => []];
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testRejectsInvalidConfidence(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['confidence'] = 101;
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    public function testLoadsPackFromAbsolutePath(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $loader = new TaxonomyCatalogLoader($dir, '.');
        $this->assertSame('taxonomy/v1', $loader->load()->taxonomyVersion());
    }

    public function testRejectsMissingRequiredSpecialCategories(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
            $taxonomy['categories'] = array_values(array_filter(
                $taxonomy['categories'],
                static fn (array $row): bool => $row['slug'] !== 'uncategorized',
            ));
        });
        $this->expectRejectedLoad($dir);
    }

    public function testRejectsKeywordsRulesNotArray(): void
    {
        $dir = TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        unset($keywords['rules']);
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        $this->expectRejectedLoad($dir);
    }

    private function packWithKeywordCategory(string $category, ?string $baseDir = null): string
    {
        $dir = $baseDir ?? TaxonomyPackFixture::tempPack(static function (array &$taxonomy): void {
        });
        $keywords = json_decode(
            (string) file_get_contents($dir . '/matchers/keywords.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keywords['rules'][0]['category'] = $category;
        file_put_contents($dir . '/matchers/keywords.json', json_encode($keywords, JSON_THROW_ON_ERROR));

        return $dir;
    }

    private function expectRejectedLoad(string $relativeOrAbsoluteDir): void
    {
        $loader = new TaxonomyCatalogLoader($relativeOrAbsoluteDir, '.');
        try {
            $loader->load();
            $this->fail('Expected taxonomy load to fail closed.');
        } catch (TaxonomyCatalogValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
            MethodLogAssert::assertBranchLogged(
                BranchLogLevel::Info,
                'taxonomy_catalog_rejected',
                TaxonomyCatalogLoader::class . '::load',
            );
        }
    }
}
