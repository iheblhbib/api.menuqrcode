<?php

namespace App\Core;

use App\Config\Env;

class Jwt
{
    public static function encode(array $claims, int $ttlSeconds): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @throws AuthException when the token is malformed, mis-signed, or expired.
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthException('Malformed token', 'TOKEN_INVALID');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expectedSignature = hash_hmac('sha256', "$encodedHeader.$encodedPayload", self::secret(), true);
        $actualSignature = self::base64UrlDecode($encodedSignature);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new AuthException('Invalid token signature', 'TOKEN_INVALID');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($payload)) {
            throw new AuthException('Malformed token payload', 'TOKEN_INVALID');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new AuthException('Token expired', 'TOKEN_EXPIRED');
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if (!$secret) {
            throw new ApiException('JWT_SECRET is not configured', 500, 'SERVER_MISCONFIGURED');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode(strtr($padded, '-_', '+/')) ?: '';
    }
}
