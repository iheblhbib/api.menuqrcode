<?php

namespace App\Helpers;

require_once dirname(__DIR__) . '/Vendor/qrcode.php';

/**
 * Basic (non-styled) QR PNG rendering, wrapping the vendored Kazuhiko Arase
 * QR encoder (public domain-equivalent MIT, see Vendor/qrcode.php) with GD
 * rasterization. Deliberately does not attempt to reproduce the "Custom"
 * dots/corners/gradient styling from the `qrcodes` table — see
 * docs/api-contract.md ("QR : rendu de l'image") for why that's deferred.
 */
class QrPngRenderer
{
    public static function renderPng(string $data, int $moduleSize = 12, int $marginModules = 4): string
    {
        // Level Q (not M): more error-correction headroom for scanning off a
        // phone/monitor screen (glare, moiré) rather than print.
        $qr = \QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_Q);
        $count = $qr->getModuleCount();
        $size = ($count + $marginModules * 2) * $moduleSize;

        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size, $size, $white);

        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if (!$qr->isDark($row, $col)) {
                    continue;
                }
                $x = ($col + $marginModules) * $moduleSize;
                $y = ($row + $marginModules) * $moduleSize;
                imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
