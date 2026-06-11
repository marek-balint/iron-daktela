# 04 — AI Classification (LLM)

We use an **LLM** (not Daktela AI) to turn raw ticket text into structured
signals the priority model can use. The provider is **pluggable** — selected with
`DAKTELA_AI_PROVIDER` (`groq` | `gemini` | `anthropic`) behind a single
`LlmClientInterface` — and the output schema is identical across them, so
downstream code doesn't care which one is configured.

## Model

- The provider is chosen with `DAKTELA_AI_PROVIDER`; each ships a cheap **Tier 1**
  and a stronger **Tier 2** model via its own `*_MODEL` / `*_MODEL_TIER2` keys
  (e.g. Groq `llama-3.1-8b-instant` / `llama-3.3-70b-versatile`, Gemini
  `gemini-1.5-flash` / `gemini-1.5-pro`).
- With no provider key set, the module falls back to a built-in **keyword
  heuristic** so the pipeline still runs (good enough to demo, no cost).
- Where the provider supports it, use **prompt caching** for the static system
  prompt + category definitions to cut cost on every call.

## Two-tier model routing (cost optimization)

We don't need an expensive model for every ticket. Use a **cheap model for the
easy ones, a stronger model for the hard ones**.

```
                      ┌─────────────────────────────┐
  every ticket  ────▶ │ Tier 1: triage (cheap/fast) │  classify + complexity flag
                      └──────────────┬──────────────┘
                                     │ needs_deep_reasoning?
                        ┌────────────┴────────────┐
                       no                         yes
                        │                          │
                   keep result        ┌────────────▼────────────┐
                                       │ Tier 2: strong model    │  re-classify / draft answer
                                       │ (configured provider)   │
                                       └─────────────────────────┘
```

### Tier 1 — cheap triage (all tickets)
A cheap, fast model handles the **first-pass classification** for everything:
simple product questions ("is this in stock?", "what colour?"), FAQs,
spam/auto-reply detection. E.g. Groq `llama-3.1-8b-instant`, Gemini
`gemini-1.5-flash`, or the cheap tier of whichever provider is configured.

Tier 1 also returns a routing flag in its JSON:

```json
{ "needs_deep_reasoning": false, "complexity": "low | medium | high" }
```

### Tier 2 — strong model
Escalate to the provider's stronger model (e.g. Groq `llama-3.3-70b-versatile`,
Gemini `gemini-1.5-pro`) when the ticket is **high-stakes or complex**, e.g.:

- **Order issues** — "where is my order", refunds, payment/billing disputes,
  complaints (anything touching money or a lost order).
- **Big / multi-part product questions** — comparisons, compatibility,
  technical specs, anything needing reasoning over multiple facts.
- Tier 1 returned `needs_deep_reasoning = true` **or** low `confidence`.
- The ticket already scores high on priority (doc 05) — worth the better model.

### Routing rules (config-driven)

| Condition | Model tier |
|-----------|------------|
| `category in {product_question, account, other}` and `complexity = low` | Tier 1 (cheap) |
| `category in {payment, order_status, returns_refunds, complaint}` | Tier 2 (strong) |
| `needs_deep_reasoning = true` or `confidence < 0.5` | Tier 2 (strong) |
| draft an actual reply to the customer (later phase) | Tier 2 (strong) |

Keep the tier mapping in config so we can re-balance cost vs quality without code
changes. Log which model handled each ticket + token cost for monitoring.

> **Note:** the classification **output schema stays identical** across tiers, so
> downstream scoring doesn't care which model produced it. A `model_used` field
> is stored for auditing.

> **Trade-off:** both tiers run on the **same provider** (one key, one SDK) — just
> a cheap model and a strong model from that provider. Mixing providers across
> tiers would mean a second key and slightly different JSON-mode behavior; keep
> both tiers on one provider unless there's a clear reason not to.

## Input (what we send)

Trim to keep tokens low:

- Ticket subject/title.
- The **latest inbound** customer message (the one awaiting reply).
- Optionally 1–2 lines of prior context.
- Customer's preferred language hint if known.

Do **not** send the whole thread or any secrets/PII beyond what's needed.

## Output schema (strict JSON)

LLM must return exactly this shape (use tool/JSON mode):

```json
{
  "category": "order_status | payment | shipping | returns_refunds | product_question | complaint | account | other",
  "is_question": true,
  "urgency": "low | medium | high | critical",
  "sentiment": "angry | frustrated | neutral | satisfied",
  "language": "cs | en | sk | ...",
  "summary": "One-sentence summary of what the customer wants.",
  "key_phrases": ["where is my order", "10 days late"],
  "confidence": 0.0
}
```

### Field notes

