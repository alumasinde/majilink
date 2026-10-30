<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use MajiLink\Support\Db;

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $version = basename($file);
    if (in_array($version, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file) ?: '';
    // Strip full-line comments, then split on statement terminators.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $statements = array_filter(array_map('trim', explode(';', $sql)), static fn ($s) => $s !== '');

    // DDL auto-commits in MySQL, so statements run outside a transaction and the
    // version row is written only once every statement succeeded.
    foreach ($statements as $statement) {
        Db::pdo()->exec($statement);
    }
    Db::insert('schema_migrations', ['version' => $version]);

    echo "Applied {$version}\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Done ({$ran} migration(s)).\n";
