<?php
/**
 * Mock Daktela client — replays tests/fixtures/*.json so the whole
 * pull -> classify -> score pipeline runs with NO Daktela account (docs/03
 * "Mocking"). This is what DAKTELA_USE_MOCK=1 selects, and what we run now.
 */

declare(strict_types=1);

namespace Daktela\Daktela;

use Daktela\Dto\Ticket;

final class MockDaktelaClient implements DaktelaClientInterface
{
    public function __construct(private string $fixturesDir)
    {
    }

    public function label(): string
    {
        return 'mock (fixtures)';
    }

    public function pullTickets(?int $sinceEpoch, int $max): array
    {
        $tickets = $this->load('tickets.sample.json');
        $activities = $this->load('activities.sample.json');
        $contacts = $this->load('contacts.sample.json');

        // Index activities by ticket name and contacts by name for O(1) joins.
        $byTicket = [];
        foreach ($activities as $a) {
            $tk = (string) ($a['ticket'] ?? $a['ticket_name'] ?? '');
            $byTicket[$tk][] = $a;
        }
        $contactByName = [];
        foreach ($contacts as $c) {
            $contactByName[(string) ($c['name'] ?? $c['id'] ?? '')] = $c;
        }

        $out = [];
        foreach ($tickets as $t) {
            $edited = isset($t['edited']) ? strtotime((string) $t['edited']) : null;
            if ($sinceEpoch !== null && $edited !== null && $edited < $sinceEpoch) {
                continue;
            }
            $name = (string) ($t['name'] ?? '');
            $contactRef = (string) ($t['contact'] ?? '');
            $contact = $contactByName[$contactRef] ?? null;

            $out[] = DaktelaMapper::build($t, $byTicket[$name] ?? [], $contact);
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function load(string $file): array
    {
        $path = rtrim($this->fixturesDir, '/') . '/' . $file;
        if (!is_readable($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        // Accept both {"result":{"data":[...]}} and {"data":[...]} and a bare array.
        if (isset($data['result']['data']) && is_array($data['result']['data'])) {
            return $data['result']['data'];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        return is_array($data) ? $data : [];
    }
}
