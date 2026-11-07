<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Points the application at a throwaway database (default: majilink_test) before
 * the real bootstrap runs, migrates it with the same migrator production uses,
 * seeds the lookup tables, then clears every transactional table so each test
 * starts from a known state.
 *
 * The configured database name is read from .env and is never touched: the run
 * aborts if the test database resolves to it.
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
require_once $root . '/src/Support/Env.php';
require_once $root . '/src/Support/Config.php';

use MajiLink\Database\Migrator;
use MajiLink\Support\Db;
use MajiLink\Support\Env;
use MajiLink\Tests\Support\TestDatabase;

Env::load($root . '/.env');

$testDb = getenv('TEST_DB_NAME') ?: 'majilink_test';
$configured = (string) Env::get('DB_NAME', '');

if ($testDb === '' || strcasecmp($testDb, $configured) === 0) {
    fwrite(STDERR, "Refusing to run tests against the configured database '{$configured}'.\n");
    fwrite(STDERR, "Set TEST_DB_NAME to a separate database (default majilink_test).\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $testDb)) {
    fwrite(STDERR, "TEST_DB_NAME may only contain letters, digits and underscores.\n");
    exit(1);
}

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', '3306');
$user = (string) Env::get('DB_USER', 'root');
$pass = (string) Env::get('DB_PASS', '');

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server = null;

Env::override('DB_NAME', $testDb);
Env::override('APP_ENV', 'testing');
// No outbound HTTP during tests: ETA falls back to the straight-line estimate.
Env::override('ROUTING_URL', '');

require $root . '/bootstrap.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

Migrator::run($root . '/database/migrations');

// The seeder prints a summary line; keep it out of the PHPUnit output.
ob_start();
require $root . '/database/seeders/seed.php';
ob_end_clean();

TestDatabase::wipe();

return $root;
