<?php
// Tiny router: method + path pattern with {name} placeholders → callable(array $params).
defined('DROP01') || exit;

final class Router {
    private array $routes = [];

    public function get(string $p, callable $h): void { $this->routes[] = ['GET', $p, $h]; }
    public function post(string $p, callable $h): void { $this->routes[] = ['POST', $p, $h]; }

    /** Dispatches; returns false when nothing matched. */
    public function dispatch(string $method, string $path): bool {
        foreach ($this->routes as [$m, $p, $h]) {
            if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) continue;
            $re = '#^' . preg_replace('#\\\{(\w+)\\\}#', '(?P<$1>[^/]+)', preg_quote($p, '#')) . '$#';
            if (preg_match($re, $path, $mm)) {
                $h(array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY));
                return true;
            }
        }
        return false;
    }
}
