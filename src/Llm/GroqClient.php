<?php
/**
 * Groq client — OpenAI-compatible Chat Completions API.
 *
 * Used now because Groq is the only provider key we currently have. Strict JSON
 * is coaxed via JSON mode (response_format={"type":"json_object"}) plus the
 * schema in the system prompt; the result is still validated against closed
 * enums downstream (docs/06 §5), so a malformed/hostile field can't widen
 * behaviour. NOTE: Groq model IDs change — set GROQ_MODEL_TIER1/2 to models your
 * account actually serves (see README / `php cli/sync.php --list-models`).
 */

declare(strict_types=1);

namespace Daktela\Llm;

use Daktela\Support\Http;
use Daktela\Support\Logger;

final class GroqClient implements LlmClientInterface
{
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
        return 'groq';
    }

    public function extractJson(string $system, string $userContent, array $schema, string $toolName, int $maxTokens = 1024): array
    {
        if ($this->apiKey === '') {
            throw new LlmException('GROQ_API_KEY is not set.');
        }

        $schemaText = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $systemFull = $system
            . "\n\nReturn ONLY a single JSON object (no prose, no markdown) that matches this JSON schema:\n"
            . $schemaText;

        $body = [
            'model' => $this->model,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemFull],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        [$status, $resp] = Http::postJson(
            $this->endpoint('chat/completions'),
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $body
        );

        if ($status !== 200) {
            $msg = $resp['error']['message'] ?? ('HTTP ' . $status);
            throw new LlmException('Groq error: ' . Logger::redact((string) $msg));
        }

        $content = $resp['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new LlmException('Groq returned an empty completion.');
        }

        $json = self::decodeLooseJson($content);
        if ($json === null) {
            throw new LlmException('Groq output was not valid JSON.');
        }
        return $json;
    }

    /** Diagnostics: list models the key can use. */
    public function listModels(): array
    {
        [$status, $resp] = Http::getJson(
            $this->endpoint('models'),
            ['Authorization' => 'Bearer ' . $this->apiKey]
        );
        if ($status !== 200) {
            throw new LlmException('Groq /models error: HTTP ' . $status);
        }
        $ids = [];
        foreach (($resp['data'] ?? []) as $m) {
            if (isset($m['id'])) {
                $ids[] = (string) $m['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** Parse JSON; if the model wrapped it in prose/fences, extract the object. */
    private static function decodeLooseJson(string $s): ?array
    {
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
