<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/**
 * Free machine translation proxy (MyMemory Translation API — no key
 * required). translate.googleapis.com was tried first but blocks
 * server/hosting IPs outright ("Sorry... automated queries"), which is why
 * the existing web app already uses MyMemory instead.
 *
 * More robust than the legacy web app's api/translate.php it's modeled on:
 * each language gets up to MAX_ATTEMPTS tries on a transient failure (network
 * error, unexpected response), but a quota/rate-limit response (HTTP 429, or
 * MyMemory's own "MYMEMORY WARNING" marker) is never retried — retrying that
 * just burns more of the shared daily quota for no benefit. Every target
 * language gets its own success or error entry — one bad language never
 * blocks the others.
 */
class TranslateController
{
    private const MAX_ATTEMPTS = 2;
    private const RETRY_DELAY_MICROS = 400_000;

    public function translate(Request $request): void
    {
        $text = trim((string) $request->input('text'));
        $targets = $request->input('targets');
        $source = (string) $request->input('source', 'fr');

        if ($text === '' || !is_array($targets) || empty($targets)) {
            throw new ValidationException(['targets' => 'This field is required']);
        }

        $translations = [];
        $errors = [];

        foreach ($targets as $rawCode) {
            $code = preg_replace('/[^a-zA-Z-]/', '', (string) $rawCode);
            if ($code === '' || strcasecmp($code, $source) === 0) {
                continue;
            }

            $result = $this->translateWithRetry($text, $source, $code);
            if ($result['text'] !== null) {
                $translations[$code] = $result['text'];
            } else {
                $errors[$code] = $result['error'] ?? 'Échec de traduction';
            }
        }

        Response::success(['translations' => $translations, 'errors' => $errors]);
    }

    /** @return array{text: ?string, error: ?string} */
    private function translateWithRetry(string $text, string $source, string $target): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->callMyMemory($text, $source, $target);
            if ($result['text'] !== null) {
                return $result;
            }

            $lastError = $result['error'];
            if ($result['isQuota'] || $attempt >= self::MAX_ATTEMPTS) {
                break;
            }
            usleep(self::RETRY_DELAY_MICROS);
        }

        return ['text' => null, 'error' => $lastError];
    }

    /** @return array{text: ?string, error: ?string, isQuota: bool} */
    private function callMyMemory(string $text, string $source, string $target): array
    {
        $url = 'https://api.mymemory.translated.net/get'
            . '?q=' . urlencode($text)
            . '&langpair=' . urlencode($source . '|' . $target);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return ['text' => null, 'error' => 'Connexion : ' . $curlError, 'isQuota' => false];
        }

        if ($httpCode === 429) {
            return ['text' => null, 'error' => 'Quota de traduction atteint, réessayez plus tard', 'isQuota' => true];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['responseData']['translatedText'])) {
            return ['text' => null, 'error' => 'Réponse inattendue (HTTP ' . $httpCode . ')', 'isQuota' => false];
        }

        $status = (int) ($data['responseStatus'] ?? 0);
        if ($status === 429) {
            return ['text' => null, 'error' => 'Quota de traduction atteint, réessayez plus tard', 'isQuota' => true];
        }
        if ($status !== 200) {
            return ['text' => null, 'error' => (string) ($data['responseDetails'] ?? ('HTTP ' . $httpCode)), 'isQuota' => false];
        }

        $translated = (string) $data['responseData']['translatedText'];
        // The exact marker MyMemory returns instead of a real translation
        // once the shared anonymous-tier quota is exhausted for the day.
        if (str_contains($translated, 'MYMEMORY WARNING')) {
            return ['text' => null, 'error' => 'Quota de traduction (MyMemory) dépassé pour aujourd\'hui', 'isQuota' => true];
        }

        return ['text' => $translated, 'error' => null, 'isQuota' => false];
    }
}
