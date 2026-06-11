<?php
/**
 * Standalone persistence to a JSON file (no PrestaShop). Gives the CLI a real,
 * idempotent store + watermark so re-runs update in place instead of duplicating
 * (docs/03). Preserves any manual_score already recorded (docs/05).
 */

declare(strict_types=1);

namespace Daktela\Store;

use Daktela\Dto\Ticket;

final class JsonFileTicketStore implements TicketStoreInterface
{
    /** @var array{watermark:?int,tickets:array<string,array<string,mixed>>} */
    private array $data;

    public function __construct(private string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->data = ['watermark' => null, 'tickets' => []];
        if (is_readable($this->path)) {
            $loaded = json_decode((string) file_get_contents($this->path), true);
            if (is_array($loaded)) {
                $this->data['watermark'] = isset($loaded['watermark']) ? (int) $loaded['watermark'] : null;
                $this->data['tickets'] = is_array($loaded['tickets'] ?? null) ? $loaded['tickets'] : [];
            }
        }
    }

    public function getWatermark(): ?int
    {
        return $this->data['watermark'];
    }

    public function setWatermark(int $epoch): void
    {
        $this->data['watermark'] = $epoch;
        $this->flush();
    }

    public function getHash(string $daktelaName): ?string
    {
        return $this->data['tickets'][$daktelaName]['hash'] ?? null;
    }

    public function save(Ticket $ticket): void
    {
        $existing = $this->data['tickets'][$ticket->daktelaName] ?? [];

        // Preserve a manual override across re-syncs (docs/05).
        $manual = $existing['manual_score'] ?? null;
        if ($manual !== null) {
            $ticket->manualScore = (int) $manual;
            $ticket->manualScoreBy = $existing['manual_score_by'] ?? null;
            $ticket->manualScoreAt = $existing['manual_score_at'] ?? null;
            $ticket->effectiveScore = (int) $manual;
        }

        $row = $ticket->toArray();
        $row['hash'] = $ticket->contentHash();
        $row['manual_score'] = $ticket->manualScore;
        $row['manual_score_by'] = $ticket->manualScoreBy;
        $row['manual_score_at'] = $ticket->manualScoreAt;
        $this->data['tickets'][$ticket->daktelaName] = $row;
        $this->flush();
    }

    private function flush(): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
