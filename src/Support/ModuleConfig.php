<?php
/**
 * Configuration accessor with a clear precedence:
 *
 *   process env  >  module-root .env  >  PrestaShop Configuration  >  default
 *
 * This lets cli/sync.php run standalone (reads .env, no PrestaShop) while the
 * installed module reads from Configuration. Secrets live here, never in code or
 * logs (docs/06 §3); the .env file is git-ignored.
 */

declare(strict_types=1);

namespace Daktela\Support;

final class ModuleConfig
{
    /** @var array<string,string>|null parsed .env cache */
    private static ?array $dotenv = null;

    public const DEFAULTS = [
        'DAKTELA_USE_MOCK' => '1',
        'DAKTELA_BASE_URL' => '',
        'DAKTELA_AI_PROVIDER' => 'anthropic', // production default per docs/04; dev .env sets groq
        'GROQ_BASE_URL' => 'https://api.groq.com/openai/v1/',
        'GROQ_MODEL_TIER1' => 'llama-3.1-8b-instant',
        'GROQ_MODEL_TIER2' => 'llama-3.3-70b-versatile',
        'ANTHROPIC_BASE_URL' => 'https://api.anthropic.com/v1/',
        'ANTHROPIC_MODEL_TIER1' => 'claude-haiku-4-5-20251001',
        'ANTHROPIC_MODEL_TIER2' => 'claude-sonnet-4-6',
        'DAKTELA_SCORE_MODE' => 'ai',
        'DAKTELA_W_SLA' => '0.30',
        'DAKTELA_W_VALUE' => '0.25',
        'DAKTELA_W_SENTIMENT' => '0.20',
        'DAKTELA_W_CATEGORY' => '0.15',
        'DAKTELA_W_URGENCY' => '0.10',
        'DAKTELA_SYNC_MAX' => '100',
        'DAKTELA_GROUNDING' => '1',
    ];

    /** Configuration keys the module owns (used by uninstall cleanup). */
    public const OWNED_KEYS = [
        'DAKTELA_USE_MOCK', 'DAKTELA_BASE_URL', 'DAKTELA_ACCESS_TOKEN',
        'DAKTELA_AI_PROVIDER',
        'GROQ_API_KEY', 'GROQ_BASE_URL', 'GROQ_MODEL_TIER1', 'GROQ_MODEL_TIER2',
        'ANTHROPIC_API_KEY', 'ANTHROPIC_BASE_URL', 'ANTHROPIC_MODEL_TIER1', 'ANTHROPIC_MODEL_TIER2',
        'DAKTELA_SCORE_MODE',
        'DAKTELA_W_SLA', 'DAKTELA_W_VALUE', 'DAKTELA_W_SENTIMENT', 'DAKTELA_W_CATEGORY', 'DAKTELA_W_URGENCY',
        'DAKTELA_SYNC_MAX', 'DAKTELA_GROUNDING',
    ];

    public static function get(string $key, ?string $default = null): string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $dot = self::dotenv();
        if (isset($dot[$key]) && $dot[$key] !== '') {
            return $dot[$key];
        }
        if (class_exists(\Configuration::class)) {
            $val = \Configuration::get($key);
            if ($val !== false && $val !== null && $val !== '') {
                return (string) $val;
            }
        }
        if ($default !== null) {
            return $default;
        }
        return self::DEFAULTS[$key] ?? '';
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(trim(self::get($key, $default ? '1' : '0')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::get($key, (string) $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    /** True if the named secret is configured (without revealing it). */
    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    public static function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,string> */
    private static function dotenv(): array
    {
        if (self::$dotenv !== null) {
            return self::$dotenv;
        }
        self::$dotenv = [];
        $path = self::moduleRoot() . DIRECTORY_SEPARATOR . '.env';
        if (!is_readable($path)) {
            return self::$dotenv;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ((str_starts_with($v, '"') && str_ends_with($v, '"'))
                || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') {
                self::$dotenv[$k] = $v;
            }
        }
        return self::$dotenv;
    }
}
