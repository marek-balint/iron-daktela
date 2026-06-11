# 05 — Prioritization Model (the heart of it)

Turns AI signals + business value + SLA state into one **priority score** used to
sort the agent inbox. All weights are **config-tunable** (no code change needed).

## Inputs

| Source | Signal |
|--------|--------|
| AI (doc 04) | category, urgency, sentiment, is_question, confidence |
| E-shop DB | order_count, total_spend, has_open_order, last_order_recency |
| Daktela (doc 03) | waiting (no agent response), wait_seconds |

## Score formula

```
priority = w_sentiment * Sentiment
         + w_category  * Category
         + w_urgency   * Urgency
         + w_value     * CustomerValue
         + w_sla       * SlaPressure
```

Each component is normalized to **0–100**, multiplied by its weight, summed, then
the total is rescaled to 0–100 for display. Suggested starting weights:

| Component | Weight | Rationale |
|-----------|--------|-----------|
| `w_sla` | **0.30** | Waiting/unanswered = SLA risk, most actionable. |
| `w_value` | **0.25** | Protect our best customers. |
| `w_sentiment` | **0.20** | Angry customers churn / escalate. |
| `w_category` | **0.15** | Money/lost-order topics matter more. |
| `w_urgency` | **0.10** | AI urgency as a secondary nudge. |

## Component definitions

### Sentiment (0–100)
| sentiment | points |
|-----------|--------|
| angry | 100 |
| frustrated | 70 |
| neutral | 30 |
| satisfied | 0 |

### Category (0–100) — business weight
| category | points |
|----------|--------|
| payment | 100 |
| complaint | 90 |
| order_status ("where is my order?") | 85 |
| returns_refunds | 80 |
| shipping | 60 |
| account | 40 |
| product_question | 35 |
| other | 20 |

### Urgency (0–100)
critical = 100, high = 75, medium = 45, low = 15.

### CustomerValue (0–100)
Derived from PrestaShop orders. Example sub-formula:

```
value_raw =  order_count_points        # 0–40
           + spend_points              # 0–40
           + open_order_bonus          # 0 or 20  (has an undelivered/open order)
CustomerValue = min(100, value_raw)
```

- **order_count_points:** tiered — e.g. `0 orders=0, 1–2=10, 3–4=20, 5–9=35,
  10+=40`. (Your ">5 orders" rule lands in the top tiers.)
- **spend_points:** scale total lifetime spend into 0–40 (cap at a sane ceiling).
- **open_order_bonus:** +20 if they have an in-flight order — a "where is my
  order?" from someone with an open order is both relevant and high-risk.

### SlaPressure (0–100)
Combines "is it waiting on us" with "how long".

```
if not waiting:        SlaPressure = 0
else:
    SlaPressure = min(100, base_waiting + time_pressure)
      base_waiting  = 40                         # any unanswered inbound
      time_pressure = scaled(wait_seconds)       # 0–60, ramps over e.g. 0–48h
```

A brand-new unanswered ticket already starts at 40; it climbs as it ages.

## Worked example

Customer: 7 orders, has an open (undelivered) order, asks "Where is my order??
It's 10 days late!", classified `order_status`, `angry`, urgency `high`, ticket
is inbound with no agent reply for 6 hours.

| Component | Raw | × weight |
|-----------|-----|----------|
| Sentiment (angry) | 100 | 0.20 → 20.0 |
| Category (order_status) | 85 | 0.15 → 12.75 |
| Urgency (high) | 75 | 0.10 → 7.5 |
| CustomerValue (35 count + ~20 spend + 20 open = ~75) | 75 | 0.25 → 18.75 |
| SlaPressure (40 base + ~15 for 6h) | 55 | 0.30 → 16.5 |
| **Total** | | **≈ 75.5 / 100** |

→ Floats near the top of the inbox. Exactly the behavior we want.

## Tie-breakers & flags

- Tie-break by `wait_seconds` (older first).
- **Flag** tickets where AI `confidence < 0.5` for human review.
- **Demote** `is_question = false` (auto-replies/spam) hard, e.g. multiply final
  score by 0.1.

## Output

Store per ticket: each component value, the weights used, and the final score in
`ps_daktela_ticket_score`, so the ranking is **explainable** ("why is this #1?").

## Manual score override (config + per-ticket)

The AI score is a **default, not a verdict**. An agent or admin must be able to
override it — both globally (how scoring runs) and per ticket.

### Scoring mode (module configuration)

A setting in the PrestaShop module config page (`Configuration` key
`DAKTELA_SCORE_MODE`) chooses how the priority score is produced:

| Mode | Behaviour |
|------|-----------|
| **`ai`** (default) | Score is computed automatically by the AI-fed model above. |
| **`manual`** | AI classification still runs (category/sentiment shown), but the **priority number** comes from a human-entered value; the auto-score is hidden/ignored for ranking. |
| **`ai_assisted`** | AI computes a default score, but an agent can **manually adjust it per ticket**; the manual value wins where set. |

Default is `ai`. The mode is read at scoring time so flipping it needs **no code
change** (consistent with the config-tunable weights above).

### Per-ticket manual score

In `ai`/`ai_assisted` mode an agent can set a manual priority on an individual
ticket from the admin view (doc 02):

- `manual_score` (nullable int 0–100) and `manual_score_by` / `manual_score_at`
  stored in `ps_daktela_ticket_score`.
- **Effective score** = `manual_score` if set, else the computed AI score. Store
  both so ranking stays **explainable** ("agent pinned this to 95").
- Setting a manual score is a **state-changing admin action** — it must pass the
  CSRF token + permission checks and validate the input range server-side
  (clamp to 0–100, reject non-numeric). See `docs/06-security.md`.

> This keeps a **human in control of priority**, mirroring the "AI drafts, humans
> send" rule (CLAUDE.md). The AI proposes; an agent can always overrule.

## Tuning

Weights and tier thresholds live in config. After launch, review mis-ranked
tickets weekly and adjust. Consider logging agent actions to later learn weights
from real behavior.
