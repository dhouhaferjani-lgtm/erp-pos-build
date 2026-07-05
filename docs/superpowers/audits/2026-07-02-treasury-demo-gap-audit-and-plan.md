# Treasury Module — Demo Gap Audit & Codex Dispatch Plan (2026-07-02)

> Synthesis of 5 parallel audits (backend money-data, frontend pages, bank reconciliation, seed data — Opus; industry standards — Sonnet). Supersedes the "what's missing" framing of the 2026-06-27 audit for demo purposes; the money-movement-spine deferral to the GL roadmap still stands.

## Headline

**Far more exists than the owner believes.** The perceived gaps are mostly (a) unseeded data, (b) unpolished/buried pages, (c) two small wiring defects. Genuinely missing: a forward-looking upcoming-payments view (QUICK), a cash-flow movement feed (MEDIUM), non-sales income recording (MEDIUM), and bank statement import (HARD — defer).

| Owner's complaint | Reality |
|---|---|
| "No clear P&L pages" | **P&L exists end-to-end** — `GET /api/v1/reports/profit-loss` (ProfitLossService over posted JEs, Tunisia CoA seeded) + `/finance/profit-loss` page, routed + in sidebar. It *looks* absent because the page is an unstyled B/W table using `parseFloat` + hardcoded `en-US` (wrong currency on a TND tenant), and the demo tenant's GL is nearly empty (seeded sales invoices bypass GL; zero expenses seeded). |
| "No cash flow / business overview" | Cash-position data exists (`payment_repositories.balance` + endpoints); a ready-made `FinanceWidget` (assets/liabilities/net income/AR/AP via `/reports/finance-summary`) is **mounted on no page**. Missing: an overview page composing them + an in/out movement feed (no treasury movement ledger — balance is a mutable scalar; per-repo "transactions" shows only `payments` rows). |
| "No upcoming payments in/out" | `documents.due_date` + `balance_due` + aged AR/AP services all exist — but bucket **backward** (overdue) only. Forward due-in-N-days endpoint is a trivial variant. `/reports/overdue-summary` already exists. |
| "Bank reconciliation not working" | **Fully built** (20 backend tests, 740-line page, manual match flow). Breaks: no seeded sessions (empty list), route gate `repositories.manage` vs sidebar `treasury` (Access Denied for non-admin), difference math ignores repo opening balance (`?? 0`) so Complete is unreachable, back-link points to `/settings`, `ReconciliationCompleted.matchedTotal` always 0 (`$item->amount` — column doesn't exist; should be `$item->payment->amount`). No statement import (CSV/OFX) — the one genuinely missing piece; Odoo-style manual mode is the industry-credible minimum and is present. |
| "You can add expenses and income" | Expenses: yes (cutoff shipped 2026-06-28, posts JE + decrements till). **Income: NO** — no counterpart module; non-sales income = raw journal entry only. |

## Industry bar (Sonnet research, Odoo/QBO/Xero/ERPNext/Pennylane/Zoho)

TABLE-STAKES: P&L (rev/COGS/GP/opex/net, single period), live cash-position widget, AR + AP aging lists with overdue-red, manual bank-rec matching screen, expense/other-income form with GL account-type picker, seeded CoA. NOT table-stakes: payment calendar grid (nobody ships one — sorted due-date lists are standard), bank feeds, forecasting. French-market framing: one unified **"Trésorerie" tab** = cash position + encaissements attendus + dépenses prévues.

## Known correctness caveats

- **POS GL fidelity:** `createPOSPaymentEntry` credits full tender (TTC) to revenue — no VAT split, COGS separate. P&L revenue may be TTC-inflated on live POS data. GL-roadmap item; for the demo the seeded books (services path) are correct.
- **`account_id` NULL trap still live in seeder:** `PaymentRepositorySeeder` sets only `gl_account_id`; `TreasuryAccountPaymentBridge`/`TreasuryDepositBridge` throw `payment_repository_missing_account_id`. Backfill migration covers only `gl_account_id`.
- Seeded sales invoices/payments are raw `Document::create`/`Payment::create` — **no JE, no balance movement** (no observers). Books show only 3 DEMO-BAL JEs + PO GR-IR.
- Seeded AR payments land on `BANK-01` (first repo by code) — good for bank-rec matching.
- Frontend `usePermissions` is role-only hardcoded map; report pages gated `accounts.view` (admin/accountant/manager).

## Dispatch plan — parallel Codex chunks (all TDD, worktrees off origin/dev)

**Wave 1 (fully independent, dispatch simultaneously):**

- **C1 — Seed the books (BE, seeders).** (1) `PaymentRepositorySeeder`: set `account_id` = same account as `gl_account_id`. (2) `seedTunisiaExpenses()`: ~10 expenses over 30 days via `ExpenseService::create/post` (CompanyContext bound, strings + bcmath scale 3, idempotency guards) → real class-6 JEs + till decrement. (3) Make the 10 seeded sales invoices post GL (route through posting service or create matching JEs) so P&L revenue side is real. (4) 2–3 supplier invoices with staggered due dates (AP aging + upcoming-out). (5) Bank-rec seed: 1 completed + 1 draft session on BANK-01, items = seeded payments, statement_balance netting to zero so Complete path is demoable. Tests: seeder feature tests (GL-consistency, balance deltas, idempotent re-run). **Effort: ~1 day. Risk: medium (demo seeder). treasury-reviewer gate.**
- **C2 — Report pages polish (FE).** ProfitLoss/BalanceSheet/AgedReceivables/AgedPayables/TrialBalance: kill `parseFloat` + `Intl.NumberFormat('en-US')` → `formatCurrency` (precision rule 19); P&L gets KPI StatCard row (revenue/expenses/net) + echarts revenue-vs-expense bar via `OwnerChart`; tokenize `StatCard`. Vitest on rendered output. **Effort: ~0.5–1 day. Risk: low.**
- **C3 — Upcoming-payments endpoint (BE).** `GET /reports/upcoming-payments?days=N`: forward window over `documents(due_date, balance_due)`, split IN (customer invoices) / OUT (supplier invoices + unpaid expenses), reuse Aged* query skeletons, `can:reports.view`. PHPUnit TDD. **Effort: ~1 day. Risk: low. treasury-reviewer gate.**
- **C4 — Bank-rec fixes (full-stack small).** Route gate alignment (`repositories.manage` → match sidebar/backend `repositories.view` or tighten sidebar), back-link `/settings`→`/treasury`, `$item->amount`→`$item->payment->amount`, difference semantics: opening = repo real balance context (make `can_complete` reachable honestly). Tests first for each. **Effort: ~1 day. Risk: low-medium. treasury-reviewer gate.**

**Wave 2 (after/overlapping):**

- **C5 — "Trésorerie" Finance Overview page (FE).** New `/finance/overview`: cash-position StatCard row (Σ repository balances by type), mount `FinanceWidget`, upcoming/overdue IN-OUT lists (C3 endpoint; falls back to aged+overdue-summary until C3 merges), echarts trend. Reuse `reports`/`finance` i18n namespaces (EN/FR/AR keys), tokens, `RequirePermission`. **Effort: 1–1.5 days. Soft-depends C3.**
- **C6 (optional) — Cash-movements read-model (BE).** `GET /reports/cash-movements`: UNION `payments` + posted `journal_lines` on cash/bank GL accounts → dated in/out feed powering the overview chart. NOT the full movement spine (stays on GL roadmap). **Effort: 1–2 days. treasury-reviewer gate.**
- **C7 (optional) — Income recording (BE+FE).** Mirror Expense: `DocumentType::Income` (append-only enum), `GeneralLedgerService::createFromIncome`, new `RepositoryInflowInterface`+service, minimal form. **Effort: 2–4 days. Recommend post-demo unless owner insists.**

**DEFERRED (post-demo / GL roadmap):** statement import (CSV/OFX) + `bank_statement_lines` + auto-match; true treasury movement spine; reconciliation lock/report; P&L period comparison + cash/accrual toggle; POS VAT/COGS GL split.

## Process

Per chunk: handover doc in `docs/handoff/`, own worktree off origin/dev (`fix/treasury-demo-c<N>`), Codex implements TDD (red→green→refactor, tests by path only — never full suite), Claude verifies (preflight scope + live Playwright where UI), **treasury-reviewer agent gates anything touching GL/payments/seeders**, merge to LOCAL dev, batched ff promotion to origin/dev. Post-demo branch policy: this is demo work → lands on dev.

Reference reports: agents' full outputs summarized here; industry checklist retained in this doc's "Industry bar" section.
