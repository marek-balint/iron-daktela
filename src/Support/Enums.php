<?php
/**
 * Closed enums + scoring point maps.
 *
 * Security (docs/06 §5 prompt injection): the AI output is untrusted. These are
 * the ONLY accepted values; anything off-list is mapped to a safe default by
 * ClassificationService, so a prompt injection can at worst mislabel a ticket.
 */

declare(strict_types=1);

namespace Daktela\Support;

final class Enums
{
    public const CATEGORIES = [
        'order_status', 'payment', 'shipping', 'returns_refunds',
        'product_question', 'complaint', 'account', 'other',
    ];

    public const URGENCIES = ['low', 'medium', 'high', 'critical'];

    public const SENTIMENTS = ['angry', 'frustrated', 'neutral', 'satisfied'];

    public const COMPLEXITIES = ['low', 'medium', 'high'];

    /** Categories that always escalate to the stronger model (docs/04 routing). */
    public const TIER2_CATEGORIES = ['payment', 'order_status', 'returns_refunds', 'complaint'];

    /** docs/05 — Category business weight (0–100). */
    public const CATEGORY_POINTS = [
        'payment' => 100,
        'complaint' => 90,
        'order_status' => 85,
        'returns_refunds' => 80,
        'shipping' => 60,
        'account' => 40,
        'product_question' => 35,
        'other' => 20,
    ];

    /** docs/05 — Sentiment (0–100). */
    public const SENTIMENT_POINTS = [
        'angry' => 100,
        'frustrated' => 70,
        'neutral' => 30,
        'satisfied' => 0,
    ];

    /** docs/05 — Urgency (0–100). */
    public const URGENCY_POINTS = [
        'critical' => 100,
        'high' => 75,
        'medium' => 45,
        'low' => 15,
    ];

    public static function categoryOrOther(?string $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($v, self::CATEGORIES, true) ? $v : 'other';
    }

    public static function urgencyOr(string $default, ?string $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($v, self::URGENCIES, true) ? $v : $default;
    }

    public static function sentimentOr(string $default, ?string $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($v, self::SENTIMENTS, true) ? $v : $default;
    }

    public static function complexityOr(string $default, ?string $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($v, self::COMPLEXITIES, true) ? $v : $default;
    }
}
