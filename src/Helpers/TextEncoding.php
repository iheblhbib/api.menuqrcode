<?php

namespace App\Helpers;

/**
 * Fixes double-encoded UTF-8 text found throughout the existing production
 * database (confirmed on ~70-80% of categorie/article/user rows: accented
 * French text was UTF-8 bytes stored via a latin1-assuming connection, then
 * re-encoded to UTF-8 again — e.g. "Hôtel" became "HÃ´tel"). This does NOT
 * touch the database; it normalizes text on the way out of the API only.
 *
 * Same root cause as the well-known "Ã©"-style French mojibake, but not
 * limited to it: the same single mis-conversion also mangles non-Latin1
 * scripts (Cyrillic, Arabic, CJK, Hangul, Czech, etc. — confirmed live on
 * the `langue` catalog, e.g. "Русский" became "Ð ÑƒÑÑÐºÐ¸Ð¹"), just with a
 * different byte signature per script that a narrow "contains Ã or Â"
 * check would miss. Detection here is therefore round-trip-based rather
 * than signature-based:
 *  1. Reinterpret the string's Unicode codepoints as Windows-1252 byte
 *     values (undoing "UTF-8 bytes read as Windows-1252, then re-encoded").
 *     MySQL's "latin1" charset is actually cp1252, so this must be
 *     Windows-1252, not strict ISO-8859-1, to also undo bytes in the
 *     0x80-0x9F range (É, È, œ, "smart" punctuation, curly quotes, etc.).
 *  2. Convert that candidate byte string back through the same
 *     Windows-1252→UTF-8 transform and require it to reproduce the
 *     original string exactly. If step 1 had to lossily substitute any
 *     character (which it does silently, without an error, for characters
 *     outside Windows-1252's repertoire — e.g. real Cyrillic or CJK text),
 *     the round trip will NOT match, correctly rejecting the "fix" instead
 *     of corrupting genuine text.
 *  3. Only apply the candidate if it's itself valid UTF-8 and different
 *     from the input.
 * This combination has been verified against real corrupted samples from
 * every one of the above scripts, and against genuinely-correct text in
 * each of those scripts (which never round-trips, so it's always left
 * untouched) — see docs/api-contract.md.
 *
 * Known limitation: a small number of rows were apparently re-encoded more
 * than once (double mojibake); a single fix() pass won't fully repair those.
 */
class TextEncoding
{
    public static function fix(?string $value): ?string
    {
        if ($value === null || $value === '' || !preg_match('/[\x80-\xFF]/', $value)) {
            return $value; // pure ASCII can't be affected — cheap bail-out
        }

        $forward = @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        if ($forward === false || $forward === $value || !mb_check_encoding($forward, 'UTF-8')) {
            return $value;
        }

        // Round-trip check: catches any lossy '?' substitution mb_convert_encoding
        // performs silently for codepoints outside Windows-1252 (e.g. real Cyrillic).
        $backward = @mb_convert_encoding($forward, 'UTF-8', 'Windows-1252');
        if ($backward !== $value) {
            return $value;
        }

        return $forward;
    }

    /** Recursively applies fix() to every string in an array (API response payloads). */
    public static function fixArray($data)
    {
        if (is_string($data)) {
            return self::fix($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::fixArray($value);
            }
        }
        return $data;
    }
}
