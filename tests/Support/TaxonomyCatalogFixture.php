<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;

final class TaxonomyCatalogFixture
{
    public static function load(): TaxonomyCatalog
    {
        return (new TaxonomyCatalogLoader(dirname(__DIR__, 2), 'data/taxonomy'))->load();
    }
}
