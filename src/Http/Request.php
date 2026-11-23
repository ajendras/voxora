<?php

declare(strict_types=1);

namespace Voxora\Http;

/**
 * Simple immutable request wrapper for API and dashboard controllers.
 * Provides normalized header access, POST/GET data, uploaded files and body.
 */
final class Request
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $query = [];

    /** @var array<string, array<string, mixed>> */
    private array $files = [];

    public function __construct()
    {
        $this->headers = $this->parseHeaders($_SERVER);
        $this->post = $_POST;
        $this->query = $_GET;
        $this->files = $_FILES;
    }

    /**
     * @param array<string, string> $server
     * @return array<string, string>
     */
    private function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $server['CONTENT_TYPE'];
        }
        return $headers;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
        return $this->headers[$normalized] ?? null;
    }

    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPost(): array
    {
        return $this->post;
    }

    public function getQueryParam(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUploadedFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function getBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }
}
