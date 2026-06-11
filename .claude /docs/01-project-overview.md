# 01 — Project Overview

## Purpose

Build a PrestaShop 8.1 module (`daktela`) that ingests support tickets/emails
from the Daktela contact-center platform, enriches them with our existing
e-shop order data, classifies them with an **LLM**, and **prioritizes**
them so support agents answer the most valuable / most urgent messages first.

## Background

- We run an **existing production e-shop** on PrestaShop. The order and customer
  data already exists in the production database.
- We already use **Daktela** for customer communication (email, tickets,
  possibly chat/calls). It likely has some production integration already.
- Daktela ships its own AI features, but **we want to use our own LLM
  instead** for classification — better control, our own prompts, our own model.

## Goals (Phase 1)

1. Stand up a **local PrestaShop 8.1** dev instance with **demo/mock data** so we
   can develop without touching production.
2. Build a **separate, self-contained module** that pulls tickets/emails from
   the Daktela API.
3. **Classify** each ticket with the LLM: category, urgency, sentiment, plus a
   short summary.
4. **Score & sort** tickets by a priority formula that blends AI signals with
   business value (customer order history, SLA wait time).
5. Make the prioritized list **visible / exportable** to agents.

## Non-goals (for now) -

- Replacing Daktela as the agent UI.
- Writing classifications/priorities back into Daktela (possible later phase).
- **Auto-sending** AI replies to customers. (We *do* generate **draft** replies
  for an agent to review and send — see below — but nothing goes out without a
  human.)
- Touching production code or the production database with writes.

> **Draft replies are in scope.** For answerable tickets the AI writes a
> suggested reply grounded in catalog data; an agent reviews and sends it.
> Auto-send for safe categories is a deliberate later decision, not Phase 1.

## Glossary

| Term | Meaning |
|------|---------|
| **Ticket** | A Daktela support case; contains one or more activities (emails, notes). |
| **Activity** | A single message/event inside a ticket (e.g. an inbound email). |
| **Contact** | A person in Daktela; we match them to a PrestaShop **customer** by email. |
| **Priority score** | Computed number used to sort the inbox; see doc 05. |
| **Customer value** | Derived from PrestaShop orders (count, total spend, recency). |
| **Sentiment** | AI-detected emotional tone (e.g. angry, neutral, satisfied). |
| **Category** | AI-detected topic (e.g. order status, payment, returns). |

## Success criteria

- Pulling N tickets from Daktela produces N classified, scored records locally.
- Re-running the pull is **idempotent** (no duplicates, updates existing).
- Sorting by priority score visibly floats "angry + valuable + waiting" tickets
  to the top in a quick manual review.
