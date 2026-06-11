<?php
/**
 * Tiny logger that REDACTS secrets before anything is written.
 *
 * Security (docs/06 §3): API keys / tokens must never be logged. Every message
 * passes through redact() which masks Bearer tokens, sk-/gsk_ keys and obvious
 * "key=..."/"token=..." pairs. Falls back to error_log when PrestaShop's
 * PrestaShopLogger is unavailable (standalone CLI / tests).
 */

declare(strict_types=1);

namespace Daktela\Support;

final class Logger
{
    public static function info(string $msg): void
    {
        self::write(1, $msg);
    }

    public static function warn(string $msg): void
    {
        self::write(2, $msg);
    }

    public static function error(string $msg): void
    {
        self::write(3, $msg);
    }

    private static function write(int $severity, string $msg): void
    {
        $msg = self::redact($msg);
        if (class_exists(\PrestaShopLogger::class)) {
            \PrestaShopLogger::addLog('[daktela] ' . $msg, $severity);
            return;
        }
        error_log('[daktela] ' . $msg);
    }

    /** Mask anything that looks like a secret. Always applied before output. */
    public static function redact(string $s): string
    {
        $patterns = [
            // Authorization: Bearer xxxxx
            '/(Bearer\s+)[A-Za-z0-9._\-]+/i' => '$1***',
            // Provider key formats: sk-..., gsk_..., xoxb-...
            '/\b((?:sk|gsk|xoxb|pat)[-_])[A-Za-z0-9._\-]{6,}/i' => '$1***',
            // key=... / token=... / accessToken=... in URLs or text
            '/((?:api[_-]?key|access[_-]?token|token|key)\s*[=:]\s*)[A-Za-z0-9._\-]{6,}/i' => '$1***',
        ];
        foreach ($patterns as $re => $rep) {
            $s = (string) preg_replace($re, $rep, $s);
        }
        return $s;
    }
}
