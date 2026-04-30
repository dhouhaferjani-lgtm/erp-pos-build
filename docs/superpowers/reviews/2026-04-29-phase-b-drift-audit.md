# Phase B drift audit — POS Refund Flow

**Date:** 2026-04-29
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-flow`
**Branch:** `feat/refund-flow`
**Phase B HEAD:** `48304fec`
**Commits audited:** `e26ffa1b`, `1d408851`, `f7eedd6e`, `48304fec` (Tasks 10–16 in plan numbering, 6–12 in source numbering)

## Verdict

**pause and fix** — exactly one production-blocker (PostgreSQL trigger vs `gl_journal_entry_id` back-fill) plus a handful of minor / tracked-deferred items. Phase B is otherwise on-track and aligned with spec; the blocker passes the SQLite test suite silently and will fail every voucher write in production PostgreSQL. Must be fixed before Phase C lands or the next migration touches voucher_ledger.

The remaining drift is small. Architecture, decisions block, GL matrix, currency precision, rate limiter shape, cascade, single-terminal scope, and SPV / restaurant-voucher rejection are all correct.

## What landed correctly

### Decisions block (top of plan)
- **Voucher is NOT a Treasury PaymentType.** `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:7` still has the original 6 cases (DocumentPayment / Advance / Refund / CreditApplication / SupplierPayment / POS). No `Voucher` case added.
- **Single-terminal Phase 1 scope enforced at SQL/service layer.**
  - `Voucher.php:259-261` — domain-level `isRedeemable()` returns false when `redeemable_at_terminal_id !== terminal->id`.
  - `VoucherRedemptionService.php:102-108` — service-level guard throws `VoucherNotForThisTerminalException`.
  - `VoucherIssuanceService.php:294` — `redeemable_at_terminal_id` is set to `issued_at_terminal_id` at issuance time (Phase 1 single-terminal contract).
- **Voucher source discriminator: 6 cases**, Phase 1 wires Refund / ExchangeSurplus / Goodwill, the rest reserved (`VoucherSource.php:18-26`).
- **SPV rejection** present on all three issuance entry points (`VoucherIssuanceService.php:100, 115, 138` → `guardSpv`); `SpvNotYetSupportedException` thrown if `voucher_kind = SPV`.

### GL matrix (spec §5.2) conformance
Implemented in `GeneralLedgerService.php:984-1045` (`resolveVoucherEventAccounts`):
- `Issued + Refund/ExchangeSurplus` → Dr SalesReturnsClearing / Cr VoucherLiability ✓
- `Issued + Goodwill` → Dr MarketingGoodwillExpense / Cr VoucherLiability ✓
- `Redeemed` → Dr VoucherLiability / Cr PosTenderClearing ✓ (NOT routed through `createPOSPaymentEntry` — `VoucherRedemptionService.php:195` calls `createVoucherLedgerEntry` directly)
- `RoundingAdjustment` → Dr VoucherLiability / Cr RoundingLossExpense ✓ (`VoucherRedemptionService.php:230` for residual, `GeneralLedgerService.php:1011-1014` for matrix)
- `Voided` (no prior redemption) → Dr VoucherLiability / Cr issuance-mirror (SalesReturnsClearing for Refund/ExchangeSurplus, MarketingGoodwillExpense for Goodwill) ✓ — `GeneralLedgerService.php:1025-1039`
- **No VAT lines on any voucher event.** Confirmed by reading the `resolveVoucherEventAccounts` matrix end-to-end and grepping the function for `Vat` references — zero hits. The class-level docblock at `GeneralLedgerService.php:876-877` reaffirms the EU Directive 2016/1065 MPV rule.

Deferred-by-design (not Phase B scope):
- `Expired` → throws `LogicException` ("not yet implemented in Phase 1"). `VoucherBreakageIncome` SystemAccountPurpose exists but unwired.
- `Reversed` → throws `LogicException`; column `reverses_voucher_ledger_id` exists.
- `Transferred` → throws `LogicException` (no GL line per spec, but no skip-path wired yet — this is acceptable since the event is unreachable in Phase B).
- `PartiallyRedeemed` → explicitly throws (projection-only, callers must not invoke).

### Currency precision (spec §5.5) conformance
- Internal precision = currency_scale + 2 ✓ — `VoucherRedemptionService.php:142-143` computes `$internalScale = currencyScale + 2`.
- DB columns: `decimal(20, 5)` covers both EUR (internal 4) and TND (internal 5) — `2026_05_02_000001_create_vouchers_table.php:24-25`.
- Casts: `decimal:5` on `initial_balance`, `current_balance`, `voucher_ledger.amount`.
- Display/redeem at currency_scale ✓ — balance check at line 150 uses `$currencyScale`, residual check at line 144 uses `$minCurrencyUnit = 10^-currencyScale` at internal precision.
- RoundingAdjustment fires when `0 < newBalance < min_currency_unit` ✓ — line 167-168.
- Tests cover both EUR (`test_residual_below_min_currency_unit_triggers_rounding_adjustment_eur`, line 461) and TND (`test_residual_below_min_currency_unit_triggers_rounding_adjustment_tnd`, line 494).

### Rate limiter (spec §4.7) conformance
`VoucherLookupRateLimiter.php` implements all 6 layers:
- Per-terminal/day: 200 hard ✓
- Per-cashier/day: 100 hard ✓
- Per-tenant/hour failed: 200 hard block ✓ (50 soft alert NOT wired — see Drift §3 below)
- Per-IP/hour: 300 ✓ (method exists; `checkAll` invokes it)
- Per-code-prefix/hour: 30 ✓
- Per-voucher 24h: 5 → auto-void ✓ (`VoucherLookupService.php:177-180` + `autoVoidVoucher`)

Disclosure boundary:
- Outside-session returns `{exists, status}` only — `GenericLookupResult.php`.
- In-session returns full `{voucherId, balance, currency, redemptionMode, expiresAt, partnerIdMatch}` — `InSessionLookupResult.php`.
- Identical generic-invalid response on rate-limit AND missing/voided code ✓ — `VoucherLookupService.php:69-71, 76-79, 83-86`.

### Cascade (spec §3.1, §4.9) conformance
- `VoucherCascadeService.onCreditNoteVoided()` correctly:
  - No-op when no vouchers from this credit note (line 49-51).
  - Voids all unredeemed vouchers in one transaction (line 63-69).
  - Hard-blocks when ANY voucher has a Redeemed event, throwing `VoucherCascadeBlockedException` (line 55-60).
- `VoucherCascadeBlockedException.message` includes the runbook path (verified by `VoucherCascadeServiceTest::test_cascade_blocks_with_runbook_reference_in_message`).
- Listener `VoucherCascadeOnReceiptVoidedListener` is wired in `EventServiceProvider.php:64-65` to `ReceiptVoided` events.
- Runbook exists at `docs/runbooks/voucher-redeemed-credit-note-correction.md` (170 lines, operator-focused).

### Voucher entity completeness (spec §3.1)
All spec fields present on `Voucher.php` (`$fillable` lines 99-124):
- code, initial_balance, current_balance, currency, status, redemption_mode, voucher_kind, source, issued_at, expires_at, partner_id, issued_to_partner_id, source_receipt_id, source_loyalty_transaction_id, source_promotional_campaign_id, issued_by_user_id, issued_at_terminal_id, redeemable_at_terminal_id, notes, authorized_by_user_id, override_reason, policy_trigger ✓

VoucherLedger fields (`VoucherLedger.php` lines 79-94):
- voucher_id, event, amount (signed), currency, receipt_id, terminal_id, user_id, gl_journal_entry_id, authorized_by_user_id, policy_trigger, reverses_voucher_ledger_id, occurred_at ✓

Single-table per spec ✓ (one `vouchers` + one `voucher_ledger`).

### Phase A regression check
- POS suite: 572 / 572 passing (`./vendor/bin/phpunit tests/Feature/POS tests/Unit/POS`).
- Document + Accounting suites: 638 / 638 passing.
- Voucher suite: 114 / 114 passing.
- PHPStan level 8 on `app/Modules/Voucher/` and `GeneralLedgerService.php`: clean (0 errors).
- Pint check on `app/Modules/Voucher/`, `tests/Feature/Voucher/`, `tests/Unit/Voucher/`: clean.

Phase A behavior intact:
- `pending_seal` lifecycle still in place (`ReceiptCreationServicePendingSealTest`, `ReceiptPaymentServiceFinalizationTest`, `FiscalStatusFilterTest` all green).
- `ReceiptDrafted` and `ReceiptCreated` events still wired.
- Z-report aggregation still excludes pending_seal.

### Architecture / discipline
- No `app()` helper in Voucher module: 0 hits.
- No `mixed` types in Voucher module: 0 hits.
- Constructor injection with `private readonly` throughout: spot-checked all 4 services + rate limiter + listener — uniform.
- Hexagonal layout clean: `Domain/`, `Application/`, `Infrastructure/`, `Presentation/` (no Presentation yet, deferred to Phase C/D — acceptable).
- Cross-module imports: Receipt, Terminal, User, Partner, JournalEntry, Tenant, GeneralLedgerService — used for entity-relationship binding and one public service (per audit checklist this is OK; codebase convention is consistent).

## Drift / gaps

### 1. **BLOCKER** — `voucher_ledger` immutability trigger blocks every `gl_journal_entry_id` back-fill on PostgreSQL

**Severity:** blocker (production)

**Evidence:**
- Migration `2026_05_02_000002_create_voucher_ledger_table.php:69-72` registers a `BEFORE UPDATE` trigger that raises `integrity_constraint_violation` for ANY update — no carve-out for the nullable `gl_journal_entry_id` column.
- Migration comment at line 79 even spells it out: `'Voucher ledger append-only enforcement: no UPDATE or DELETE ever allowed.'`
- Yet four call sites perform exactly the forbidden update:
  - `VoucherIssuanceService.php:329-331` — back-fills `gl_journal_entry_id` after creating the GL entry.
  - `VoucherRedemptionService.php:198-200` — same pattern after redemption GL entry.
  - `VoucherRedemptionService.php:232-234` — same pattern after RoundingAdjustment GL entry.
  - `VoucherLookupService.php:255-257` — same pattern in `autoVoidVoucher()`.
  - `VoucherCascadeService.php:130-132` — same pattern in `voidSingleVoucher()`.
- The class-level docblock comment in `VoucherIssuanceService.php:323-328` claims "the trigger only blocks UPDATE on amount/event columns — the implementation allows gl_journal_entry_id to be filled post-insert." That assertion is FALSE per the migration as written. The comment was likely copy-pasted from an earlier draft that had per-column logic.
- Tests pass because PHPUnit runs on SQLite (`phpunit.xml:31-32` → `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), and the trigger creation block in the migration is wrapped in `if (DB::connection()->getDriverName() === 'pgsql')`. The trigger never installs in tests.

