<?php

declare(strict_types=1);

namespace FenPing\Api;

final readonly class Router
{
    /** @param list<Route> $routes */
    public function __construct(private array $routes)
    {
    }

    public function match(Request $request): RouteMatch
    {
        $segments = $request->segments();
        if ($segments === []) {
            throw new HttpException(404, 'not found');
        }

        $methodAllowed = false;
        $invalid = null;
        foreach ($this->routes as $route) {
            $match = $this->matchPath($route->pattern, $segments);
            if (!$match['matched']) {
                if ($match['invalid'] !== null && $route->method === $request->method && $invalid === null) {
                    $invalid = $match['invalid'];
                }
                continue;
            }
            if ($route->method !== $request->method) {
                $methodAllowed = true;
                continue;
            }
            return new RouteMatch($route, $match['params']);
        }

        if ($methodAllowed) {
            throw new HttpException(405, 'method not allowed');
        }
        if ($invalid !== null) {
            throw new HttpException(400, $invalid);
        }
        throw new HttpException(404, 'not found');
    }

    private function matchPath(string $pattern, array $segments): array
    {
        $parts = trim($pattern, '/') === '' ? [] : explode('/', trim($pattern, '/'));
        if (count($parts) !== count($segments)) {
            return ['matched' => false, 'invalid' => null, 'params' => []];
        }

        $params = [];
        foreach ($parts as $index => $part) {
            $segment = $segments[$index];
            if (!preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z][A-Za-z0-9_]*))?\}$/', $part, $matches)) {
                if ($part !== $segment) {
                    return ['matched' => false, 'invalid' => null, 'params' => []];
                }
                continue;
            }
            $converted = $this->convert($matches[2] ?? 'string', $segment);
            if (!$converted['ok']) {
                return ['matched' => false, 'invalid' => $converted['error'], 'params' => []];
            }
            $params[$matches[1]] = $converted['value'];
        }
        return ['matched' => true, 'invalid' => null, 'params' => $params];
    }

    private function convert(string $type, string $value): array
    {
        if ($type === 'int') {
            return ctype_digit($value)
                ? ['ok' => true, 'value' => (int) $value, 'error' => null]
                : ['ok' => false, 'value' => null, 'error' => 'invalid id'];
        }
        if ($type === 'ipv4') {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? ['ok' => true, 'value' => $value, 'error' => null]
                : ['ok' => false, 'value' => null, 'error' => 'invalid ip'];
        }
        if ($type === 'scanXml') {
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\.xml$/', $value, $matches)) {
                return ['ok' => false, 'value' => null, 'error' => 'invalid scan file'];
            }
            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? ['ok' => true, 'value' => $value, 'error' => null]
                : ['ok' => false, 'value' => null, 'error' => 'invalid ip'];
        }
        if ($type === 'scanIdXml') {
            return preg_match('/^\d+\.xml$/', $value)
                ? ['ok' => true, 'value' => $value, 'error' => null]
                : ['ok' => false, 'value' => null, 'error' => 'invalid scan id'];
        }
        return $value !== ''
            ? ['ok' => true, 'value' => $value, 'error' => null]
            : ['ok' => false, 'value' => null, 'error' => 'invalid value'];
    }
}
