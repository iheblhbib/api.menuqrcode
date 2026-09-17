<?php

namespace App\Core;

use App\Helpers\TextEncoding;

class Response
{
    public static function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function success($data = null, int $statusCode = 200, array $meta = []): void
    {
        // Normalizes double-encoded UTF-8 present throughout the legacy
        // database (see Helpers\TextEncoding) before it ever reaches a client.
        $payload = ['success' => true, 'data' => TextEncoding::fixArray($data)];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $statusCode);
    }

    public static function error(string $code, string $message, int $statusCode = 400, array $fields = []): void
    {
        $error = ['code' => $code, 'message' => $message];
        if (!empty($fields)) {
            $error['fields'] = $fields;
        }
        self::json(['success' => false, 'error' => $error], $statusCode);
    }
}
