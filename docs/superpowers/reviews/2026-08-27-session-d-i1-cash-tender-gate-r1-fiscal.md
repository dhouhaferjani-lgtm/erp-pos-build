# Gate r1 (fiscal/POS) — Session D finding I-1 fix wave: one cash-tender definition

**Scope:** `fix/session-d-i1-cash-tender-invariant`, range `840cbadf5..c68e3acf5` (5 commits).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i1-cash-tender-invariant` (`git status` clean at `c68e3acf5`).
**Finding under fix:** `docs/superpowers/reviews/2026-08-26-session-d-final-review.md:32-45`.
**Implementer report:** `.superpowers/sdd/PLAN/task-i1-report.md`.
**Posture:** adversarial, code-grounded, read-only apart from this file.

## Evidence actually executed in this gate

| Command | Result |
|---|---|
| `php vendor/bin/phpunit tests/Feature/Treasury/PaymentMethodCashTenderTest.php` | **OK (28 tests, 92 assertions)**; migrate log printed `[I-1] cash-tender invariant violations found: 0` |
| `php vendor/bin/phpunit tests/Feature/POS/CloseOrphanedShiftCommandTest.php` | **OK (31 tests, 189 assertions)** |
| `php vendor/bin/phpunit tests/Unit/POS/ReportGenerationServiceTest.php tests/Feature/POS/ZReportV3AggregationTest.php tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php` | **OK (10 tests, 49 assertions)** |
| `php vendor/bin/phpunit tests/Feature/POS/GenerateZReportWithCountsTest.php tests/Feature/POS/ZReportSyncControllerSchema2Test.php tests/Feature/POS/PosShiftProjectionTest.php` | **OK (49 tests, 232 assertions, 1 skipped)** |
| `npx vitest run src/lib/offline/__tests__/zReportService.test.ts src/lib/offline/__tests__/zReportService.cashRounding.test.ts` | **2 files / 51 tests passed**; no stray worker pools after (`ps aux \| grep -c '[n]ode (vitest'` → 0) |

Not executed: PHPStan (needs a live-DB env), deptrac, full suite. PG 5433 re-runs were not repeated; the implementer's PG evidence is taken on report.

---

## 1. Bidirectional write guard — VERIFIED

- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:419-432` checks the FINAL state in both directions: `is_cash_tender = true` requires `code === 'CASH'` **exactly** (`:422`), and `strtoupper($code) === 'CASH'` requires the flag (`:429`). The case asymmetry is deliberate and documented at `:379-416`; it is what keeps a mixed-case collision loser un-flaggable while still refusing to leave it unflagged-and-editable.
- Both writers route through it on the FINAL state: `store()` at `:139-146`, `update()` at `:254-283` (note `:275-277` folds the stored flag in when the key is absent, so a PATCH that touches neither field is still evaluated).
- Typed 422 envelope: `refuseCashTenderInvariant()` at `:441-450` emits `{error:{code,message,payment_method_code}}` with a backed enum value from `Treasury/Domain/Enums/CashTenderInvariantRefusalCode.php:54,56`. Exception is `final`, private-constructor, `DomainException`-derived (`Treasury/Domain/Exceptions/CashTenderInvariantViolationException.php:24-31`) so an escape would still render 422 rather than 500.
- `normalizeCodeInput()` (`:368-377`) uppercases before validation and is correctly a no-op on non-strings/blank — so `cash` cannot pass `Rule::unique` and then die on the index.
- **No bypass writer.** A repo-wide grep for `PaymentMethod::create|updateOrCreate|table('payment_methods')->insert|->update` across `app/` and `database/seeders/` returns exactly two writers: the guarded controller (`:148`) and `database/seeders/PaymentMethodSeeder.php:53`. `ConfigureCashRoundingCommand.php:536` is a read. Imports do not touch `payment_methods` (`app/Modules/Import` has no reference).
- **Seeders create coherent rows.** `PaymentMethodSeeder` sets `is_cash_tender => true` on all three country templates' `CASH` row (`:86-89`, `:215-218`, `:375-378`) and on nothing else; every other code defaults false. `updateOrCreate` is keyed on `(tenant_id, company_id, code)` so a re-run repairs rather than duplicates.
- `PaymentMethodFactory` (`database/factories/PaymentMethodFactory.php:23-34`) leaves the flag at the column default — a *test-land* bypass only; the affected fixtures were updated (`CloseOrphanedShiftCommandTest.php:1169-1176`, `PaymentMethodTest.php:160-172,312-326`) and the suites above are green.

