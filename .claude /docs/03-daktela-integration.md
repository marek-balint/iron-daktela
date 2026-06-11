# 03 — Daktela Integration

> **References:**
> - REST API docs: https://docs.daktela.com/en/integrations/working-version/rest-api
> - V6 API guide (custom agent interface): https://docs.daktela.com/en/other/working-version/daktela-v6-api-developing-a-custom-agent-interface
> - Per-instance interactive API help: `https://<your-instance>.daktela.com/external/apihelp/v6/`
>
> Note: there is **no** `www.daktela.com/api/v6/` endpoint. The live API is served
> from **your own instance** at `https://<your-instance>.daktela.com/api/v6/`.

## Email flow (where tickets come from)

The e-shop's support mailbox is **routed into Daktela**: every email sent to (and
from) our support address — e.g. **`info@eshop.com`** — is delivered to Daktela,
which turns it into a **ticket** (or appends it as an **activity** to an existing
ticket in the same thread).

```
customer ──email──▶ info@eshop.com ──routed──▶ Daktela ──ticket/activity──▶ our sync (API pull)
```

Implications for this module:

- We **do not** read the mailbox (IMAP) ourselves. Daktela is the single source
  of truth (: ); we only consume it via the **API** (and later, webhooks).
- Threading is handled by Daktela — replies land on the same ticket, so our
  `last_inbound_at` / `last_outbound_at` logic (below) reflects the real
  conversation state.
- Outbound emails (agent replies sent *from* `info@eshop.com` through Daktela)
  appear as **outbound activities** — this is exactly how we detect "answered vs
  waiting".

> **TODO:** confirm the exact support address(es) routed into Daktela (there may
> be more than one, e.g. `info@`, `orders@`, `reklamace@`). Each maps to a
> Daktela queue/channel we may want to include or filter.

## Authentication

Daktela uses an **access token** appended to requests (`?accessToken=...`) or via
the login endpoint to obtain one. For a service integration, provision a
dedicated API user and store its long-lived token in module config.

- Base URL: `https://<your-instance>.daktela.com/api/v6/`
- Token: `DAKTELA_ACCESS_TOKEN`

> **TODO:** confirm which instance + a service account for dev. If no sandbox is
> available, mock the payloads (see "Mocking" below).

## Key entities we read

| Endpoint | Entity | Why we need it |
|----------|--------|----------------|
| `tickets.json` | Ticket | The case to prioritize (subject, stage, dates). |
| `activities.json` | Activity | Individual emails/messages inside a ticket. |
| `contacts.json` | Contact | The customer; gives us the **email** to match to PrestaShop. |

## What a ticket gives us (fields we care about)

- `name` / id — stable identifier (for idempotent upsert).
- `title` / subject.
- `stage` / `category` — Daktela's own status (open, waiting, closed).
- `contact` — link to the person (→ email).
- `created` / `edited` timestamps.
- Last activity + **direction** (inbound vs outbound) → tells us if it's
  **waiting for an agent response**.
- Unread / SLA fields if available.

## Pulling new emails (the sync)

1. Read the stored **watermark** (last successful sync time / last seen edited
   timestamp).
2. Query tickets where `edited >= watermark`, ordered ascending, paginated.
3. For each ticket: fetch its **activities** (to get the latest inbound email
   body) and its **contact** (for the email).
4. **Upsert** into `ps_daktela_ticket` (+ activities) keyed by Daktela id.
5. Mark which tickets are **new/changed** so only those get re-classified.
6. Advance the watermark on success.

### Idempotency

- Key on Daktela ticket id; `INSERT ... ON DUPLICATE KEY UPDATE`.
- Store a content hash of the classified text; only call the LLM again if the
  hash changed (saves AI cost).

### Determining "waiting / no response"

A ticket is **waiting for us** if its **latest activity is inbound** (from the
customer) and there is no later outbound (agent) activity. Track:

- `last_inbound_at`
- `last_outbound_at`
- `waiting = last_inbound_at > last_outbound_at` (or no outbound at all)
- `wait_seconds = now - last_inbound_at`

These feed the SLA component of the priority score (doc 05).

## Matching Daktela contact → PrestaShop customer

Primary key: **email address** (normalized lowercase, trimmed).

```
daktela contact.email  ──match──▶  PrestaShop ps_customer.email
```

- If matched → pull order history (doc 02 enrichment).
- If not matched → treat as **guest / unknown** customer value = 0 (still scored
  on AI signals + SLA).

> **TODO:** confirm email is the right key. Consider phone as a fallback.

## Rate limits & resilience

- Respect Daktela pagination (`take` / `skip`).
- Back off on HTTP 429; retry with jitter.
- One sync run should be bounded (e.g. max N tickets) and resumable.

## Mocking (when no Daktela access yet)

Keep sample JSON payloads under `daktela/tests/fixtures/`:

- `ticket.sample.json`, `activities.sample.json`, `contact.sample.json`

The `DaktelaClient` should have a `MockDaktelaClient` implementation so the
sync → classify → score pipeline can be developed and tested end-to-end
**without live credentials**.
