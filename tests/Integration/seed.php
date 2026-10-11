<?php

declare(strict_types=1);

use Amtgard\Denarius\Tests\Integration\IntegFixtures;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

integApplyCliEnvironment();

if ((getenv('ENVIRONMENT') ?: '') !== 'DEV_INTEG') {
    fwrite(STDERR, "seed.php requires ENVIRONMENT=DEV_INTEG\n");
    exit(1);
}

$pdo = integPdo();
purgeFixtures($pdo);
seedBaselineFixtures($pdo);

fwrite(STDOUT, 'Integ fixtures seeded (schema ' . integEnvString('DB_NAME', '') . ").\n");

function integApplyCliEnvironment(): void
{
    integForceEnv('ENVIRONMENT', 'DEV_INTEG');
    integApplyEnvDefault('DB_HOST', 'amtgard-denarius-db-integ');
    integApplyEnvDefault('DB_PORT', '3306');
    integApplyEnvDefault('DB_NAME', 'denarius_integ');
    integApplyEnvDefault('DB_USER', 'denarius');
    integApplyEnvDefault('DB_PASS', 'secret');
}

function integForceEnv(string $key, string $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function integApplyEnvDefault(string $key, string $default): void
{
    $existing = getenv($key);
    if ($existing !== false && $existing !== '') {
        return;
    }

    putenv("{$key}={$default}");
    $_ENV[$key] = $default;
    $_SERVER[$key] = $default;
}

function integEnvString(string $key, string $default): string
{
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }

    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    return $default;
}

function integPdo(): PDO
{
    $host = integEnvString('DB_HOST', 'localhost');
    $port = integEnvString('DB_PORT', '3306');
    $name = integEnvString('DB_NAME', '');
    $user = integEnvString('DB_USER', '');
    $pass = integEnvString('DB_PASS', '');

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function purgeFixtures(PDO $pdo): void
{
    $tables = [
        'transactions',
        'kingdom_category_rules',
        'published_accounts_audit',
        'published_accounts',
        'enrollment_secrets',
        'role_grants',
        'principals',
        'kingdoms_audit',
        'kingdoms',
    ];

    foreach ($tables as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
}

function seedBaselineFixtures(PDO $pdo): void
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $stmt = $pdo->prepare(
        'INSERT INTO kingdoms (
            ork_kingdom_id, name, slug, visibility, display_mode,
            enrollment_id, institution_name, enrollment_status, last_synced_at,
            provider, last_sync_attempted_at, last_sync_status, last_sync_error
        ) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, ?, NULL, NULL, NULL)',
    );
    $stmt->execute([
        IntegFixtures::KINGDOM_ORK_ID,
        IntegFixtures::KINGDOM_NAME,
        IntegFixtures::KINGDOM_SLUG,
        'public',
        'summarized',
        'none',
        'teller',
    ]);

    $principal = $pdo->prepare(
        'INSERT INTO principals (idp_user_id, email, ork_kingdom_id, ork_kingdom_name, updated_at)
         VALUES (?, ?, ?, ?, ?)',
    );
    $principal->execute([
        IntegFixtures::ADMIN_IDP_USER_ID,
        IntegFixtures::ADMIN_EMAIL,
        IntegFixtures::KINGDOM_ORK_ID,
        IntegFixtures::KINGDOM_NAME,
        $now,
    ]);
    $principal->execute([
        IntegFixtures::MANAGER_IDP_USER_ID,
        IntegFixtures::MANAGER_EMAIL,
        IntegFixtures::KINGDOM_ORK_ID,
        IntegFixtures::KINGDOM_NAME,
        $now,
    ]);

    $kingdomId = (int) $pdo->query(
        'SELECT id FROM kingdoms WHERE slug = ' . $pdo->quote(IntegFixtures::KINGDOM_SLUG),
    )->fetchColumn();
    seedReviewTransactions($pdo, $kingdomId);
}

function seedReviewTransactions(PDO $pdo, int $kingdomId): void
{
    $account = $pdo->prepare(
        'INSERT INTO published_accounts (kingdom_id, teller_account_id, name, account_type, last_four, published)
         VALUES (?, ?, ?, ?, ?, 1)',
    );
    $account->execute([
        $kingdomId,
        IntegFixtures::REVIEW_ACCOUNT_ID,
        'Integ Checking',
        'depository',
        '4242',
    ]);

    $transaction = $pdo->prepare(
        'INSERT INTO transactions (
            kingdom_id, teller_transaction_id, teller_account_id, posted_on, amount_cents,
            category_id, description, status, publishable_after
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $publishableAfter = '2020-01-01T00:00:00+00:00';
    $transaction->execute([
        $kingdomId,
        IntegFixtures::TXN_REVIEW_PUBLISH,
        IntegFixtures::REVIEW_ACCOUNT_ID,
        IntegFixtures::REVIEW_MONTH . '-02',
        -1250,
        categoryIdForLineage($pdo, 'expense.site_rental'),
        'D9 publish row',
        'posted',
        $publishableAfter,
    ]);
    $transaction->execute([
        $kingdomId,
        IntegFixtures::TXN_REVIEW_REDACT,
        IntegFixtures::REVIEW_ACCOUNT_ID,
        IntegFixtures::REVIEW_MONTH . '-03',
        -2500,
        categoryIdForLineage($pdo, 'expense.feast_groceries'),
        'D9 redact row',
        'posted',
        $publishableAfter,
    ]);
}

function categoryIdForLineage(PDO $pdo, string $lineageKey): int
{
    $statement = $pdo->prepare(
        'SELECT cl.current_category_id
         FROM category_lineages cl
         WHERE cl.lineage_key = ?
         LIMIT 1',
    );
    $statement->execute([$lineageKey]);
    $id = $statement->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Missing category lineage: ' . $lineageKey);
    }

    return (int) $id;
}
