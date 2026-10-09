<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

/** Value object (constants): user-visible manager description redaction copy. */
final class PublicationManagerRedactCopy
{
    public const string LINE_DESCRIPTION = 'Description redacted';

    private function __construct()
    {
    }
}
