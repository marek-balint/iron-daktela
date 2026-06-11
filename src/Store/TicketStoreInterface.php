<?php

declare(strict_types=1);

namespace Daktela\Store;

use Daktela\Dto\Ticket;

interface TicketStoreInterface
{
    /** Last successful sync watermark (epoch), or null on first run (docs/03). */
    public function getWatermark(): ?int;

    public function setWatermark(int $epoch): void;

    /** Stored classified-text hash for a ticket, or null if unseen (idempotent reclassify, docs/03/04). */
    public function getHash(string $daktelaName): ?string;

    /** Idempotent upsert of ticket + score; must NOT clobber an agent's manual score (docs/05). */
    public function save(Ticket $ticket): void;
}
