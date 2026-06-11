<?php
/**
 * Maps raw Daktela v6 JSON (tickets / activities / contacts) into Ticket DTOs.
 *
 * Shared by the mock and live clients so the field interpretation lives in one
 * place. The exact Daktela field names are confirmed-best-effort (see docs/03
 * TODOs); when you get a real instance, adjust the key lists here only.
 */

declare(strict_types=1);

namespace Daktela\Daktela;

use Daktela\Dto\Ticket;

final class DaktelaMapper
{
    /**
     * @param array<string,mixed>        $t   ticket row
     * @param array<int,array<string,mixed>> $activities activities of this ticket
     * @param array<string,mixed>|null   $contact resolved contact row
     */
    public static function build(array $t, array $activities, ?array $contact): Ticket
    {
        $ticket = new Ticket();
        $ticket->daktelaName = (string) self::first($t, ['name', 'id'], '');
        $ticket->title = (string) self::first($t, ['title', 'name'], '');
        $ticket->stage = strtolower((string) self::scalar(self::first($t, ['stage', 'category', 'statuses'], '')));
        $ticket->createdRemote = self::str(self::first($t, ['created', 'created_at'], null));
        $ticket->editedRemote = self::str(self::first($t, ['edited', 'edited_at', 'updated'], null));

        if ($contact !== null) {
            $ticket->contactEmail = strtolower(trim((string) self::first($contact, ['email', 'emails'], '')));
            $ticket->contactName = (string) self::first($contact, ['title', 'name', 'firstname'], '');
        }
        // Some ticket payloads embed the contact email directly.
        if ($ticket->contactEmail === '') {
            $ticket->contactEmail = strtolower(trim((string) self::first($t, ['email', 'contact_email'], '')));
        }

        self::applyActivities($ticket, $activities);
        return $ticket;
    }

    /** @param array<int,array<string,mixed>> $activities */
    private static function applyActivities(Ticket $ticket, array $activities): void
    {
        $lastIn = null;
        $lastOut = null;
        $latestInboundText = '';
        $latestInboundTime = -1;

        foreach ($activities as $a) {
            $dir = strtolower((string) self::first($a, ['direction', 'way'], ''));
            $isIn = in_array($dir, ['in', 'inbound', 'incoming'], true);
            $isOut = in_array($dir, ['out', 'outbound', 'outgoing'], true);
            $ts = self::epoch(self::first($a, ['time', 'created', 'edited'], null));

            if ($isIn) {
                if ($ts !== null && ($lastIn === null || $ts > $lastIn)) {
                    $lastIn = $ts;
                }
                if ($ts !== null && $ts > $latestInboundTime) {
                    $latestInboundTime = $ts;
                    $latestInboundText = self::body($a);
                } elseif ($latestInboundText === '') {
                    $latestInboundText = self::body($a);
                }
            } elseif ($isOut) {
                if ($ts !== null && ($lastOut === null || $ts > $lastOut)) {
                    $lastOut = $ts;
                }
            }
        }

        $ticket->lastInboundAt = $lastIn;
        $ticket->lastOutboundAt = $lastOut;
        $ticket->latestInboundText = $latestInboundText;

        // "waiting for us" = latest activity is inbound with no later outbound (docs/03).
        $ticket->waiting = $lastIn !== null && ($lastOut === null || $lastIn > $lastOut);
        $ticket->waitSeconds = $ticket->waiting ? max(0, time() - (int) $lastIn) : 0;
    }

    /** Pull a human-readable message body out of an activity row. */
    private static function body(array $a): string
    {
        foreach (['text', 'description', 'item', 'body', 'title', 'note'] as $k) {
            if (isset($a[$k]) && is_string($a[$k]) && trim($a[$k]) !== '') {
                return trim(strip_tags($a[$k]));
            }
            if (isset($a[$k]) && is_array($a[$k])) {
                $nested = self::first($a[$k], ['text', 'body', 'description'], '');
                if (is_string($nested) && trim($nested) !== '') {
                    return trim(strip_tags($nested));
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $arr @param string[] $keys */
    private static function first(array $arr, array $keys, mixed $default): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return $default;
    }

    private static function scalar(mixed $v): mixed
    {
        if (is_array($v)) {
            return self::first($v, ['name', 'title', 'id'], '');
        }
        return $v;
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) ? (string) $v : null;
    }

    private static function epoch(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        $t = strtotime((string) $v);
        return $t === false ? null : $t;
    }
}
