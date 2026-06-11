# Daktela ticket prioritization (Claude AI) — PrestaShop 8.1 module

Pulls support tickets from **Daktela**, enriches them with e-shop order data,
classifies them with an LLM, and ranks them so agents answer the most valuable /
most at-risk emails first. Full spec lives in `.claude/docs/` (note: that folder
currently has a trailing space in its name — see *Known quirks* below).

> **Provider note.** The docs mandate **Anthropic Claude**. The AI layer is built
> behind a provider interface (`src/Llm`), so it also runs on **Groq**
> (OpenAI-compatible) — which is what we use right now, since Groq is the only key
> we currently have. Switch with `DAKTELA_AI_PROVIDER`. Anthropic stays the
> documented production target.

## What runs today (no Daktela, no PrestaShop, no Anthropic key)

The pipeline runs **standalone** on mock data with just a Groq key:

```bash
cp .env.example .env
# edit .env: set GROQ_API_KEY=...   (DAKTELA_USE_MOCK=1 and provider=groq already set)

# (optional) see which Groq models your key can use, then set GROQ_MODEL_TIER1/2:
php cli/sync.php --list-models

php cli/sync.php            # pull mock tickets -> classify (Groq) -> score -> ranked table
php cli/sync.php --json     # same, machine-readable
php cli/sync.php --force    # ignore the watermark and reprocess everything
```

Mock tickets live in `tests/fixtures/*.json`. State (watermark + last results) is
written to `var/daktela-store.json`, so re-runs are idempotent (no duplicates).

Expected ordering on the sample data: the angry "where is my order, 10 days late"
ticket and the duplicate-charge payment ticket float to the top; the
out-of-office auto-reply is demoted (it's not a real question).

## What it does (pipeline)

`pull → enrich → classify → (ground draft) → score → store`, wired in
`src/Service/PipelineFactory.php`:

| Stage | Standalone (now) | Inside PrestaShop |
|------|------------------|-------------------|
| Source | `MockDaktelaClient` (fixtures) | live `DaktelaClient` when a token is set |
| Enrich | none (value 0) | order history + catalog from the PS DB (read-only) |
| Classify | Groq (or Anthropic) | same |
| Store | `var/daktela-store.json` | `ps_daktela_*` tables |

Scoring model and weights: `.claude/docs/05-prioritization.md`. Weights are
config-tunable (no code change).

## Installing into PrestaShop 8.1

1. Put this folder in `<shop>/modules/daktela/` (the folder **must** be named
   `daktela`).
2. Back office → Modules → install **Daktela ticket prioritization**.
3. Configure: set the AI provider + key, score mode, weights. Secrets are
   write-only (never shown back).
4. Open **Customers → Daktela tickets** to see the ranked inbox; "Run sync now"
   triggers a run. Or schedule the CLI: `*/5 * * * * php /path/modules/daktela/cli/sync.php`.

No Composer install is required — `autoload.php` falls back to a built-in PSR-4
loader. Run `composer dump-autoload` if you prefer the optimized autoloader.

## Security

Implements the per-PR checklist in `.claude/docs/06-security.md`: escaped/cast SQL
everywhere, output-escaped templates (no `|raw`), secrets only from
Configuration/`.env` and never logged, admin controller enforces
auth+permission+CSRF, CLI refuses to run over HTTP, AI output validated against
closed enums, and PII is scrubbed before anything leaves for the AI provider.

## Known quirks

- The spec folder is currently named `.claude ` (**trailing space**) — likely a
  typo when it was created. Rename it to `.claude` so Claude Code auto-loads it:
  `mv ".claude " .claude`. (A separate, empty `.claude` may have been created by
  the Claude Code harness; merge/replace as needed.)
- Groq model IDs change over time — if you get a model error, run
  `php cli/sync.php --list-models` and update `GROQ_MODEL_TIER1/2`.
- The live Daktela field mapping (`src/Daktela/DaktelaMapper.php`) is best-effort
  per the docs' TODOs; confirm against a real instance when credentials exist.

## Layout

```
daktela.php                     main module class (install/uninstall, config page)
autoload.php                    PSR-4 fallback autoloader
cli/sync.php                    CLI / cron entrypoint (dual-mode)
controllers/admin/              AdminDaktelaTicketsController (ranked view + actions)
sql/                            install/uninstall schema (module tables only)
views/templates/admin/          escaped Smarty template
tests/fixtures/                 mock Daktela payloads
src/
  Daktela/   DaktelaClient, MockDaktelaClient, DaktelaMapper
  Llm/       LlmClientInterface, AnthropicClient, GroqClient, factory
  Enrichment/ PrestaShop + null providers
  Store/     DB + JSON-file stores
  Service/   ClassificationService, PriorityService, Pipeline, PipelineFactory
  Dto/       Ticket
  Support/   ModuleConfig, Http, Logger, Pii, Enums
```
