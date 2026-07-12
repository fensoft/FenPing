<?php

declare(strict_types=1);

namespace FenPing\Api;

final class Request
{
    private ?array $parsedBody = null;

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input');
        return new self(
            method: (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            uri: (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            server: $_SERVER,
            cookies: $_COOKIE,
            rawBody: $raw === false ? '' : $raw,
        );
    }

    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }
        if (trim($this->rawBody) === '') {
            return $this->parsedBody = $this->post;
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'invalid json');
        }
        return $this->parsedBody = $data;
    }

    public function segments(): array
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        $path = rawurldecode($path === false ? '/' : $path);
        if (str_starts_with($path, '/api.php')) {
            $path = substr($path, strlen('/api.php'));
        } elseif (str_starts_with($path, '/api')) {
            $path = substr($path, strlen('/api'));
        }
        $path = trim($path, '/');
        return $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
    }
}
