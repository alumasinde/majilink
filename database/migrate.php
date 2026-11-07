<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use MajiLink\Database\Migrator;

$ran = Migrator::run(__DIR__ . '/migrations');

foreach ($ran as $version) {
    echo "Applied {$version}\n";
}

echo $ran === [] ? "Nothing to migrate.\n" : 'Done (' . count($ran) . " migration(s)).\n";