Guard test coverage is real and both-directional: `PaymentMethodCashTenderTest.php:629,651,670,691,720,750,770,790,822` pin store/update refusals with the exact `error.code` and `error.payment_method_code`, plus both escape hatches (flag the canonical row `:750`; rename the mixed-case loser `:790`) and the "unrelated edit is refused until reconciled" consequence `:770`.

## 2. `ShiftExpectedCashService` — VERIFIED

- Both `UPPER(payment_method_code) = 'CASH'` predicates are gone: `cashTenderedNetOfChange()` now `whereIn(...)` on the flag-derived set (`ShiftExpectedCashService.php:216`) and `cashChangeDuePerPaymentMethod()` likewise (`:450`).
- `cashTenderCodes()` (`:367-425`) resolves the shift's distinct tendered codes against `payment_methods` scoped `(tenant_id, company_id)` from the shift's terminal (`:398-412`) — the same scope `PosCoreReceiptProjection::writePayment()` used when it wrote the snapshot (`PosCoreReceiptProjection.php:1518-1534` via `EloquentPaymentMethodResolver::resolveByCode`, `:39-42`, which is an **exact, case-sensitive** `where('code', …)`). Read and write therefore agree by construction.
- **Memoized** per `(shift id, window end)` (`:143-150`, `:369-373`, `:423`), so the three reads inside one derivation cannot straddle a concurrent `payment_methods` edit.
- **Fails closed** on an unclassifiable code (`:419`) and on an unresolvable terminal (`:394`), surfaced as `CloseOrphanedShiftCommand::EXIT_TENDER_UNCLASSIFIABLE = 10` (`CloseOrphanedShiftCommand.php:186-191`, catch at `:348-357`). Catch ordering is safe — all three exception types are unrelated `final` classes.
- `is_active` deliberately not filtered (`:353-357`) — correct: these receipts are already written.
- Money math is clean: scale comes from `$this->scaleResolver->getScale($currencyCode)` with an explicit currency (`:258`, and `CloseOrphanedShiftCommand.php:312`), no bare no-arg call anywhere in either file. No float, no `(float)`.
- **The cross-layer regression really does pin both shapes identically across resolver and service.** `CloseOrphanedShiftCommandTest.php:797-822` (mixed-case loser `Cash`, flag false) and `:836-856` (canonical `CASH` with the flag forced false by raw DB write) each assert, in ONE test, (a) `TenderRepositoryResolver::resolve()` does **not** land the tender in a `cash_register`/`safe` when two candidate repositories exist (`assertCrossLayerAgreement()` `:1007-1032`, repositories seeded `:1039-1064`) and (b) `pos_shifts.expected_cash` equals the opening float only. The positive control `:864-880` proves the assertions cannot pass by classifying everything as non-cash. Both fixtures are written past the controller and past the model default (`brownfieldCashVariant()` `:1085-1101`, raw `DB::table(...)->update()` at `:840`), which is the honest brownfield shape.

## 3. Device Z aggregation — VERIFIED

