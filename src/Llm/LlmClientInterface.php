<?php
/**
 * Provider-agnostic "give me a JSON object" contract.
 *
 * docs/04 mandates Anthropic Claude for production. We add a second provider
 * (Groq) behind this interface so the pipeline runs TODAY with the only key we
 * have. The downstream code (ClassificationService, scoring) never knows or
 * cares which model produced the JSON — exactly the doc's "output schema stays
 * identical across tiers" guarantee.
 */

declare(strict_types=1);

namespace Daktela\Llm;

interface LlmClientInterface
{
    /**
     * Return ONLY a JSON object that conforms to $schema.
     *
     * @param string               $system       system / instruction text
     * @param string               $userContent  the (PII-scrubbed) data to classify
     * @param array<string,mixed>  $schema       JSON-schema for the object
     * @param string               $toolName     logical name (used as the Anthropic tool name)
     * @return array<string,mixed> decoded JSON object the model produced
     * @throws LlmException on transport, auth, or unparseable output
     */
    public function extractJson(string $system, string $userContent, array $schema, string $toolName, int $maxTokens = 1024): array;

    /** The concrete model id this client calls (for the stored model_used field). */
    public function model(): string;

    /** Provider label, e.g. "anthropic" / "groq". */
    public function provider(): string;
}
