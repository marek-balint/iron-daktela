<?php

declare(strict_types=1);

namespace Daktela\Daktela;

use Daktela\Dto\Ticket;

interface DaktelaClientInterface
{
    /**
     * Pull new/changed tickets, newest activity resolved, as ready-to-process DTOs.
     *
     * @param int|null $sinceEpoch watermark; only tickets edited at/after this (docs/03)
     * @param int      $max        hard cap per run (bounded sync, docs/03/06 §7)
     * @return Ticket[]
     */
    public function pullTickets(?int $sinceEpoch, int $max): array;

    public function label(): string;
}
