<?php

declare(strict_types=1);

namespace Daktela\Enrichment;

use Daktela\Dto\Ticket;

interface EnrichmentProviderInterface
{
    /** Match the contact to a PrestaShop customer and fill order-value fields (docs/02 §3, docs/05). */
    public function enrichCustomer(Ticket $ticket): void;

    /**
     * Build a compact, read-only catalog context for grounding a product answer
     * (docs/04). Return '' when grounding is off or nothing relevant is found.
     */
    public function catalogContext(Ticket $ticket): string;
}