**Production impact:** Every voucher issuance, redemption, RoundingAdjustment, fraud-auto-void, and cascade-void will throw `SQLSTATE[23000]` after writing the ledger row but before completing the transaction. The transaction will roll back, leaving the voucher in pending state and producing a 500 error to the caller. This will be discovered the moment the migration runs against PostgreSQL (staging or production).

**Proposed fix (pick one):**
- **(A) Reverse the order:** create the JournalEntry FIRST, capture its id, then INSERT the VoucherLedger row with `gl_journal_entry_id` already populated. Remove all four `DB::table('voucher_ledger')->update(...)` calls. This is the cleanest approach and matches the plan's intent — the ledger row's `gl_journal_entry_id` is set once at insert, never updated.
- **(B) Carve out the trigger:** rewrite the trigger to allow the UPDATE only when `OLD.gl_journal_entry_id IS NULL AND NEW.gl_journal_entry_id IS NOT NULL` and all other columns are unchanged. More fragile; harder to reason about; preferred only if option A breaks an integration constraint I'm not seeing.

**Decision needed:** which fix, before Phase C touches the ledger.

### 2. **Major** — `voucher_ledger.event` column needs an explicit NOT NULL or schema test, and there is no per-row enum CHECK

**Severity:** minor (latent data-quality)

