# P1 — journal_lines.debit/credit and payment_repositories.balance are decimal(15,2); TND money is scale 3

**Found:** 2026-09-06 by the Codex gate r2 on slice plan W-CASH-1 (`docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r2.md` B2); verified by the orchestrator against migrations at local dev HEAD.

**Evidence (unchanged since creation, no later widening migration exists):**
- `apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:51-52` — `journal_lines.debit` / `credit` `decimal(15,2)`.
- `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:29,31` — `payment_repositories.balance`, `last_reconciled_balance` `decimal(15,2)`.
- Rule 19 (`CLAUDE.md`) sets the currency storage floor at `decimal(N,3)`; POS orders were aligned to scale 3 on 2026-05-29 (`2026_05_29_100001_align_pos_orders_to_scale_3.php:92`) and WAC to scale 6, but GL lines and repository balances were not.
- Model casts claim scale 3 (`PaymentRepository.php:214` per the gate); PostgreSQL rounds on insert to the column scale, so the ORM value and the stored value differ silently.

**Consequence:** every POS receipt bridge journal line and every repository movement `balance_after` for a TND amount with a non-zero third decimal (VAT splits routinely produce `x.xx5`) is rounded at rest. Documents, movements, custody balances and journal lines can describe different amounts for the same fact; the trial balance may still close because both legs round. `docs/architecture/precision-contract.md` does not list these columns.

**Scope:** program-level, not W-CASH-1-only. Affects existing `TreasuryReceiptBridge`, `TreasuryAccountPaymentBridge`, `TreasuryDepositBridge`, `AccountingService` invoice GL, and every repository balance. Needs a census of actual rounding drift on staging tenants before widening.

**Disposition:** W-CASH-1 rev 3 carries a P0 "precision widening" prerequisite task (own non-additive push, host-side backup first, per-tenant `tenants:migrate-rolling --force` verification, raw round-trip test at `1.005`, schema-shape ratchet). The census + widening should be its own small lane, gated by treasury-reviewer + stock-gl-interaction-reviewer, and promoted BEFORE any W-CASH booking slice. Owner decision needed on whether it also precedes tenant #1 go-live (recommended: yes, since TND POS sales already post through it).

**Owner ruling 2026-09-06 (later):** widen to **decimal(15,4)** (4 decimals maximum, to support 4-decimal currencies and accounting practice); the precision used stays the country preset (`countries.currency_decimal_places`). Update `docs/architecture/precision-contract.md`: money storage floor = scale 4. Round-trip test value `1.0005`.

## CORRECTION 2026-09-06 22:00 (orchestrator) — the P1 premise was WRONG

The four columns are **already `decimal(15,3)` live**: `2026_03_11_200000_widen_monetary_columns_to_scale_3.php` widened `journal_lines.debit/credit` (`:27-30`) and `payment_repositories.balance/last_reconciled_balance` (`:113-116`); live tuples verified by the parallel session on a tenant DB (`docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` §0, commit 51dd84878). My grep for a later widening missed the raw `ALTER` form. Consequences: (1) there is **no silent millime rounding** in GL lines or repository balances for TND; the "books vs filing" risk stated above does not exist; (2) the W-CASH-1 gate r3 M2 had already rejected the "no later widening" assertion and I did not act on it; (3) the remaining gap is only the owner's A1 follow-up: support **four** decimals in storage. Severity downgraded from P1 to **P3 / capability** and it stays a P0 prerequisite of W-CASH-1 only because that slice's own document amount column is created at scale 4 and must match the ledger columns.

Owner ruling 2026-09-07 (relayed by the parallel session): Eloquent casts stay `decimal:3` **provisionally**; a benchmark on four-decimal accounting practice in Tunisia and the existing rounding rules (`docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md`, in progress) precedes the final cast decision.
