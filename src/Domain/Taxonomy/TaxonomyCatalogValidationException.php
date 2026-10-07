<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Exception: taxonomy pack failed closed validation at load time. */
final class TaxonomyCatalogValidationException extends \InvalidArgumentException
{
}
