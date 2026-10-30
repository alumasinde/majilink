<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server:
 *   php -S localhost:8099 -t public public/router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

if ($uri === '/') {
    header('Location: /site/');
    return true;
}

if (str_starts_with($uri, '/admin')) {
    require __DIR__ . '/admin/index.php';
    return true;
}

// Static assets (landing page CSS, images) are served by the built-in server.
return false;
