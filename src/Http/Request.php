<?php

declare(strict_types=1);

namespace Voxora\Http;

use InvalidArgumentException;

/**
 * Request
 *
 * Lightweight immutable wrapper around HTTP request data to improve testability
 * and decouple controllers from superglobals. Supports query, post, files, headers
 * and JSON body. Used by ApiController and DashboardController actions.
 */
final class Request
{
    public function __construct(
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly array $headers = [],
        private readonly ?array $parsedJson = null
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if (is_string($value)) {
            return trim($value);
        }

        return $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    public function header(string $key, ?string $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $key));

        if (isset($this->headers[$key])) {
            return (string) $this->headers[$key];
        }

        if (isset($this->headers['HTTP_' . $key])) {
            return (string) $this->headers['HTTP_' . $key];
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? $this->header('X-Api-Key');

        if ($header && preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return $header;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function json(?string $key = null): mixed
    {
        if ($this->parsedJson === null) {
            return null;
        }

        if ($key === null) {
            return $this->parsedJson;
        }

        return $this->parsedJson[$key] ?? null;
    }

    public static function createFromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[substr($name, 5)] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[$name] = $value;
            }
        }

        $json = null;
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $input = file_get_contents('php://input');
            if ($input !== false) {
                $json = json_decode($input, true);
            }
        }

        return new self(
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            headers: $headers,
            parsedJson: $json
        );
    }
}
