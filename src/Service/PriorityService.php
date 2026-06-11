<?php
/**
 * Priority scoring (docs/05) — the heart of it. Blends AI signals + customer
 * value + SLA pressure into a 0–100 score using config-tunable weights. Stores
 * each component so the ranking stays explainable ("why is this #1?").
 */

declare(strict_types=1);

namespace Daktela\Service;

use Daktela\Dto\Ticket;
use Daktela\Support\Enums;
use Daktela\Support\ModuleConfig;

final class PriorityService
{
    /** @var array<string,float> */
    private array $w;

    private const SPEND_CEILING = 1000.0;       // €: lifetime spend that maxes spend_points
    private const SLA_RAMP_SECONDS = 48 * 3600; // wait time over which time_pressure ramps 0→60

    public function __construct()
    {
        $this->w = [
            'sla' => ModuleConfig::float('DAKTELA_W_SLA', 0.30),
            'value' => ModuleConfig::float('DAKTELA_W_VALUE', 0.25),
            'sentiment' => ModuleConfig::float('DAKTELA_W_SENTIMENT', 0.20),
            'category' => ModuleConfig::float('DAKTELA_W_CATEGORY', 0.15),
            'urgency' => ModuleConfig::float('DAKTELA_W_URGENCY', 0.10),
        ];
    }

    public function score(Ticket $t): void
    {
        $t->compSentiment = Enums::SENTIMENT_POINTS[$t->sentiment] ?? 30;
        $t->compCategory = Enums::CATEGORY_POINTS[$t->category] ?? 20;
        $t->compUrgency = Enums::URGENCY_POINTS[$t->urgency] ?? 45;
        $t->compValue = $this->customerValue($t);
        $t->compSla = $this->slaPressure($t);

        $weightedSum =
            $this->w['sentiment'] * $t->compSentiment +
            $this->w['category'] * $t->compCategory +
            $this->w['urgency'] * $t->compUrgency +
            $this->w['value'] * $t->compValue +
            $this->w['sla'] * $t->compSla;

        $weightTotal = array_sum($this->w);
        $score = $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;

        // Hard-demote non-questions (auto-replies / spam) — docs/05 tie-breakers & flags.
        if (!$t->isQuestion) {
            $score *= 0.1;
        }

        $t->aiScore = (int) round(max(0.0, min(100.0, $score)));
        $t->weights = $this->w;

        // Effective score = manual override if an agent set one, else AI (docs/05).
        $t->effectiveScore = $t->manualScore ?? $t->aiScore;

        // Flag low-confidence / failed classifications for human review (docs/05).
        $t->flagged = $t->classifyFailed || $t->confidence < 0.5;
    }

    /** docs/05 CustomerValue (0–100). */
    private function customerValue(Ticket $t): int
    {
        $countPoints = $this->orderCountPoints($t->orderCount);
        $spendPoints = (int) round(min(40.0, ($t->totalSpend / self::SPEND_CEILING) * 40.0));
        $openBonus = $t->hasOpenOrder ? 20 : 0;
        return (int) min(100, $countPoints + $spendPoints + $openBonus);
    }

    private function orderCountPoints(int $count): int
    {
        return match (true) {
            $count >= 10 => 40,
            $count >= 5 => 35,
            $count >= 3 => 20,
            $count >= 1 => 10,
            default => 0,
        };
    }

    /** docs/05 SlaPressure (0–100). */
    private function slaPressure(Ticket $t): int
    {
        if (!$t->waiting) {
            return 0;
        }
        $timePressure = min(60.0, ($t->waitSeconds / self::SLA_RAMP_SECONDS) * 60.0);
        return (int) min(100, (int) round(40 + $timePressure));
    }
}
