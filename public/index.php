<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MajiLink\Http\HttpException;
use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Http\Router;
use MajiLink\Support\Config;

$request = Request::fromGlobals();
Response::cors();

if ($request->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();
(require BASE_PATH . '/config/routes.php')($router);

try {
    $router->dispatch($request);
} catch (HttpException $e) {
    Response::error($e->status, $e->errorCode, $e->getMessage(), $e->fields);
} catch (\Throwable $e) {
    $log = (string) Config::get('log_dir') . '/api-errors.log';
    @file_put_contents($log, sprintf("[%s] %s %s: %s\n%s\n", gmdate('c'), $request->method, $request->path, $e->getMessage(), $e->getTraceAsString()), FILE_APPEND | LOCK_EX);
    $message = Config::get('debug') ? $e->getMessage() : 'Something went wrong on our side.';
    Response::error(500, 'server_error', $message);
}