- One read: `buildPaymentMethodDirectory()` (`apps/pos/src/lib/offline/zReportService.ts:812-852`) issues a single `SELECT id, code, is_cash_tender, is_active FROM payment_methods` (`:825`) and returns the id→code map, the known-code set and the predicate together; the old two-shape/two-query split is gone (`generateZReport` `:228-231`).
- Flag-keyed classification: the per-receipt loop tests `cashTender.isCashMethodCode(p.method_code)` (`:1121`) and buckets the change-netted cash under the flagged method's own code (`cashBucketCode` `:1123`, `:1130-1136`) rather than the literal `'CASH'`.
- Both downstream consumers resolve through the same predicate: the expected-cash bucket lookup (`:255-257`) and the cash-count expected figure (`:396-397`).
- Boolean marshalling is correct: the device column is `INTEGER NOT NULL DEFAULT 0` (`apps/pos/src/lib/db/migrations.ts:1972`), written as `m.is_cash_tender ? 1 : 0` (`apps/pos/src/lib/db/repositories/paymentRepository.ts:108`), so the `=== 1` test at `:840` matches `paymentRepository.ts:48`.
- Fails closed on an uncached tender with a named `console.warn` (`:1115-1120`), pinned by `zReportService.test.ts:522-544`.

## 4. Census migration — VERIFIED non-mutating and per-company

`apps/api/database/migrations/tenant/2026_08_27_100000_census_cash_tender_invariant_violations.php` contains exactly one `DB::` call and it is a `select` (`:78-96`); `up()` otherwise only `echo`s (`:222-225`) and `Log::`s; `down()` is a no-op (`:230-233`). Self-guards on the missing table (`:63-67`) and the missing column (`:69-76`). Zero-census still prints (`:98-108`, observed live in both PHPUnit runs above). Grouping is **per company** (`:115-133`), with the reason stated correctly — `unique(company_id, code)` is the scope in which a remedy is decidable. Predicates cover all three shapes (A+B `:84-87`, C `:89-92`); the column is `NOT NULL DEFAULT false` (`2026_07_28_100000_add_is_cash_tender_to_payment_methods.php:34-36`) so `where('is_cash_tender', false)` cannot miss NULL rows. Pinned by `PaymentMethodCashTenderTest.php:855,926`.

---

# FINDINGS — 0 Critical, 3 Important, 2 Minor

## [IMPORTANT] `apps/web/src/features/treasury/components/AddPaymentMethodModal.tsx:127-142` — after this diff no product surface can create the canonical cash method

The create payload sends `code`, `name`, `is_physical`, fees — and **not** `is_cash_tender`; a repo-wide grep of `apps/web/src` finds the field in exactly one place, a read-only type at `apps/web/src/features/pos/api/paymentMethodApi.ts:17`. There is no `is_cash_tender` control in the create modal or anywhere else in the web admin.

The new guard refuses `CASH` with the flag omitted (`PaymentMethodController.php:429-431`, deliberately pinned by `PaymentMethodCashTenderTest.php:651-663`). So a web admin creating a method with code `cash`/`CASH` now gets a 422 rendered as a generic toast (`PaymentMethodsPage.tsx:71-73` — the typed `error.code` is discarded), with no field to satisfy the guard with.

This is reachable, not theoretical: `PaymentMethodSeeder` is invoked only from `TenantInitializationService.php:105` (tenant creation) and demo seeders. The **second-company** path — `CompanyController.php:165-178` — provisions the chart of accounts, expense categories and tax configurations, and never payment methods. A second company in an existing tenant therefore starts with zero payment methods and now has no in-product way to create a cash one.

*Why it matters:* the guard has become the sole writer's only gate while no client can satisfy it for the one code that matters; the remaining path is API/SQL. (Honest scoping: the pre-diff outcome for that company was an *unflagged* `CASH` row, which `apps/pos/src/lib/payment/cashMethods.ts:27` already treated as no cash method — so this is not a loss of a *working* path, but it converts a repairable row into an uncreatable one.)

