<?php
/**
 * Live Daktela v6 REST client (read-only).
 *
 * Used when DAKTELA_USE_MOCK=0 and a token is configured. Pulls tickets, then
 * their activities + contact, and hands raw rows to DaktelaMapper. Bounded and
 * resumable (docs/03/06 §7); backs off on HTTP 429.
 *
 * The base URL is https://<instance>.daktela.com/api/v6/ — NOT www.daktela.com
 * (docs/03). The accessToken travels as a query param per Daktela's scheme; it
 * is never logged (Logger::redact strips accessToken=...). The exact filter
 * encoding is best-effort and flagged for confirmation against a real instance.
 */

declare(strict_types=1);

namespace Daktela\Daktela;

use Daktela\Dto\Ticket;
use Daktela\Support\Http;
use Daktela\Support\Logger;

final class DaktelaClient implements DaktelaClientInterface
{
    private const PAGE = 50;
    private const MAX_RETRIES = 3;

    public function __construct(
        private string $baseUrl,
        private string $accessToken
    ) {
    }

    public function label(): string
    {
        return 'live: ' . preg_replace('#^https?://#', '', rtrim($this->baseUrl, '/'));
    }

    public function pullTickets(?int $sinceEpoch, int $max): array
    {
        if ($this->baseUrl === '' || $this->accessToken === '') {
            throw new \RuntimeException('Daktela base URL / access token not configured.');
        }

        $out = [];
        $skip = 0;
        while (count($out) < $max) {
            $page = $this->get('tickets.json', [
                'take' => self::PAGE,
                'skip' => $skip,
                'sort[0][field]' => 'edited',
                'sort[0][dir]' => 'asc',
            ]);
            $rows = $this->rows($page);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $t) {
                $edited = isset($t['edited']) ? strtotime((string) $t['edited']) : null;
                if ($sinceEpoch !== null && $edited !== null && $edited < $sinceEpoch) {
                    continue; // already processed in a prior run (idempotency, docs/03)
                }
                $name = (string) ($t['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $activities = $this->rows($this->get('activities.json', [
                    'filter[0][field]' => 'ticket',
                    'filter[0][operator]' => 'eq',
                    'filter[0][value]' => $name,
                    'take' => self::PAGE,
                ]));
                $contact = $this->resolveContact((string) ($t['contact'] ?? ''));

                $out[] = DaktelaMapper::build($t, $activities, $contact);
                if (count($out) >= $max) {
                    break 2;
                }
            }

            if (count($rows) < self::PAGE) {
                break; // last page
            }
            $skip += self::PAGE;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function resolveContact(string $contactName): ?array
    {
        if ($contactName === '') {
            return null;
        }
        try {
            $resp = $this->get('contacts/' . rawurlencode($contactName) . '.json', []);
            if (isset($resp['result']) && is_array($resp['result'])) {
                return $resp['result'];
            }
            $rows = $this->rows($resp);
            return $rows[0] ?? null;
        } catch (\Throwable $e) {
            Logger::warn('Contact lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        $query['accessToken'] = $this->accessToken;
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);

        $attempt = 0;
        while (true) {
            [$status, $resp] = Http::getJson($url, []);
            if ($status === 429 && $attempt < self::MAX_RETRIES) {
                $attempt++;
                // Backoff with jitter (docs/03 rate limits).
                usleep((int) ((2 ** $attempt) * 250000 + random_int(0, 250000)));
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Daktela HTTP ' . $status . ' for ' . $path);
            }
            return $resp;
        }
    }

    /** Normalise Daktela's {"result":{"data":[...]}} (and variants) to a row list. */
    private function rows(array $resp): array
    {
        if (isset($resp['result']['data']) && is_array($resp['result']['data'])) {
            return $resp['result']['data'];
        }
        if (isset($resp['data']) && is_array($resp['data'])) {
            return $resp['data'];
        }
        if (isset($resp['result']) && is_array($resp['result']) && array_is_list($resp['result'])) {
            return $resp['result'];
        }
        return [];
    }
}
