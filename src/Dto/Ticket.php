<?php
/**
 * In-memory representation of one Daktela ticket as it flows through the
 * pipeline: pulled -> enriched -> classified -> scored. Storage-agnostic so the
 * same object works in the standalone CLI and inside PrestaShop.
 */

declare(strict_types=1);

namespace Daktela\Dto;

final class Ticket
{
    // --- Identity / source (docs/03) ---
    public string $daktelaName = '';      // stable Daktela id — idempotency key
    public string $title = '';
    public string $stage = '';
    public string $contactEmail = '';
    public string $contactName = '';
    public ?string $createdRemote = null; // ISO-ish timestamps from Daktela
    public ?string $editedRemote = null;

    // --- Conversation state (docs/03 "waiting / no response") ---
    public ?int $lastInboundAt = null;    // epoch seconds
    public ?int $lastOutboundAt = null;
    public bool $waiting = false;
    public int $waitSeconds = 0;
    public string $latestInboundText = ''; // the message awaiting a reply

    // --- Customer enrichment (docs/02 §3, docs/05) ---
    public ?int $idCustomer = null;
    public int $orderCount = 0;
    public float $totalSpend = 0.0;
    public bool $hasOpenOrder = false;

    // --- AI classification (docs/04 output schema) ---
    public string $category = 'other';
    public bool $isQuestion = true;
    public string $urgency = 'medium';
    public string $sentiment = 'neutral';
    public string $language = '';
    public string $summary = '';
    /** @var string[] */
    public array $keyPhrases = [];
    public float $confidence = 0.0;
    public string $complexity = 'low';
    public bool $needsDeepReasoning = false;
    public string $modelUsed = '';
    public bool $classifyFailed = false;

    // --- Draft reply (docs/04 catalog grounding) ---
    public ?string $suggestedAnswer = null;
    public bool $answerGrounded = false;
    /** @var int[] */
    public array $productsReferenced = [];
    public bool $needsHuman = true;
    public string $draftModel = '';

    // --- Score (docs/05) ---
    public int $compSentiment = 0;
    public int $compCategory = 0;
    public int $compUrgency = 0;
    public int $compValue = 0;
    public int $compSla = 0;
    public int $aiScore = 0;
    public ?int $manualScore = null;
    public ?string $manualScoreBy = null;
    public ?string $manualScoreAt = null;
    public int $effectiveScore = 0;
    /** @var array<string,float> */
    public array $weights = [];
    public bool $flagged = false;

    /** Hash of the classified text — only re-classify when it changes (docs/03/04). */
    public function contentHash(): string
    {
        return hash('sha256', $this->title . "\n" . $this->latestInboundText);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'daktela_name' => $this->daktelaName,
            'title' => $this->title,
            'stage' => $this->stage,
            'contact_email' => $this->contactEmail,
            'contact_name' => $this->contactName,
            'id_customer' => $this->idCustomer,
            'waiting' => $this->waiting,
            'wait_seconds' => $this->waitSeconds,
            'order_count' => $this->orderCount,
            'total_spend' => $this->totalSpend,
            'has_open_order' => $this->hasOpenOrder,
            'category' => $this->category,
            'is_question' => $this->isQuestion,
            'urgency' => $this->urgency,
            'sentiment' => $this->sentiment,
            'language' => $this->language,
            'summary' => $this->summary,
            'key_phrases' => $this->keyPhrases,
            'confidence' => $this->confidence,
            'complexity' => $this->complexity,
            'model_used' => $this->modelUsed,
            'suggested_answer' => $this->suggestedAnswer,
            'answer_grounded' => $this->answerGrounded,
            'products_referenced' => $this->productsReferenced,
            'needs_human' => $this->needsHuman,
            'components' => [
                'sentiment' => $this->compSentiment,
                'category' => $this->compCategory,
                'urgency' => $this->compUrgency,
                'value' => $this->compValue,
                'sla' => $this->compSla,
            ],
            'ai_score' => $this->aiScore,
            'manual_score' => $this->manualScore,
            'effective_score' => $this->effectiveScore,
            'weights' => $this->weights,
            'flagged' => $this->flagged,
        ];
    }
}
