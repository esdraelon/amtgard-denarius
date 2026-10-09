#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Removes rows written by PersistenceStoreArrange::exerciseRepositories() when tests
 * accidentally ran against DB_NAME=denarius. Safe to run on dev; does not touch denarius_test.
 *
 *   php bin/purge-phpunit-fixtures.php
 */

use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$name = (string) ($_ENV['DB_NAME'] ?? '');
if ($name === 'denarius_test') {
    fwrite(STDERR, "Refusing: DB_NAME is denarius_test. Point .env at dev database denarius.\n");
    exit(1);
}

$pdo = MysqlPdoProvider::fromConfiguration(DatabaseConfiguration::fromEnvironment())->getPdo();

$fixtureActor = '15';
$fixtureTarget = '9';
$fixtureEnrollment = 'enr';
$fixtureTxn = 'txn';
$fixtureAccount = 'acc';

$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM role_grants WHERE target_idp_user_id = " . $pdo->quote($fixtureTarget)
        . " OR (actor_idp_user_id = " . $pdo->quote($fixtureActor) . " AND created_at = '2026-09-01T00:00:00+00:00')");
    $pdo->exec('DELETE FROM transactions WHERE teller_transaction_id = ' . $pdo->quote($fixtureTxn));
    $pdo->exec('DELETE FROM published_accounts WHERE teller_account_id = ' . $pdo->quote($fixtureAccount));
    $pdo->exec('DELETE FROM enrollment_secrets WHERE ciphertext IN (' . $pdo->quote('cipher') . ',' . $pdo->quote('cipher-2') . ')');
    $pdo->exec('DELETE FROM kingdoms WHERE enrollment_id = ' . $pdo->quote($fixtureEnrollment)
        . ' OR (slug = ' . $pdo->quote('golden-plains') . ' AND institution_name = ' . $pdo->quote('Bank') . ')');
    $pdo->exec('DELETE FROM principals WHERE idp_user_id = ' . $pdo->quote($fixtureTarget)
        . ' OR email IN (' . $pdo->quote('person@example.com') . ',' . $pdo->quote('other@example.com') . ')');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

fwrite(STDOUT, "Purged PHPUnit fixture rows from database \"{$name}\".\n");
