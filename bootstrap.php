<?php
declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/src/Support/Env.php';
require_once BASE_PATH . '/src/Support/Config.php';

MajiLink\Support\Env::load(BASE_PATH . '/.env');

spl_autoload_register(function (string $class): void {
    $prefix = 'MajiLink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = BASE_PATH . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');

$config = require BASE_PATH . '/config/app.php';
MajiLink\Support\Config::set($config);

$dbConfig = require BASE_PATH . '/config/database.php';
MajiLink\Support\Db::init($dbConfig);

return $config;
