<?php
declare(strict_types=1);

namespace MajiLink\Http;

use MajiLink\Middleware\Authenticator;
use MajiLink\Middleware\RuntimeGate;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:callable,options:array,pattern:string}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('PUT', $pattern, $handler, $options);
    }

    public function delete(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('DELETE', $pattern, $handler, $options);
    }

    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/')) . '/?$#';
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $regex,
            'handler' => $handler,
            'options' => $options,
            'pattern' => $pattern,
        ];
    }

    public function dispatch(Request $request): void
    {
        $path = rtrim($request->path, '/') ?: '/';
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $options = $route['options'];

            RuntimeGate::check($request);

            if (!empty($options['auth'])) {
                Authenticator::authenticate($request);
            }
            if (!empty($options['permission'])) {
                Authenticator::require((string) $options['permission']);
            }

            self::invoke($route['handler'], $request, $params);
            return;
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed for this endpoint.', ['allow' => implode(', ', array_unique($allowed))]);
        }
        throw HttpException::notFound('No route matches ' . $request->method . ' ' . $path);
    }

    /**
     * Handlers may take (Request $request, array $params), (Request $request) or
     * (array $params); the declared signature decides what is passed.
     */
    private static function invoke(callable $handler, Request $request, array $params): void
    {
        $reflection = is_array($handler)
            ? new \ReflectionMethod($handler[0], $handler[1])
            : new \ReflectionFunction($handler);

        if ($reflection->getNumberOfParameters() === 0) {
            $handler();
            return;
        }
        $firstType = $reflection->getParameters()[0]->getType()?->getName() ?? '';
        if (str_contains($firstType, 'Request')) {
            $handler($request, $params);
            return;
        }
        $handler($params);
    }
}
