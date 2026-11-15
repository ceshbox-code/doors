<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    public function get(string $pattern, callable $handler): void   { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void  { $this->add('POST', $pattern, $handler); }
    public function patch(string $pattern, callable $handler): void { $this->add('PATCH', $pattern, $handler); }
    public function put(string $pattern, callable $handler): void   { $this->add('PUT', $pattern, $handler); }
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                return $handler($request->withParams($params));
            } catch (ApiException $e) {
                return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }
        return $pathMatched
            ? Response::error('METHOD_NOT_ALLOWED', 'Метод не поддерживается', 405)
            : Response::error('NOT_FOUND', 'Ресурс не найден', 404);
    }
}
