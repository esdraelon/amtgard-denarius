<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

/** Shared fixture identifiers for integration seed data and later milestones. */
final class IntegFixtures
{
    public const KINGDOM_ORK_ID = 91001;

    public const KINGDOM_SLUG = 'integ-kingdom';

    public const KINGDOM_NAME = 'Integration Kingdom';

    /** Matches `DENARIUS_BOOTSTRAP_ADMIN_IDP_USER_IDS` in phpunit.integ.xml. */
    public const ADMIN_IDP_USER_ID = '7';

    public const ADMIN_EMAIL = 'integ-admin@example.com';

    public const MANAGER_IDP_USER_ID = '91002';

    public const MANAGER_EMAIL = 'integ-manager@example.com';

    /** Matches IDP integ seed (`IntegFixtures::PASSWORD` in amtgard-idp). */
    public const IDP_FIXTURE_PASSWORD = 'integ-fixture-pass';
}
