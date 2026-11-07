<?php
declare(strict_types=1);

namespace MajiLink\Database;

use MajiLink\Support\Db;

/**
 * Applies database/migrations/*.sql exactly once per file, tracked in
 * schema_migrations. Shared by database/migrate.php and the test suite so the
 * schema a test runs against is the schema production runs against.
 */
final class Migrator
{
    /** @return list<string> versions applied by this call */
    public static function run(string $directory): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(80) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $applied = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file) ?: '';
            // Strip full-line comments, then split on statement terminators.
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            $statements = array_filter(array_map('trim', explode(';', $sql)), static fn ($s) => $s !== '');

            // DDL auto-commits in MySQL, so statements run outside a transaction and
            // the version row is written only once every statement succeeded.
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            Db::insert('schema_migrations', ['version' => $version]);
            $ran[] = $version;
        }

        return $ran;
    }
}
