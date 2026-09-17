<?php

namespace App\Helpers;

use App\Core\ValidationException;

class Upload
{
    private const ALLOWED_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Stores an uploaded image under public/uploads/{subdir}/{filenameWithoutExt}.{ext},
     * overwriting any previous file for that name (any extension). Returns the
     * path relative to public/, suitable for building a public URL from.
     */
    public static function storeImage(array $file, string $subdir, string $filenameWithoutExt): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'Upload failed'], 'Image upload failed');
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new ValidationException(['image' => 'File too large (max 5MB)'], 'Image upload failed');
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME_TO_EXT[$mime])) {
            throw new ValidationException(['image' => 'Only JPEG, PNG or WEBP images are allowed'], 'Image upload failed');
        }
        $ext = self::ALLOWED_MIME_TO_EXT[$mime];

        $publicRoot = dirname(__DIR__, 2) . '/public';
        $targetDir = $publicRoot . '/uploads/' . trim($subdir, '/');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        self::removeExistingVariants($targetDir, $filenameWithoutExt);

        $relativePath = 'uploads/' . trim($subdir, '/') . '/' . $filenameWithoutExt . '.' . $ext;
        $destination = $publicRoot . '/' . $relativePath;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new ValidationException(['image' => 'Could not save uploaded file'], 'Image upload failed');
        }

        return $relativePath;
    }

    private static function removeExistingVariants(string $dir, string $filenameWithoutExt): void
    {
        foreach (self::ALLOWED_MIME_TO_EXT as $ext) {
            $existing = "$dir/$filenameWithoutExt.$ext";
            if (is_file($existing)) {
                unlink($existing);
            }
        }
    }
}
