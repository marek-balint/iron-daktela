<?php
/**
 * Anthropic Claude client — the documented production provider (docs/04).
 *
 * Strict JSON via forced tool use: we declare a single tool whose input_schema
 * IS the output schema and force tool_choice to it, so Claude must emit a
 * well-typed object. The static system prompt + schema carry a cache_control
 * breakpoint for prompt caching (docs/04 "mark the system prompt cacheable").
 *
 * Wire contract (endpoint, anthropic-version header, tool use, cache_control)
 * per the Anthropic Messages API. Self-contained cURL — see Http.php for why.
 */

declare(strict_types=1);

namespace Daktela\Llm;

use Daktela\Support\Http;
use Daktela\Support\Logger;

final class AnthropicClient implements LlmClientInterface
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function extractJson(string $system, string $userContent, array $schema, string $toolName, int $maxTokens = 1024): array
    {
        if ($this->apiKey === '') {
            throw new LlmException('ANTHROPIC_API_KEY is not set.');
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => [[
                'name' => $toolName,
                'description' => 'Record the structured classification for this support ticket.',
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            'messages' => [[
                'role' => 'user',
                'content' => $userContent,
            ]],
        ];

        [$status, $resp] = Http::postJson(
            $this->endpoint('messages'),
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            $body
        );

        if ($status !== 200) {
            $msg = $resp['error']['message'] ?? ('HTTP ' . $status);
            throw new LlmException('Anthropic error: ' . Logger::redact((string) $msg));
        }

        foreach (($resp['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use'
                && ($block['name'] ?? '') === $toolName
                && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new LlmException('Anthropic did not return the expected tool_use block.');
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
