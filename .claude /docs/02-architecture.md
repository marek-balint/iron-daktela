# 02 — Architecture

## High-level data flow

```
   Daktela API                PrestaShop module `daktela`             LLM provider
  ┌───────────┐    pull     ┌───────────────────────────┐   classify  ┌────────┐
  │  tickets  │ ─────────▶  │  1. Sync (cron/CLI)        │ ──────────▶ │  LLM   │
  │  emails   │             │  2. Enrich w/ order data   │ ◀────────── │  API   │
  │ contacts  │             │  3. Score priority         │   JSON      └────────┘
  └───────────┘             │  4. Store locally          │
                            └─────────────┬─────────────┘
                                          │
                            ┌─────────────▼─────────────┐
                            │  PrestaShop DB (existing)  │  ← read orders/customers
                            │  + module tables (new)     │  ← write tickets/scores
                            └─────────────┬─────────────┘
                                          │
                            ┌─────────────▼─────────────┐
                            │  Agent view / export       │  sorted by priority
                            └───────────────────────────┘
```

## Components

### 1. Daktela API client
Thin wrapper over Daktela REST API v6. Handles auth (access token), pagination,
and rate limits. Pulls **tickets**, their **activities** (emails), and
**contacts**. See `docs/03-daktela-integration.md`.

### 2. Sync service
Orchestrates a pull. Tracks a watermark (last sync timestamp / last ticket id)
so each run only fetches **new or changed** tickets. Idempotent upsert into
module tables.

### 3. Enrichment service
Two kinds of read-only enrichment from the existing e-shop DB:

- **Customer enrichment** — find the matching PrestaShop **customer** (by email)
  and read order history: order count, total spend, last order date,
  open/undelivered orders. Produces the **customer value** inputs for scoring.
- **Catalog enrichment (grounding)** — for product questions, look up relevant
  rows from `products`, `product_variants`, `categories`, `brands`, and stock so
  the AI can answer from real data instead of guessing. See
  `docs/04-ai-classification.md` → "Catalog grounding".

> Reads the existing production-shaped DB via PrestaShop's DB layer. **No writes**
> to core tables.

### 4. AI classification service
Sends the ticket text (subject + latest inbound message, trimmed) to the
configured LLM and gets back structured JSON: `category`, `urgency`, `sentiment`, `summary`,
`is_question`. See `docs/04-ai-classification.md`.

### 5. Prioritization service
Combines AI signals + customer value + SLA state (waiting / no response) into a
single **priority score**. See `docs/05-prioritization.md`.

### 6. Storage
New module-owned tables (prefixed, e.g. `ps_daktela_ticket`,
`ps_daktela_ticket_score`). Never alter existing PrestaShop tables.

### 7. Presentation
Phase 1 exposes two back-office screens (plus an optional CSV/JSON export; a
webhook back into Daktela is a later option):

- **Configuration page** — the module settings (API credentials, model, score
  mode, scoring weights). Reached via the module's "Configure" button.
- **Customer → Daktela view** — a tab in the back office showing tickets sorted
  by priority and, per customer, their Daktela tickets enriched with order value
  and AI signals.

Every non-obvious field on both screens gets an inline **tooltip** explaining
what it does, in **English and Slovak** (`en` / `sk`) via PrestaShop's
translation layer — see "Admin UI tooltips" below.

## Admin UI tooltips (en / sk)

Short help bubbles (the `?` / info icon next to a field) so agents and admins
understand each control without reading the docs. Keep them **one sentence**.
Wire them through PrestaShop's i18n (`$this->trans(...)`, domain
`Modules.Daktela.Admin`) so `en` and `sk` strings come from translation files —
**don't hard-code** either language in the template.

### Configuration page

| Field | EN tooltip | SK tooltip |
|-------|-----------|-----------|
| Daktela base URL | Your Daktela instance URL — the API is served from `https://<instance>.daktela.com/api/v6/`, not from daktela.com. | URL vašej Daktela inštancie — API beží na `https://<instancia>.daktela.com/api/v6/`, nie na daktela.com. |
| Daktela access token | Long-lived token of a dedicated read-only API user; used to pull tickets, activities and contacts. Kept secret, never shown in logs. | Trvalý token vyhradeného API používateľa len na čítanie; slúži na sťahovanie ticketov, aktivít a kontaktov. Je tajný, nikdy sa nezobrazuje v logoch. |
| AI provider API key | Key for the configured LLM provider, used to classify tickets (category, urgency, sentiment). Billed per use; kept server-side only. | Kľúč pre nastaveného poskytovateľa LLM, ktorý klasifikuje tickety (kategória, naliehavosť, nálada). Účtuje sa za použitie; zostáva len na serveri. |
| AI model | Which model classifies tickets — a cheap/fast one for simple cases, a stronger one for complex tickets. | Ktorý model klasifikuje tickety — lacný/rýchly pre jednoduché prípady, silnejší pre zložité. |
| Score mode | How the priority number is set: **AI** computes it automatically, **Manual** uses a human-entered value, **AI-assisted** lets an agent adjust the AI score per ticket. | Ako sa určuje priorita: **AI** ju počíta automaticky, **Manuálne** používa hodnotu zadanú človekom, **AI s asistenciou** umožní agentovi upraviť AI skóre pri každom tickete. |
| Scoring weights | How much each factor (waiting time, customer value, sentiment, category, urgency) influences the final priority. Tune without code changes. | Ako veľmi každý faktor (čas čakania, hodnota zákazníka, nálada, kategória, naliehavosť) ovplyvňuje výslednú prioritu. Ladí sa bez zásahu do kódu. |

