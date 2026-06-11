<?php
/**
 * PrestaShop persistence for tickets + scores.
 *
 * Security (docs/06 §1): every value is bound via (int)/(float)/pSQL — no raw
 * concatenation of external input. Upsert keys on the Daktela ticket id
 * (idempotent, docs/03). Re-sync NEVER overwrites manual_score / _by / _at
 * (docs/05) — those columns are simply omitted from the ON DUPLICATE UPDATE set.
 */

declare(strict_types=1);

namespace Daktela\Store;

use Daktela\Dto\Ticket;

final class DbTicketStore implements TicketStoreInterface
{
    private string $tTicket;
    private string $tScore;
    private string $tState;

    public function __construct()
    {
        $this->tTicket = _DB_PREFIX_ . 'daktela_ticket';
        $this->tScore = _DB_PREFIX_ . 'daktela_ticket_score';
        $this->tState = _DB_PREFIX_ . 'daktela_sync_state';
    }

    public function getWatermark(): ?int
    {
        $v = \Db::getInstance()->getValue(
            'SELECT v FROM ' . $this->tState . ' WHERE k = "watermark"'
        );
        return ($v === false || $v === null || $v === '') ? null : (int) $v;
    }

    public function setWatermark(int $epoch): void
    {
        \Db::getInstance()->execute(
            'INSERT INTO ' . $this->tState . ' (k, v) VALUES ("watermark", "' . (int) $epoch . '")
             ON DUPLICATE KEY UPDATE v = "' . (int) $epoch . '"'
        );
    }

    public function getHash(string $daktelaName): ?string
    {
        $v = \Db::getInstance()->getValue(
            'SELECT classified_hash FROM ' . $this->tTicket . '
             WHERE daktela_name = "' . pSQL($daktelaName) . '"'
        );
        return ($v === false || $v === null || $v === '') ? null : (string) $v;
    }

    public function save(Ticket $t): void
    {
        $db = \Db::getInstance();
        $now = date('Y-m-d H:i:s');

        // --- ticket upsert ---
        $db->execute(
            'INSERT INTO ' . $this->tTicket . '
              (daktela_name, title, stage, contact_email, contact_name, id_customer,
               waiting, wait_seconds, order_count, total_spend, has_open_order,
               latest_inbound, classified_hash, created_remote, edited_remote, date_add, date_upd)
             VALUES (' . implode(',', [
                $this->q($t->daktelaName),
                $this->q(mb_substr($t->title, 0, 500)),
                $this->q($t->stage),
                $this->q($t->contactEmail),
                $this->q($t->contactName),
                $t->idCustomer === null ? 'NULL' : (int) $t->idCustomer,
                (int) $t->waiting,
                (int) $t->waitSeconds,
                (int) $t->orderCount,
                (float) $t->totalSpend,
                (int) $t->hasOpenOrder,
                $this->q($t->latestInboundText),
                $this->q($t->contentHash()),
                $this->date($t->createdRemote),
                $this->date($t->editedRemote),
                $this->q($now),
                $this->q($now),
            ]) . ')
             ON DUPLICATE KEY UPDATE
               title = VALUES(title), stage = VALUES(stage),
               contact_email = VALUES(contact_email), contact_name = VALUES(contact_name),
               id_customer = VALUES(id_customer), waiting = VALUES(waiting),
               wait_seconds = VALUES(wait_seconds), order_count = VALUES(order_count),
               total_spend = VALUES(total_spend), has_open_order = VALUES(has_open_order),
               latest_inbound = VALUES(latest_inbound), classified_hash = VALUES(classified_hash),
               edited_remote = VALUES(edited_remote), date_upd = VALUES(date_upd)'
        );

        $idTicket = (int) $db->getValue(
            'SELECT id_daktela_ticket FROM ' . $this->tTicket . '
             WHERE daktela_name = ' . $this->q($t->daktelaName)
        );
        if ($idTicket <= 0) {
            return;
        }

        // Preserve an existing manual override; effective = manual ?? ai (docs/05).
        $manual = $db->getValue(
            'SELECT manual_score FROM ' . $this->tScore . '
             WHERE id_daktela_ticket = ' . (int) $idTicket
        );
        $effective = ($manual === false || $manual === null || $manual === '')
            ? (int) $t->aiScore
            : (int) $manual;

