<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategoryMatcherChain;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\FallbackMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KeywordRuleMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\ManagerLockMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\ProviderHintMatcher;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\TransactionCategorizer;
use Amtgard\Denarius\Domain\Taxonomy\CreditPositiveProviderAmountSign;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\PlaidProviderAmountSign;
use Amtgard\Denarius\Domain\Taxonomy\ProviderAmountSignRegistry;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;
/** Builder: production taxonomy pack wired like the container categorizer chain. */
final class CategorizationArrange
{
    public static function bundledCatalog(): TaxonomyCatalog
    {
        return (new TaxonomyCatalogLoader(dirname(__DIR__, 2), 'data/taxonomy'))->load();
    }

    public static function categorizer(?TaxonomyCatalog $catalog = null): TransactionCategorizer
    {
        $catalog ??= self::bundledCatalog();

        return new TransactionCategorizer(
            $catalog,
            new DescriptionNormalizer(),
            self::amountSignRegistry(),
            self::matcherChain($catalog),
        );
    }

    public static function amountSignRegistry(): ProviderAmountSignRegistry
    {
        return new ProviderAmountSignRegistry(
            [
                'plaid' => new PlaidProviderAmountSign(),
                'teller' => new CreditPositiveProviderAmountSign(),
                'stripe' => new CreditPositiveProviderAmountSign(),
                'simplefin' => new CreditPositiveProviderAmountSign(),
            ],
            new CreditPositiveProviderAmountSign(),
        );
    }

    public static function matcherChain(TaxonomyCatalog $catalog): CategoryMatcherChain
    {
        return new CategoryMatcherChain([
            new ManagerLockMatcher(),
            new ProviderHintMatcher($catalog),
            new KeywordRuleMatcher($catalog),
            new FallbackMatcher(),
        ]);
    }
}
