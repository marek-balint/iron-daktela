# CLAUDE.md — Daktela Integration Module for PrestaShop

> Main project context file. Read this first. Detailed specs live in `.claude/docs/`.

## What this project is

A **PrestaShop 8.1 module** named `daktela` that integrates the
[Daktela](https://daktela.com) omnichannel/contact-center API into our existing
e-shop, **pulls support tickets/emails from Daktela**, and uses **Anthropic AI
(Claude)** — *not* Daktela's built-in AI — to **classify and prioritize** those
tickets by business value.

The goal of the first phase is **documentation + a working prioritization
pipeline**, not a full UI.

## The core idea (one sentence)

> Pull each new Daktela ticket → enrich it with our e-shop order data → ask
> Claude to classify category / urgency / sentiment → compute a priority score →
> surface the highest-value, most-at-risk emails first.

## Email flow

Our support mailbox (e.g. **`info@eshop.com`**) is **routed into Daktela** — every
inbound/outbound email becomes a Daktela ticket/activity. We never touch the
mailbox directly; Daktela is the source of truth and we consume it via its API.
Details in [docs/03-daktela-integration.md](docs/03-daktela-integration.md).

## Why prioritize (the business problem)

Support agents see a flat inbox. We want them to answer the *right* email first.
A ticket should rank higher when, for example:

- The customer is **angry / frustrated** (sentiment).
- It's about **money or a lost order** ("where is my order?", "payment failed", refunds).
- The customer is **valuable** (e.g. more than 5 orders, high lifetime spend).
- The ticket has been **waiting** / has **no agent response** yet (SLA risk).

See `docs/05-prioritization.md` for the exact scoring model.

## Tech stack

| Layer | Choice |
|-------|--------|
| Platform | PrestaShop **8.1** (PHP 8.1+) |
| Module | Standard PS 8.1 module (`daktela/`) — **must be a separate, self-contained module/script** |
| Source data | Daktela REST API v6 (tickets, activities, contacts) |
| AI | Anthropic Claude (classification + complex tickets). Optional cheap **Tier 1** model (e.g. Gemini 1.5 Flash or Claude Haiku) for simple product questions — see doc 04. |
| E-shop data | Existing PrestaShop DB (orders, customers) — **already exists in production** |
| Dev environment | Local PrestaShop install seeded with mock/demo data |

## Hard constraints / decisions

1. **Separate module.** Daktela may already be integrated in production, but this
   work must be a **standalone module/script** so it can be developed, deployed,
   and removed independently. Do not modify core or other production modules.
2. **Anthropic, not Daktela AI.** All classification goes through Claude.
3. **Read-only on production data first.** Phase 1 only *reads* orders/customers
   to compute value. No writes back to the e-shop DB.
4. **Idempotent sync.** Re-pulling the same ticket must not create duplicates.
5. **AI drafts, humans send.** For answerable tickets the AI produces a
   **suggested reply (draft)** grounded in catalog data; an agent reviews and
   sends it. **No auto-send** in Phase 1 — flipping safe categories to auto-send
   is an explicit later decision. See doc 04 → "Catalog grounding".
6. **Security is not optional.** We handle untrusted customer text, production
   PII, and API secrets. Every external value is escaped/parameterized (no SQL
   injection), every rendered string is output-escaped (no XSS), secrets never
   get logged or committed, AI output is validated against closed enums. See
   `docs/06-security.md` and follow its per-PR checklist.

## Current status

- [x] Phase 0 — Documentation (this commit)
- [ ] Phase 1 — Local PrestaShop 8.1 + demo data
- [ ] Phase 2 — Daktela API client (pull tickets/emails)
- [ ] Phase 3 — AI classification (Claude)
- [ ] Phase 4 — Priority scoring + storage
- [ ] Phase 5 — Agent-facing view / export

## Documentation index

| Doc | What's in it |
|-----|--------------|
| [docs/01-project-overview.md](docs/01-project-overview.md) | Goals, scope, glossary, non-goals |
| [docs/02-architecture.md](docs/02-architecture.md) | Components, data flow, module layout, admin UI tooltips (en/sk) |
| [docs/03-daktela-integration.md](docs/03-daktela-integration.md) | Daktela API: auth, ticket pull, sync strategy |
| [docs/04-ai-classification.md](docs/04-ai-classification.md) | Claude prompt, output schema, categories |
| [docs/05-prioritization.md](docs/05-prioritization.md) | The priority scoring model (the heart of it) + manual score override |
| [docs/06-security.md](docs/06-security.md) | Security: SQL injection, XSS, secrets, CSRF, prompt injection, PII |

## Open questions (to resolve before coding)

- Daktela API credentials & instance URL (which environment for dev?).
- Is there a Daktela **sandbox/demo** instance, or do we mock ticket payloads?
- How do we **match a Daktela contact to a PrestaShop customer** — by email only?
- Where does priority get consumed — a dashboard tab, a sorted export, a webhook
  back into Daktela?