*Suggested fix (either):* in `store()`, default the flag when the key is absent and the code is canonical — `$isCashTender = (bool) ($validated['is_cash_tender'] ?? ($code === self::CANONICAL_CASH_CODE));` (keeps the refusal for an explicit `false`, which is the shape I-1 actually found) — **or** add the field to the modal and surface `error.code`. Note the first option contradicts `PaymentMethodCashTenderTest.php:651`, so it is a ruling, not a silent edit.

## [IMPORTANT] `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:419` + census remedy — the lane's own prescribed remediation can make a brownfield orphan shift permanently unclosable

The census tells the operator to rename a mixed-case loser: `UPDATE payment_methods SET code = 'CASH_LEGACY' …` (`2026_08_27_100000_census_cash_tender_invariant_violations.php:206-213`). `pos_receipt_payments.payment_method_code` is an immutable snapshot (`2026_05_07_000001_add_payment_method_code_to_pos_receipt_payments.php:67-72`), so every historical payment keeps naming `Cash`. After the rename, `cashTenderCodes()` finds no row for `Cash` and throws (`:419`) → `EXIT_TENDER_UNCLASSIFIABLE`. Under the *old* `UPPER(code)` predicate the same shift still closed.

The exception's stated remedy — "Restore the payment method (same code) and re-run" (`UnknownTenderClassificationException.php:46-47`) — is **unreachable through the API for any cash-family code**: recreating `Cash` unflagged is refused by `canonicalCodeNotFlagged` (`PaymentMethodController.php:429`) and flagged is refused by `flagOnNonCanonicalCode` (`:422`). The operator's only route is a direct DB insert. Pinned-as-intended at `CloseOrphanedShiftCommandTest.php:905-928`, but the pin does not resolve the dead end.

*Why it matters:* it is fail-closed (no wrong money, no bad JET), but it can strand an orphaned shift in `OPEN` on exactly the tenants this lane exists to remediate, and the guidance printed to the operator names an impossible action.

*Suggested fix:* resolve cash-ness from **`pos_receipt_payments.payment_method_id`** instead of the code snapshot. That column is a non-nullable FK with `restrictOnDelete` (`2026_01_08_190640_create_pos_receipt_payments_table.php:30-32`), so it is always present and a rename cannot invalidate it — the throw at `:419` becomes structurally unreachable and implementer concern #1 dissolves with it. `SalesReportService.php:335` already demonstrates the join. If the code-keyed read is kept, at minimum correct the exception text and add a census note that a rename strands open shifts.

## [IMPORTANT] `apps/pos/src/lib/offline/zReportService.ts:840,1115` — a known-but-unflagged cash code zeroes the signed drawer figure silently, while an *unknown* code warns

The warn at `:1115-1120` fires only for codes absent from the cache. A cached `CASH` row carrying `is_cash_tender = 0` produces `cashSales = '0'` (`:255-259`) and `expected_cash = opening float`, which is then hashed into the signed `Z_REPORT` bytes — with no operator-facing diagnosis. `zReportService.test.ts:503-517` pins this as intended.

Device migration v63 adds the column `NOT NULL DEFAULT 0` with **no backfill** (`apps/pos/src/lib/db/migrations.ts:1972`), so any cache older than the flag reads every method as non-cash.

*Honest reachability:* I could not construct a currently-live path. A device whose cache says flag=0 cannot tender cash at all (`cashMethods.ts:27` gates checkout), so on a server-incoherent tenant the zero is *correct*. The bad window is a shift straddling the v63 upgrade (cash receipts written under the old literal predicate, Z generated after) — historical, since v63 shipped before this lane. Recorded because the consequence lands in fiscal bytes.

*Suggested fix:* one line — warn when `cashCodes.size === 0` while the shift has tenders, naming the resync. Cheap, and it converts a silent wrong `EspecesAttendues` into a diagnosable one.

## [MINOR] `…census_cash_tender_invariant_violations.php:206-213` — the suggested rename collides when a company holds two mixed-case losers