**Evidence:**
- `2026_05_02_000002_create_voucher_ledger_table.php:23` — `$table->string('event', 32);` — no NOT NULL declared at the migration level (Laravel defaults to NOT NULL for `string`, so this is fine in practice — confirmed by `VoucherSchemaTest`).
- No PostgreSQL CHECK constraint enforces that `event` is one of the 8 valid VoucherEvent values. Future maintainers could write a row with an invalid event string via raw SQL.

**Proposed fix:** optional Phase B+ hardening — add a CHECK constraint on `event IN ('issued','redeemed','partially_redeemed','expired','voided','reversed','transferred','rounding_adjustment')`. Defer if not deemed necessary.

### 3. **Minor** — Per-tenant/hour soft alert at 50 (spec §4.7) is not wired

**Severity:** minor (deferred from spec)

**Evidence:**
- `VoucherLookupRateLimiter.php:21` documents "soft alert at 50, hard block at 200" but only the hard block (`PER_TENANT_FAILED_HOUR_HARD = 200`, line 38) is implemented.
- No event fires when the per-tenant failed counter crosses 50. Spec calls for an alert for fraud-monitor surfacing.

**Proposed fix:** add a `VoucherFraudSoftAlert` event (or reuse `BroadCustomerSearchAlert`-style pattern) emitted from `recordTenantFailedAttempt()` when the count transitions through 50. Optional Phase B follow-up or roll into Phase C.

### 4. **Minor** — No per-IP integration test in `VoucherLookupServiceTest`

**Severity:** minor (test coverage gap)

**Evidence:**
- `VoucherLookupRateLimiter.php:128-140` implements `checkPerIp` correctly and `checkAll` invokes it.
- `VoucherLookupServiceTest.php` has tests for terminal, cashier, tenant-failed, code-prefix, but no test exercises per-IP rejection. The IP value is plumbed through every test as `'127.0.0.1'`, but no test loops it past 300.