### Customer → Daktela view

| Field | EN tooltip | SK tooltip |
|-------|-----------|-----------|
| Priority score | 0–100 ranking of how urgently this ticket should be answered; higher = answer first. Combines AI signals, customer value and waiting time. | Hodnotenie 0–100, ako súrne treba odpovedať na tento ticket; vyššie = odpovedať skôr. Spája AI signály, hodnotu zákazníka a čas čakania. |
| Category | What the ticket is about (e.g. order status, payment, complaint), detected by AI from the message text. | O čom ticket je (napr. stav objednávky, platba, reklamácia), rozpoznané AI z textu správy. |
| Sentiment | The customer's mood detected by AI (angry, frustrated, neutral, satisfied); angrier raises priority. | Nálada zákazníka rozpoznaná AI (nahnevaný, frustrovaný, neutrálny, spokojný); nahnevanejší zvyšuje prioritu. |
| Urgency | AI's read of how time-sensitive the request is, from the wording of the message. | Odhad AI, ako časovo citlivá je požiadavka, podľa znenia správy. |
| Customer value | How valuable this customer is, from their order count, lifetime spend and any open order. | Aký hodnotný je tento zákazník, podľa počtu objednávok, celkovej útraty a prípadnej otvorenej objednávky. |
| Waiting / SLA | Whether the ticket is still waiting for an agent reply and for how long; longer waits raise priority. | Či ticket stále čaká na odpoveď agenta a ako dlho; dlhšie čakanie zvyšuje prioritu. |
| Manual score | Override the AI priority with your own 0–100 value; your value wins for ranking and is logged with your name. | Prepíšte AI prioritu vlastnou hodnotou 0–100; vaša hodnota platí pre poradie a zaznamená sa s vaším menom. |
| Suggested draft | An AI-written reply grounded in our catalog data, as a starting point — review and edit before sending. Never sent automatically. | Návrh odpovede od AI vychádzajúci z údajov nášho katalógu, ako východisko — pred odoslaním skontrolujte a upravte. Nikdy sa neodosiela automaticky. |

> Tooltips are help text only — they never expose secrets (token/API key values
> are write-only fields, masked in the UI). See `docs/06-security.md`.

## Proposed module layout (PrestaShop 8.1)

```
daktela/
├── daktela.php                 # main module class (install/uninstall, hooks)
├── config.xml
├── composer.json               # LLM provider + http client deps
├── src/
│   ├── Client/
│   │   └── DaktelaClient.php    # API wrapper
│   ├── Service/
│   │   ├── SyncService.php
│   │   ├── EnrichmentService.php
│   │   ├── ClassificationServiceAI.php   # LLM
│   │   └── PriorityService.php
│   ├── Model/                  # ticket, activity, score entities
│   └── Repository/
├── controllers/
│   └── admin/
│       └── AdminDaktelaTicketsController.php   # sorted inbox view
├── sql/
│   ├── install.sql
│   └── uninstall.sql
├── cli/
│   └── sync.php                # CLI/cron entrypoint for the pull
└── config/
    └── services.yml
```

## Configuration / secrets

Stored in module config (PrestaShop `Configuration`) or `.env` for dev:

- `DAKTELA_BASE_URL`, `DAKTELA_ACCESS_TOKEN`
- `DAKTELA_AI_PROVIDER` plus the matching provider key/model
  (e.g. `GROQ_API_KEY` / `GROQ_MODEL`, `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`)
- Scoring weights (tunable without code changes — see doc 05).
- `DAKTELA_SCORE_MODE` — `ai` (auto) / `manual` / `ai_assisted` — lets an admin
  switch off automatic AI scoring and set priority by hand (see doc 05).

Secrets must **never** be logged or committed — see `docs/06-security.md`.

## Scheduling

Phase 1: run `cli/sync.php` manually or via cron (e.g. every 5 min). Later: a
Daktela **webhook** can trigger near-real-time classification on new tickets.
