<?php
/**
 * No-op enrichment for the standalone CLI (no PrestaShop DB available).
 *
 * Unknown/guest customer => value 0; tickets are still scored on AI signals +
 * SLA (docs/03 "If not matched -> value = 0"). No catalog context.
 */

declare(strict_types=1);

namespace Daktela\Enrichment;

use Daktela\Dto\Ticket;

final class NullEnrichmentProvider implements EnrichmentProviderInterface
{
    public function enrichCustomer(Ticket $ticket): void
    {
        // Intentionally nothing — DTO keeps its zero defaults.
    }

    public function catalogContext(Ticket $ticket): string
    {
        return '';
    }
}