**Proposed fix:** add `test_generic_lookup_per_ip_throttle()` parallel to the existing layer tests.

### 5. **Minor** — Hardcoded goodwill thresholds in `VoucherIssuanceService` (acknowledged by TODO)

**Severity:** minor (tracked)

**Evidence:**
- `VoucherIssuanceService.php:65, 75` — `GOODWILL_NAMED_CUSTOMER_THRESHOLD = '100.00000'` and `GOODWILL_FOUR_EYES_THRESHOLD = '250.00000'` are class constants.
- Spec §3.5 says these come from `Company.reservation_settings`, which is Phase C work (Task 17, plan line 1117).
- The class docblock (lines 44-46, 195-196, 206-207) flags the deferral with explicit `TODO (Task C17)` comments. This is in line with plan ordering.

**No fix needed in Phase B.** Move with Task 17.

### 6. **Minor** — Self-dealing guard is a no-op (acknowledged by TODO)

**Severity:** minor (tracked)

**Evidence:**
- `VoucherIssuanceService.php:51-53` — class docblock notes self-dealing is a no-op in Phase 1 because `Partner` has no `user_id` link.
- `GoodwillSelfDealingException` exists at `Domain/Exceptions/` but is never thrown.

**No fix needed in Phase B.** Phase 2 work, correctly tracked.

### 7. **Minor** — `store_voucher` PaymentMethod row not yet seeded

**Severity:** tracked (Phase C scope)

**Evidence:**
- `grep -rn "store_voucher" apps/api/database/seeders/` returns 0 hits.
- Spec §3.2 calls for a per-tenant `PaymentMethod` row with `code = 'store_voucher'`.
- Plan Task 17 (Phase C) covers `instrument_serial` rename + discriminator and explicitly scopes "store_voucher only in Phase 1". The seeder is implied to live in Task 17.

**No fix needed in Phase B.** This belongs in Phase C; Phase B does not yet wire voucher tender as a `pos_receipt_payments` row.

### 8. **Cosmetic** — Stale code comment in `VoucherIssuanceService`

**Severity:** trivial

**Evidence:** `VoucherIssuanceService.php:323-328` says "trigger only blocks UPDATE on amount/event columns — the implementation allows gl_journal_entry_id to be filled post-insert." This is wrong and should be deleted regardless of which Drift §1 fix is chosen.

## Test coverage observations

- 114 / 114 voucher tests passing.
- 572 / 572 POS regression tests passing (no Phase A regressions).
- 638 / 638 Document + Accounting tests passing (GL service additions don't break existing behavior).
- PHPStan level 8 clean on `Voucher/` + `GeneralLedgerService.php`.
- Pint clean on Voucher module + tests.
- Test scenarios well-covered: SPV reject, single-terminal reject, expiry, voided, customer-bound mismatch, duplicate-in-tx, partial+full redeem, EUR & TND residual, fraud auto-void after 5 failed attempts, cascade no-op / void / hard-block / runbook reference.
- **Gaps:** no per-IP rate-limit test (Drift §4); no PostgreSQL-driver test that exercises the trigger (the existing `VoucherSchemaTest` only checks columns exist, not the trigger).
- **No test today catches the Drift §1 blocker** because everything runs on SQLite.

## Recommendations before Phase C

In priority order:

1. **Fix the `gl_journal_entry_id` back-fill (Drift §1).** Choose option A (insert with id already set) — it's cleaner and removes 5 dangerous `DB::table()->update()` calls. Then either:
   - Add a Postgres-flavored test for the trigger (e.g. a `VoucherLedgerImmutabilityTest` that conditionally connects to a Postgres instance when `DB_CONNECTION=pgsql`); OR
   - At minimum, add a unit test that runs the migration's PL/pgSQL trigger function via a SQL fixture and verifies it rejects updates. Without one of these, the same regression can recur on the next refactor.
2. **Delete the stale comment in `VoucherIssuanceService.php:323-328`** (Drift §8) regardless of the fix path.
3. **Add a per-IP rate-limit test** (Drift §4).
4. **Decide on the per-tenant soft alert at 50** (Drift §3): wire it now in a small follow-up commit, OR defer to Phase G with a new tracked task.
5. **Optional:** add a CHECK constraint on `voucher_ledger.event` (Drift §2) — small belt-and-suspenders win.
6. **Confirm decision:** Phase C Task 17 will seed the `store_voucher` PaymentMethod row and rename `voucher_serial → instrument_serial`. No action in Phase B.

Phase B is otherwise solid — the architectural shape, GL matrix, currency precision, single-terminal scope, layered rate limiter, and cascade semantics all match spec. The blocker is a single misalignment between a strict trigger and four code paths that assumed a relaxed trigger; once fixed, Phase C can land cleanly on top.