- **category** — drives the business-weight in scoring. Money/lost-order
  categories (`payment`, `order_status`, `returns_refunds`, `complaint`) weigh
  more. Keep the enum closed; map anything unknown to `other`.
- **is_question** — distinguishes a real question awaiting an answer from
  auto-replies / FYI / spam.
- **urgency** — AI's read of time-sensitivity from the text itself.
- **sentiment** — `angry`/`frustrated` boost priority.
- **summary** — shown to the agent so they grasp the ticket at a glance.
- **confidence** — low confidence can flag for human review.

## System prompt (sketch) --to do!!!

> You are a support-triage classifier for an e-commerce shop. Read the customer
> message and return ONLY the JSON object matching the given schema. Choose the
> single best category from the enum. Judge urgency and sentiment from the text,
> not from assumptions. If the message is not a genuine request needing a reply
> (newsletter, auto-reply, spam), set `is_question` to false. Be concise.

Provide the category definitions inline so classification is consistent, and
mark the system prompt + definitions as **cacheable**.

## Reliability

- Validate the JSON against the schema; on parse failure, retry once, then store
  a `category=other, confidence=0` fallback and flag it.
- Log token usage per call for cost tracking.
- Only (re)classify when the ticket's classified-text **hash changed** (doc 03).

## Catalog grounding (answering simple questions)

For simple product questions, the AI shouldn't guess — it should answer from our
**actual catalog**. We give the model relevant rows from the e-shop DB as
context, so a Tier 1 reply ("Is the blue one in stock?", "What's the warranty on
brand X?") is grounded in real data.

### Catalog tables we read (read-only)

User-facing names → PrestaShop tables:

| Concept | PrestaShop table(s) |
|---------|---------------------|
| products | `ps_product` + `ps_product_lang` (name, description, price) |
| product variants | `ps_product_attribute` (combinations) + `ps_attribute` / `ps_attribute_lang` (e.g. colour, size) |
| categories | `ps_category` + `ps_category_lang` |
| brands | `ps_manufacturer` |
| stock / availability | `ps_stock_available` |

> Same rule as order data: **read-only**, via PrestaShop's DB layer, no writes.

### How grounding works (retrieve → answer)

```
ticket text ──▶ extract product/brand/category mentions ──▶ look up matching rows
            ──▶ build a compact "catalog context" ──▶ give to the model ──▶ grounded answer
```

1. From the ticket (and AI `key_phrases`), find candidate product / variant /
   brand / category references — by SKU/reference, name match, or order line if
   the customer is asking about something they bought.
2. Fetch only the **relevant rows** (name, price, key attributes, in-stock
   quantity, brand, category) — keep it small to control tokens.
3. Pass this as structured context to the model with a rule: **answer only from
   the provided catalog data; if it's not there, say you don't know / escalate.**
4. The model returns a **suggested answer** the agent can review and send.

### Suggested-answer output (extends the schema)

When grounding is enabled, the model may also return:

```json
{
  "suggested_answer": "Yes — the blue variant (SKU 12345) is in stock, 7 units, €29.90.",
  "answer_grounded": true,
  "products_referenced": [12345],
  "needs_human": false
}
```

- `answer_grounded` = false or `needs_human` = true → **don't suggest a send**,
  route to an agent.
- This is **agent-assist (draft), not auto-send** — consistent with the Phase-1
  non-goal "auto-replying to customers with AI." A human stays in the loop until
  we explicitly decide otherwise.

### Where the draft lives

The generated draft is **attached to the ticket** so the agent finds it next to
the conversation:

- [x] **Always:** store the draft in our own module storage and show it in the
  admin tickets view (doc 02) — the agent can copy, edit, and send it.
- [ ] **Optional (later):** push the draft **back into Daktela as a draft** on
  the ticket, so the agent edits and sends it inside Daktela's own email
  composer. This crosses the "write back to Daktela" non-goal, so it's a later,
  opt-in step — needs the right Daktela API write scope. -- TO DO !

Store metadata with each draft: which model produced it, whether the answer was
grounded, which products it referenced, when it was generated, and what the
agent finally did with it (sent, edited, or discarded) once we can capture it —
useful for measuring how often drafts are good enough to send.

### Which tickets get a grounded answer

Best fit for **Tier 1** simple cases: `product_question` with low complexity,
stock/availability, price, brand/spec lookups. Order/payment/complaint tickets go
to a human (and Tier 2) — catalog data doesn't answer those.

> **Later option:** instead of per-query SQL lookups, build a small embedding
> index over the catalog for semantic retrieval. SQL/name matching is enough to
> start.

## Privacy

- Strip obvious payment card numbers / passwords before sending.
- Keep an allow-list of fields sent to the API.
