<?php

namespace App\Core;

class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $params = [];
    public array $headers;
    public ?array $auth = null;
    public array $files;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = rtrim(strtok($uri, '?') ?: '/', '/');
        if ($this->path === '') {
            $this->path = '/';
        }

        $this->query = $_GET ?? [];
        $this->headers = self::extractHeaders();
        $this->body = $this->parseBody();
        $this->files = $_FILES ?? [];
    }

    private static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        // PHP excludes Content-Type/Content-Length from HTTP_* and exposes them
        // directly as CONTENT_TYPE/CONTENT_LENGTH instead (CGI spec quirk).
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['CONTENT-LENGTH'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    private function parseBody(): array
    {
        $contentType = $this->headers['CONTENT-TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST ?? [];
    }

    public function bearerToken(): ?string
    {
        $authHeader = $this->headers['AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
