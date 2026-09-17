<?php

namespace App\Helpers;

use App\Config\Env;

class Url
{
    /**
     * Turns a stored image path into an absolute URL. Handles three
     * conventions found in the real data:
     *  - already absolute ("https://app.menuqrcode.tn/img/x.png") → used as-is.
     *  - legacy "relative" paths that actually embed the production image
     *    host ("../app.menuqrcode.tn/img/x.png", from the old site's PHP
     *    include structure) → the leading "../" is noise, not real traversal.
     *  - our own uploads, relative to public/ (e.g. "uploads/menu-items/1/5.jpg").
     */
    public static function asset(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return $relativePath;
        }

        $trimmed = ltrim($relativePath, './');
        if (str_starts_with($trimmed, 'app.menuqrcode.tn/')) {
            return 'https://' . $trimmed;
        }

        $base = rtrim(Env::get('APP_URL', ''), '/');
        return $base . '/' . ltrim($relativePath, '/');
    }
}
