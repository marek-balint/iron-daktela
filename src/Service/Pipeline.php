<?php
/**
 * Orchestrates one sync run: pull (bounded, from watermark) -> enrich -> classify
 * -> ground draft -> score -> upsert. Idempotent via the watermark (only new/edited
 * tickets are pulled, docs/03) and the store's upsert-on-Daktela-id.
 *
 * Wiring is injected, so the same Pipeline runs standalone (mock + Groq + JSON
 * store + null enrichment) and inside PrestaShop (live/mock + DB store + DB
 * enrichment + Anthropic/Groq).
 */

declare(strict_types=1);

namespace Daktela\Service;

use Daktela\Daktela\DaktelaClientInterface;
use Daktela\Dto\Ticket;
use Daktela\Enrichment\EnrichmentProviderInterface;
use Daktela\Store\TicketStoreInterface;
use Daktela\Support\ModuleConfig;

final class Pipeline
{
    public function __construct(
        private DaktelaClientInterface $daktela,
        private EnrichmentProviderInterface $enrich,
        private ClassificationService $classifier,
        private PriorityService $priority,
        private TicketStoreInterface $store
    ) {
    }

    /**
     * @return array{pulled:int,classified:int,failed:int,tickets:Ticket[]}
     */
    public function run(int $max, bool $force = false): array
    {
        $watermark = $force ? null : $this->store->getWatermark();
        $grounding = ModuleConfig::bool('DAKTELA_GROUNDING', true);

        $tickets = $this->daktela->pullTickets($watermark, $max);

        $classified = 0;
        $failed = 0;
        $maxEdited = $watermark ?? 0;

        foreach ($tickets as $t) {
            $this->enrich->enrichCustomer($t);
            $this->classifier->classify($t);

            if ($grounding && $t->category === 'product_question' && $t->complexity === 'low') {
                $ctx = $this->enrich->catalogContext($t);
                if ($ctx !== '') {
                    $this->classifier->suggestDraft($t, $ctx);
                }
            }

            $this->priority->score($t);
            $this->store->save($t);

            $t->classifyFailed ? $failed++ : $classified++;

            $edited = $t->editedRemote !== null ? (int) strtotime($t->editedRemote) : 0;
            if ($edited > $maxEdited) {
                $maxEdited = $edited;
            }
        }

        // Advance the watermark just past the newest ticket seen (docs/03).
        if ($tickets !== [] && $maxEdited > 0) {
            $this->store->setWatermark($maxEdited + 1);
        }

        // Rank for display: effective score desc, oldest-waiting as tiebreak (docs/05).
        usort($tickets, static function (Ticket $a, Ticket $b): int {
            return $b->effectiveScore <=> $a->effectiveScore
                ?: $b->waitSeconds <=> $a->waitSeconds;
        });

        return [
            'pulled' => count($tickets),
            'classified' => $classified,
            'failed' => $failed,
            'tickets' => $tickets,
        ];
    }
}
