<?php

namespace App\Core;

/**
 * Mirrors application::encrypt()/decrypt() from the existing web app's
 * config.php exactly (AES-128-CTR, fixed key/iv) so market ids encrypted here
 * decrypt correctly on https://menuqrcode.tn/qrcode?data=... — this must stay
 * byte-for-byte compatible with the legacy implementation, not "improved".
 */
class Crypto
{
    private const CIPHER = 'AES-128-CTR';
    private const KEY = 'budha205';
    private const IV = '1234567890123456';

    public static function encrypt(string $value): string
    {
        return openssl_encrypt($value, self::CIPHER, self::KEY, 0, self::IV);
    }

    public static function decrypt(string $value): string
    {
        return openssl_decrypt($value, self::CIPHER, self::KEY, 0, self::IV);
    }
}
