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