        // --- score upsert (manual_* columns deliberately omitted from UPDATE) ---
        $db->execute(
            'INSERT INTO ' . $this->tScore . '
              (id_daktela_ticket, category, urgency, sentiment, lang, summary, is_question,
               confidence, complexity, model_used, comp_sentiment, comp_category, comp_urgency,
               comp_value, comp_sla, ai_score, effective_score, weights_json, suggested_draft,
               answer_grounded, products_referenced, needs_human, flagged, date_add, date_upd)
             VALUES (' . implode(',', [
                (int) $idTicket,
                $this->q($t->category),
                $this->q($t->urgency),
                $this->q($t->sentiment),
                $this->q(mb_substr($t->language, 0, 8)),
                $this->q($t->summary),
                (int) $t->isQuestion,
                (float) $t->confidence,
                $this->q($t->complexity),
                $this->q($t->modelUsed),
                (int) $t->compSentiment,
                (int) $t->compCategory,
                (int) $t->compUrgency,
                (int) $t->compValue,
                (int) $t->compSla,
                (int) $t->aiScore,
                (int) $effective,
                $this->q((string) json_encode($t->weights)),
                $t->suggestedAnswer === null ? 'NULL' : $this->q($t->suggestedAnswer),
                (int) $t->answerGrounded,
                $this->q(implode(',', array_map('intval', $t->productsReferenced))),
                (int) $t->needsHuman,
                (int) $t->flagged,
                $this->q($now),
                $this->q($now),
             ]) . ')
             ON DUPLICATE KEY UPDATE
               category = VALUES(category), urgency = VALUES(urgency),
               sentiment = VALUES(sentiment), lang = VALUES(lang), summary = VALUES(summary),
               is_question = VALUES(is_question), confidence = VALUES(confidence),
               complexity = VALUES(complexity), model_used = VALUES(model_used),
               comp_sentiment = VALUES(comp_sentiment), comp_category = VALUES(comp_category),
               comp_urgency = VALUES(comp_urgency), comp_value = VALUES(comp_value),
               comp_sla = VALUES(comp_sla), ai_score = VALUES(ai_score),
               effective_score = ' . (int) $effective . ',
               weights_json = VALUES(weights_json), suggested_draft = VALUES(suggested_draft),
               answer_grounded = VALUES(answer_grounded),
               products_referenced = VALUES(products_referenced),
               needs_human = VALUES(needs_human), flagged = VALUES(flagged),
               date_upd = VALUES(date_upd)'
        );
    }

    /**
     * Ranked rows for the admin view (effective score desc, oldest-waiting as tiebreak, docs/05).
     * @return array<int,array<string,mixed>>
     */
    public function fetchRanked(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return \Db::getInstance()->executeS(
            'SELECT t.*, s.* FROM ' . $this->tTicket . ' t
             INNER JOIN ' . $this->tScore . ' s ON s.id_daktela_ticket = t.id_daktela_ticket
             ORDER BY s.effective_score DESC, t.wait_seconds DESC
             LIMIT ' . (int) $limit
        ) ?: [];
    }

    /** Per-ticket manual override from the admin view (docs/05). $score already clamped 0–100. */
    public function setManualScore(int $idTicket, int $score, string $by): bool
    {
        $score = max(0, min(100, $score));
        return (bool) \Db::getInstance()->execute(
            'UPDATE ' . $this->tScore . ' SET
               manual_score = ' . (int) $score . ',
               manual_score_by = ' . $this->q(mb_substr($by, 0, 250)) . ',
               manual_score_at = ' . $this->q(date('Y-m-d H:i:s')) . ',
               effective_score = ' . (int) $score . ',
               date_upd = ' . $this->q(date('Y-m-d H:i:s')) . '
             WHERE id_daktela_ticket = ' . (int) $idTicket
        );
    }

    /** Quote a string value for inline SQL (always via pSQL — docs/06 §1). */
    private function q(string $v): string
    {
        return '"' . pSQL($v) . '"';
    }

    private function date(?string $remote): string
    {
        if ($remote === null || $remote === '') {
            return 'NULL';
        }
        $ts = strtotime($remote);
        return $ts === false ? 'NULL' : '"' . pSQL(date('Y-m-d H:i:s', $ts)) . '"';
    }
}
