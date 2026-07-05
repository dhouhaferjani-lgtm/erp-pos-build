# HANDOVER — Treasury C6 + C7 (own session, lands on `post-demo`)

**Date:** 2026-07-03
**Source plan:** `docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md` (chunks C6/C7 — the two "optional" chunks; C1–C5 are already merged to local `dev`).
**Landing branch:** `post-demo` (NOT `dev` — post-demo branch policy: dev = demo-related only). Promotion to `origin/post-demo` is orchestrator-gated; do not push.

## Setup

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin post-demo
git worktree add ../erp.treasury-c67 -b feat/treasury-cash-movements-income origin/post-demo
```

Work ONLY in `../erp.treasury-c67`. One feature branch, one commit-batch per chunk (C6 first, then C7). Merge to LOCAL `post-demo` only after the treasury-reviewer gate passes.

## C6 — Cash-movements read-model (BE)

`GET /reports/cash-movements?from=&to=&repository_id=`:
- UNION of `payments` + posted `journal_lines` restricted to cash/bank GL accounts → a dated in/out feed (date, direction in|out, amount string, currency, source_type/source_id, counterparty label, gl_account code).
- This is a **read-model only** — NOT the full treasury movement spine (that stays on the GL roadmap, `feat/accounting-gl-go-live`). No new tables; query-side only.
- Purpose: powers the `/finance/overview` (Trésorerie, chunk C5, already on local dev) trend chart with real dated movements. Add the FE wiring to the existing overview chart ONLY if C5 left an obvious seam; otherwise expose the endpoint + tests and note the FE follow-up.
- Route: module `routes.php` middleware `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:reports.view`.
- Watch the dedup seam: a payment that also produced a posted journal line on a cash account must not appear twice — decide the precedence (payments row wins; exclude journal lines whose `source_type='payment'`/pos_receipt already represented) and TEST it. `journal_entries(source_type,source_id)` is NOT globally unique — use source-type-scoped logic.

## C7 — Income recording (BE+FE)

Mirror the Expense flow:
- `DocumentType::Income` — **append-only enum** (never rename/repurpose existing cases; events are immutable forever).
- `GeneralLedgerService::createFromIncome` (credit class-7 income account, debit the receiving repository's GL account).
- New `RepositoryInflowInterface` + service (Shared/Contracts if crossed module boundaries) — the inverse of the expense till-decrement: posting an income INCREASES the repository balance. No negative/overdraft weirdness.
- Minimal FE form (mirror the expense form): amount via `<MoneyInput>` (strings), category (income GL account select), repository, date, note. i18n en/fr/ar. Design tokens.
- FormRequest: `numeric` + money regex ceiling `/^-?\d+(\.\d{1,3})?$/`.

## Ground rules (violations = rejected review)

- **TDD** — PHPUnit backend / Vitest frontend, red→green→refactor. Run tests **BY PATH ONLY — NEVER the full suite (crashes the laptop)**.
- **Precision rule 19**: money as strings end-to-end; `CurrencyScale::bcformatStrict` + constructor-injected `CurrencyScaleResolverInterface`; in queued/console contexts pass the entity currency explicitly (`getScale($currency)`) — a bare `getScale()` throws there. Never `parseFloat`/`Number()` on money in FE.
- **`account_id` vs `gl_account_id` trap** (live in payment repositories): read the C1 seeder fix (`PaymentRepositorySeeder`) before touching repository balances.
- Constructor injection only (no `app()`), strict types, enums for status/type, PHPStan level 8 on changed files, Pint.
- `php artisan typescript:transform` (with `CACHE_STORE=array`) after any DTO change; never hand-edit generated types.
- **Gate:** run the `treasury-reviewer` agent on the diff before merging each chunk (it gates anything touching GL/payments/repositories). Fix findings, re-gate.

## Deliverable

- C6 and C7 merged to LOCAL `post-demo` (no push), treasury-reviewer PASS reports saved under `docs/superpowers/reviews/`.
- Report: endpoints/classes added, tests + assertion counts, reviewer verdicts, and any scope you deliberately left on the GL roadmap.
