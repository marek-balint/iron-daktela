<?php
/**
 * Builds the right LLM client per tier from configuration.
 *
 * Tier 1 = cheap/fast triage for every ticket; Tier 2 = stronger model for
 * money/complaint/complex tickets (docs/04). Provider is chosen by
 * DAKTELA_AI_PROVIDER (anthropic = docs default; groq = what we run now).
 */

declare(strict_types=1);

namespace Daktela\Llm;

use Daktela\Support\ModuleConfig;

final class LlmClientFactory
{
    public static function provider(): string
    {
        $p = strtolower(trim(ModuleConfig::get('DAKTELA_AI_PROVIDER', 'anthropic')));
        return in_array($p, ['anthropic', 'groq'], true) ? $p : 'anthropic';
    }

    public static function tier1(): LlmClientInterface
    {
        return self::build(1);
    }

    public static function tier2(): LlmClientInterface
    {
        return self::build(2);
    }

    private static function build(int $tier): LlmClientInterface
    {
        $suffix = $tier === 2 ? 'TIER2' : 'TIER1';
        if (self::provider() === 'groq') {
            return new GroqClient(
                ModuleConfig::get('GROQ_API_KEY'),
                ModuleConfig::get('GROQ_MODEL_' . $suffix),
                ModuleConfig::get('GROQ_BASE_URL')
            );
        }
        return new AnthropicClient(
            ModuleConfig::get('ANTHROPIC_API_KEY'),
            ModuleConfig::get('ANTHROPIC_MODEL_' . $suffix),
            ModuleConfig::get('ANTHROPIC_BASE_URL')
        );
    }

    /** True when the active provider has an API key configured. */
    public static function isConfigured(): bool
    {
        return self::provider() === 'groq'
            ? ModuleConfig::has('GROQ_API_KEY')
            : ModuleConfig::has('ANTHROPIC_API_KEY');
    }
}
