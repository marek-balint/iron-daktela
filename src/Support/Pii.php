<?php
/**
 * PII / secret minimisation before text leaves our system to an AI provider.
 *
 * docs/06 §6 + docs/04 "Privacy": send only what classification needs, and strip
 * obvious payment card numbers / passwords first. A successful injection must
 * stay low-impact; over-exposure of customer data must not happen.
 */

declare(strict_types=1);

namespace Daktela\Support;

final class Pii
{
    /** Trim + strip obvious secrets from free text headed to the model. */
    public static function scrub(string $text, int $maxLen = 4000): string
    {
        // Credit-card-like sequences (13–19 digits, optional spaces/dashes).
        $text = (string) preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[redacted-number]', $text);
        // IBANs.
        $text = (string) preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', '[redacted-iban]', $text);
        // "password: xxxx" / "heslo: xxxx".
        $text = (string) preg_replace('/((?:password|passwd|pwd|heslo)\s*[:=]\s*)\S+/i', '$1[redacted]', $text);
        // CVV-ish hints.
        $text = (string) preg_replace('/((?:cvv|cvc|cvv2)\s*[:=]?\s*)\d{3,4}\b/i', '$1[redacted]', $text);

        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLen);
        }
        return substr($text, 0, $maxLen);
    }
}