`strtoupper($code).'_LEGACY'` yields `CASH_LEGACY` for both `Cash` and `cAsh`; running the printed statements in order makes the second violate `unique(company_id, code)`. Suffix with the row id (or a counter) so the printed statements are runnable as a batch.

## [MINOR] `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:143-150` — unbounded memo on a non-singleton service

`$cashTenderCodes` is never cleared and is keyed on `(shift id, window end)`. The class has no container binding (no `bind`/`singleton` in any provider), so it is resolved fresh per injection and the practical staleness/growth risk is nil today — but a future `singleton()` binding would silently serve a stale flag map to a later job for the same shift+window. A short comment or a size cap would make that explicit.

---

## Rulings on the implementer's stated concerns

**#1 — renamed-code stale snapshot ⇒ `ReportGenerationService::buildExpectedPerMethod()` 500 — LEDGER, not blocking.**
Verified bounded: `generateZReport()` calls `assertServerReportAuthoringAllowed()` first (`ReportGenerationService.php:196`), which throws `ServerFiscalAuthoringRetiredException` for any terminal at `fiscal_schema_version >= 3` (`:98-103`); the reachable branch also needs non-null `$cashCountInputs` (`:247-248`); and `buildExpectedPerMethod()` is `@deprecated` as "a takings-only server-authoring surface with no shipped client" (`:530-538`). So the uncaught path is a legacy-v2 terminal with a cash count and a post-hoc renamed method — narrow enough to ledger. The durable fix is the `payment_method_id` join in the Important above, which removes the throw rather than catching it.

**#2 — brownfield incoherent row blocks unrelated PATCHes — ACCEPTED for PATCH, but the concern is INCOMPLETE.**
The PATCH refusal is the right call: the guard evaluates the final state (`PaymentMethodController.php:267-283`), both escape hatches are reachable and tested (`PaymentMethodCashTenderTest.php:750,790`), and the census prints the per-row remedy. The generic toast (`PaymentMethodsPage.tsx:71-73`) is a real but minor UX gap. What the concern misses is the **create** side: "new tenants are seeded coherently" is true, but companies created through `CompanyController.php:165-178` are not seeded at all, and no web surface can send `is_cash_tender` — see Important #1. Ledger that, not the PATCH behaviour.

**#3 — `cashAccountCollections()` keeps the string predicate as a scope boundary — AGREE.**
Verified: that `method_code` comes off an account-payment record's device-authored payload, not a `payment_methods.code` snapshot written by `PosCoreReceiptProjection`, so nothing guarantees a row exists; routing it through the flag would convert a legitimate non-cash collection into a refusal to close (the boundary is documented in code at `ShiftExpectedCashService.php:607-616`). Keeping the literal is the correct scope call, and the residual — a mixed-case cash method counted here but not in the receipts term — is correctly recorded as its own lane.

**#4 — `SalesReportService.php:342` fourth consumer — AGREE, LEDGER (and it is cheaper than the report suggests).**
Confirmed: `whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])` at `:342`, and I-1 named only N-12/O-30/device, so it is out of scope for this lane. Worth noting for the follow-up: that subquery **already** `leftJoin`s `payment_methods` on `payment_method_id` at `:335`, so the fix is a one-predicate swap to `payment_methods.is_cash_tender` with no added query cost.

---

**VERDICT: spec ✅ + quality APPROVED (mergeable).**

All four verification axes hold against the code: the guard is bidirectional with a typed 422 and no bypass writer; `ShiftExpectedCashService` reads the flag through a memoized, correctly-scoped, fail-closed resolver; the device Z is flag-keyed off one query; the census is a pure read, per company. The cross-layer regression genuinely pins both brownfield shapes across the resolver and the service in a single test with a positive control. No Critical. The three Importants are reachability/observability gaps, none of which produces wrong money or a broken chain.

**What to fix before merge:** nothing blocking — but ledger Important #1 (no web surface can set `is_cash_tender`, and second companies get no seeded methods) as the first follow-up, since it is the only one an operator can hit on a coherent tenant.
