# Codex Remediation Work-List — Opus Review of the 2026-06-22 Balance/AR-AP/GL/Event-Sourcing Hardening

> **Owner of execution: Codex** (its domain — it authored the batch). **Adversarial review: Codex + Opus per item, then a fresh Opus re-review pass of the whole remediation.**
> Source: the 30 per-item Opus reviews at `docs/superpowers/reviews/2026-06-23-*-opus-review.md` (committed `faf63dbfc`). Verdict was 15 clean / 15 with findings / **0 BLOCKER / 5 NEEDS-REVISION**. NONE block the POS-retail pharmacy demo (all are back-office B2B AR/AP/GL paths POS sales don't exercise). These are production-correctness fixes.
> Operating loop, guardrails (NO full PHPUnit suite; db-per-tenant; precision contract; event immutability; FF-only dev pushes), and per-item DoD: reuse `docs/superpowers/coordination/2026-06-22-balance-and-event-sourcing-hardening-autonomous-session.md` §1–§2, §6. Re-verify every claim against current dev HEAD before acting (file:line may have drifted).

Branch: cut a fresh `fix/opus-remediation` off current `origin/dev`. Commit per item. FF-push in verified batches.

---

## R-1 (PRIORITY 1) — Revert the supplier-AP recognition pair (closes H-3.2 + H-3.1) — OWNER DECISION: REVERT

**Why a PAIR, not just H-3.2:** H-3.2 (`53115efaa`) *credits* SupplierPayable at PO confirmation (creates the payable); H-3.1 (`407cea426`) *debits* SupplierPayable when the PO is paid (clears it). They are coupled. Reverting H-3.2 alone leaves H-3.1 debiting a payable that was never created → `partners.payable_balance` goes **negative** → the M-5 (`0bc6b331d`) `payable_balance >= 0` CHECK + model guard **rejects the supplier payment** → supplier PO payments break.

**H-3.2 defects (per `2026-06-23-h-3.2-opus-review.md`):** recognizes expense + full AP at PO *confirmation* (before goods receipt) → double-counts cost against the perpetual COGS engine (Inventory drifts negative); books the ENTIRE `tax_amount` (incl. non-recoverable tax + stamp duty) as recoverable VAT-Deductible (Tunisia fiscal misstatement); reads a stale `subtotal` (confirm() re-saves tax_amount+total but not subtotal) → imbalanced entry; actorless confirmation silently skips AP.

**Action:** revert BOTH commits as one unit, plus their tests/wiring:
- `53115efaa` — `PurchaseOrderConfirmedListener` (delete), `EventServiceProvider` wiring, `GeneralLedgerService::createSupplierInvoiceJournalEntry`, `PurchaseOrderServiceTest` supplier_invoice assertions.
- `407cea426` — `PaymentController::store` supplier-payment branch, `GeneralLedgerService::createSupplierPaymentJournalEntry`, `PaymentTest` supplier-payment assertions.
- **KEEP** M-5 (the non-negative payable guard/constraint is correct and harmless when payable stays 0), M-7, M-6, M-3 — they tolerate `payable_balance == 0` (the pre-Codex state).

**Acceptance:** confirming a PO posts no GL; paying a PO does not drive `payable_balance` negative / no constraint violation; supplier-payment path behaves as pre-Codex; `git grep` shows no remaining `PurchaseOrderConfirmedListener` references or `PaymentType::SupplierPayment` branch in `PaymentController`; direct `createSupplierInvoiceJournalEntry`/`createSupplierPaymentJournalEntry` helpers remain posted/hash-covered because they predate the reverted pair; scoped tests green; PHPStan L8 + Pint clean.

**Re-introduce later (separate designed feature, accountant sign-off):** no GL at PO confirm → `Dr Inventory / Cr GR-IR (goods-received-not-invoiced)` at goods receipt → `Dr GR-IR + Dr recoverable-VAT / Cr SupplierPayable` at supplier invoice (split recoverable vs non-recoverable tax + stamp duty per `TaxCalculationService::determineRecoverability`) → payment clears AP. Tracks H-3 properly. Until that design lands, the reverted pre-Codex PO payment path remains economically customer-payment-shaped by owner decision and must not be treated as a correct supplier-AP model.

> Makes H-3.1's other findings (partner-id match H-3.1/H1, payable round-trip/idempotency) MOOT — they vanish with the revert.

---

## R-2 (PRIORITY 1) — `postEntry` must set `chain_sequence` (closes M-1/H1 + H-7.3/H1)

`GeneralLedgerService::postEntry` (`:1278-1284`) writes a `fiscal_hash` but never sets `chain_sequence`, and `getPreviousHash` orders by `posted_at`. `GeneralLedgerHashService::verifyChain` (`:85-123`) requires a gap-free integer `chain_sequence` starting at 1, ordered by `chain_sequence`. So every `postEntry`-path post (manual journals M-1, COGS H-7.3, and now every other newly-posting path Codex added) lands a hashed row with `chain_sequence = NULL` → **`verifyChain()` returns false for the whole company**. Latent (no production caller of `verifyChain` today) but it corrupts the documented GL-integrity model, and the "canonical hash-chain" claim is false. Two incompatible schemes coexist: Chain A (`AccountingService::createInvoiceGLEntries`, sets chain_sequence) vs Chain B (`postEntry`, posted_at-ordered, no chain_sequence).

**Action:** reconcile to ONE scheme. Make `postEntry` assign the next per-company `chain_sequence` and have `getPreviousHash` order by `chain_sequence` (matching Chain A and the verifier). **Acceptance:** a NEW test posts via `postEntry` (manual + COGS) then asserts `GeneralLedgerHashService::verifyChain()` is true; existing Chain-A invoices still verify.

---

## R-3 (PRIORITY 2) — M-6 reconciliation command never enters tenant context

`accounting:check-subledger-reconciliation` queries tenant-DB-only tables (`companies`, `journal_entries`, `accounts`, `partners`) but `forEachTenant` (`TenantScopedCommand.php:121`) iterates `Tenant::all()` on the central connection and never calls `$tenant->run()`/`tenancy()->initialize()`, so the bootstrapper never swaps DBs → throws `relation "companies" does not exist` (or reads empty) in db-per-tenant prod. Its sole test passes only because it runs single-connection with `db_per_tenant` OFF (false-confidence).
**Action:** initialize tenancy per tenant before querying. **Acceptance:** a db-per-tenant-mode (or PG) test proves it scans each tenant DB and detects a seeded discrepancy.

---

## R-4 (PRIORITY 2) — H-7.2 voucher `findOrFail` fail-closed regression

`createVoucherLedgerEntry` does `User::findOrFail($ledgerRow->user_id)` (`GeneralLedgerService.php:1082`) inside the redemption/void transaction. `voucher_ledger.user_id` has NO FK and is fed from POS operator/cashier ids the codebase elsewhere treats as possibly-not-a-user (`resolveCashierName` returns 'Unknown' on null). An unresolvable operator now throws `ModelNotFoundException` → **rolls back the entire voucher redemption/void** (prior behavior was a harmless Draft).
**Action:** resolve the actor defensively (nullable `posted_by`, mirroring the COGS H-7.3 system-generated precedent) instead of `findOrFail`. **Acceptance:** a test with a non-user operator id completes redemption/void and posts (or degrades to draft) without throwing.

---

## R-5 (PRIORITY 2) — M-8 throws an exception the sole caller doesn't catch

Cap/positive-amount guards throw `\InvalidArgumentException` (a LogicException), but `SalesOrderToInvoiceConverter::transferPrepayments()` only catches `\RuntimeException` for graceful GL degradation (`:411`) → a benign over-cap **rolls back the whole SO→Invoice conversion** (`convert()` is in a `DB::transaction`).
**Action:** throw a `RuntimeException` subclass (or extend the converter catch). **Acceptance:** converter test where the advance is already cleared → GL clearing is skipped, conversion still succeeds.

---

## R-6 (PRIORITY 3) — M-4 sealed-invoice GL durability

The `DB::transaction` wrap in `AccountingService::createInvoiceGLEntries/createCreditNoteGLEntries` (`:107,205`) makes a transient `refreshPartnerBalance()` failure roll back the ENTIRE GL for an already-sealed invoice (synchronous listener, no idempotency guard, no backfill) → a sealed tax invoice can end up with zero ledger representation and no recovery. Pre-M-4 it self-healed (stale cache only).
**Action:** move balance refresh out of the GL-persistence transaction (afterCommit + idempotent), or make refresh failure non-fatal + add a GL/balance backfill command. **Acceptance:** simulated refresh failure leaves the GL entry persisted; balance reconciles on retry.

---

## R-7 (PRIORITY 3) — H-4 balance guard uses display scale, not storage scale

`postEntry`'s debit==credit guard compares at `scaleResolver->getScale` (currency display scale), not the `decimal(15,3)` storage scale. For scale-0 currencies (XOF/XAF/JPY — **XOF/XAF are North-Africa-adjacent**), `bccomp` truncates to integer → a `Dr 100.500 / Cr 100.000` entry passes as balanced.
**Action:** compare at `max(3, currencyScale)` / storage scale. **Acceptance:** scale-0-currency regression test rejects a sub-unit imbalance.

---

## R-8 (PRIORITY 3) — M-10 un-gated DocumentLineEditor Service tab

`DocumentLineEditor` 'Service' tab (`DocumentLineEditor.tsx:507-508`) renders for ALL verticals and fetches `/services` (now `module:Workshop`-gated) → non-Workshop verticals get a new 403. Defeats M-10's "fail closed consistently" intent.
**Action:** gate the tab behind `hasModule('Workshop')`. **Acceptance:** non-Workshop vertical doesn't render/call the Service tab; frontend test.

---

## R-9 (PRIORITY 3) — Test quality + CI coverage

- **False-confidence tests** (pass against the buggy/reverted code): M-9 behavioral (`customer_statement_running_balance_preserves_third_decimal_for_tnd` passes with floats restored), H-7.3 currency (TND-everywhere fixtures), M-1 (no `verifyChain` call), M-6 (single-connection). Replace with discriminating tests (R-2/R-3 add some).
- **PG-schema tests never run in CI:** `PaymentAllocationPrecisionTest` (H-5) and `PartnerMoneyPrecisionTest` (M-5) are `tests/Feature` / PG-only; CI runs `--testsuite=Unit` (SQLite) + a hard-coded PG `--filter` allowlist that excludes them. Add both to the `backend-test-pgsql` filter (`.github/workflows/ci.yml`) so the `(15,3)` + non-negative-constraint guarantees have durable coverage.

---

## Definition of done (session)

Every R-item DONE or DISCARDED-with-evidence; per-item Codex + (true cross-model) Opus review saved to `docs/superpowers/reviews/2026-06-23-<R-item>-*-review.md`; scoped tests green; PHPStan L8 + Pint clean; FF-pushed to dev. Then a final fresh **Opus re-review pass** over the remediation diff confirms the findings are closed and no regressions introduced. The 15 "clean" items + the genuinely-fixed APPROVE-WITH-MINOR-EDITS items need no rework.
