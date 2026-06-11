<?php
/**
 * Minimal JSON-over-HTTPS helper (cURL).
 *
 * Why hand-rolled and not an SDK: this module must be a self-contained, dropped-in
 * PrestaShop module (CLAUDE.md hard constraint) that installs without `composer
 * install` on the server, and must not collide with the Guzzle version PrestaShop
 * core bundles. A thin cURL wrapper also lets us pin TLS verification and control
 * exactly what is (never) logged.
 *
 * Security (docs/06 §7): TLS peer + host verification are ON and must stay on.
 */

declare(strict_types=1);

namespace Daktela\Support;

final class Http
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body     JSON-encoded as the request body
     * @return array{0:int,1:array<mixed>}   [httpStatus, decodedJson]
     * @throws \RuntimeException on transport failure or non-JSON body
     */
    public static function postJson(string $url, array $headers, array $body, int $timeout = 60): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required.');
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode request body as JSON.');
        }

        $h = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            // docs/06 §7 — never disable these to "make it work".
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // Error string may echo the URL (which can contain a token) — redact.
            throw new \RuntimeException('HTTP request failed: ' . Logger::redact($err));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $snippet = Logger::redact(substr((string) $raw, 0, 300));
            throw new \RuntimeException("Non-JSON response (HTTP $status): " . $snippet);
        }

        return [$status, $decoded];
    }

    /** GET helper (used to list provider models for diagnostics). */
    public static function getJson(string $url, array $headers, int $timeout = 30): array
    {
        $h = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('HTTP GET failed: ' . Logger::redact($err));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Non-JSON response (HTTP $status).");
        }
        return [$status, $decoded];
    }
}
