REJECT

The round-1 fixes are genuinely done — but verifying them surfaced a larger defect that Phase ⑤a itself created.

## 1. Findings

### BLOCKER

**B1 — `app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:596-654` — the inbound `cancel()` has no direction guard, so an issued outbound cheque can be cancelled with no GL reversal, no expense reopen, and no action key.**

Every other inbound lifecycle method rejects outbound: `custodyTransfer:137`, `deposit:181`, `clear:203`, `bounce:328`, `InstrumentRemittanceService.php:268`. `cancel()` at `:596` has none. Its only gate is `status !== Received → throw` (`:604`).

An outbound instrument issued through the expense path lands in exactly that status: `OutboundInstrumentIssuer.php:78` creates it via `instrumentLifecycle->receive(...)`, and `:198` writes `to_status => Received`. The issuer never creates or links a `Payment` — zero `Payment` references in the file — so at `:608` `$instrument->payment()->first()` returns **null**, which skips the "settle or reverse the linked payment" guard at `:609-613`. Execution then falls through:

- `:616-632` — the JE block is gated on `$payment !== null`, so `$journalEntryId` stays `null`. **No cancellation entry is posted.** The ⑤a issue JE (Dr 401 / Cr `4035`, `OutboundInstrumentIssuer.php:180`) is never reversed — `4035` permanently credits a cancelled instrument and AP stays understated.
- `:637` — status flips to `Cancelled`.
- `:638-652` — an `InstrumentEvent` is written with **no `action_key` and no `semantic_digest`**, bypassing the ⑤a durable idempotency store entirely.
- The method dispatches **no** `InstrumentCancelled` event, so `SyncExpenseOnInstrumentLifecycle` never runs — `expense_metadata.payment_instrument_id` still points at a cancelled instrument and the expense is never reopened.

This is reachable from the shipped UI, not just the API. `InstrumentDetailPage.tsx:186` computes `canCancel = instrument.status === 'received' && hasPermission('instruments.cancel')` with **no** direction term — `isOutbound` is declared one line later at `:189` and used only for labels. So a Cancel button renders on every issued supplier cheque, POSTs to `routes.php:141` (`can:instruments.cancel`, held by admin and accountant), and succeeds. `InstrumentDetailPage.tsx:148-172` has no `onError` on any mutation (grep for `onError|toast` in that file returns nothing), so nothing surfaces either way.

It is not an oversight the tests missed — it is **pinned**. `tests/Feature/Treasury/OutboundInstrumentGuardTest.php:171` `test_outbound_receive_and_cancel_remain_allowed` asserts this exact call returns `data.status = cancelled` (`:177-181`). That test is pre-existing and correct for the pre-⑤a world, where outbound instruments carried no GL. ⑤a gave outbound issue a GL footprint and left the pinned behaviour in place. This is precisely the cross-phase regression an exit gate exists to catch, and four prior gates plus round 1 missed it because every ⑤a test drives `cancel-outbound`, never `cancel`.

Violates the mandated invariants *"cancel is append-only and safe"*, *"lifecycle metadata never creates money outside the authorized GL/movement action"*, and *"Cancellation/clear lifecycle changes and Expense projection are atomic."*

**Fix:** add the outbound guard to `InstrumentLifecycleService::cancel()` matching `:137`; invert `OutboundInstrumentGuardTest.php:171` to assert rejection; gate `canCancel`/`canRemit`/`canTransfer`/`canClear`/`canBounce` on `!isOutbound` at `InstrumentDetailPage.tsx:184-188`.

### MAJOR

**M-A — `docs/handoff/treasury-phase5a-deploy-checklist.md:22-24,33` — the round-1 M3 fail-loud backfill is silently neutralized by the invocation the checklist itself prescribes.**
`BackfillPayableInstrumentAccountsCommand.php:85-94` correctly detects inactive `403`/`4035` and `:156` returns `self::FAILURE`. But the checklist prescribes `php artisan tenants:run treasury:backfill-payable-instrument-accounts`, and `vendor/stancl/tenancy/src/Commands/Run.php:54` calls `$this->call(...)` **discarding the return value**; `handle()` never aggregates an exit code. So a per-tenant failure neither aborts remaining tenants nor produces a non-zero exit — the operator sees success on a broken chart, then every supplier-cheque issue 500s. `:33` calls these conditions "a deployment stop" while giving no detection mechanism. **Fix:** state the non-aborting semantics and prescribe an explicit gate (tee + grep for `inactive`/`wrong type`/`missing supplier parent`, or per-tenant `--tenants=<uuid>` with `$?`).

**M-B — `Expense/Domain/ExpenseMetadata.php:9,128-132` and `Expense/Application/Listeners/SyncExpenseOnInstrumentLifecycle.php:11,46-50` — round-1 M2 traded five rule-6 breaches for three new ones.**
`ExpenseService` is clean of the five internals round 1 named and now injects only `OutboundInstrumentIssuerInterface` (`:37,57`). But this diff **adds**: an Expense Eloquent model with `belongsTo(PaymentInstrument::class)` (`ExpenseMetadata.php:128-132`), a listener that queries Treasury's `PaymentInstrument` directly (`:46-50`), and `use Treasury\Domain\Enums\InstrumentKind` in `ExpenseService.php:27`. CLAUDE.md rule 6 prohibits importing models across modules. The listener re-fetches `repository_id`/`payment_method_id`/`direction` — data that could ride on the `InstrumentCleared` payload — so the event boundary is nominal. **Fix:** carry those fields on the event; keep the FK column, drop the Eloquent relation.

### MINOR

- `OutboundInstrumentService.php:338` — `bounce` still dispatches via `DB::afterCommit(...)` while `clear:182`, `represent:492`, `cancel:696` now dispatch in-transaction. Harmless today (no `InstrumentBounced` listener registered, `EventServiceProvider.php:69-74`), but the first listener added inherits the non-atomicity round 1 rejected, untested.
- `OutboundInstrumentService.php:716-732` vs `OutboundInstrumentIssuer.php:232-244` — two structurally identical digests remain (down from three). Both are now Treasury-owned, so the mandated *ownership* centralization is satisfied; the duplication can still diverge silently into spurious replay conflicts.
- `OutboundInstrumentService.php:725` / `OutboundInstrumentIssuer.php:237` — digest hashes the raw `$instrument->amount` rather than a `bcformatStrict`-normalized string.
- `docs/sessions/treasury-phase5a-e2e/05-expense-paid-after-clear.png` — genuinely re-captured in `cffb5f76f` (confirmed `M` in `git log --name-status`), but still shows only `Posted`; the Paid badge (`ExpenseDetailPage.tsx:322-326`) is clipped off-frame because `fullPage: true` (`smoke.ts:483`) doesn't defeat the app's inner scroll container. The *assertion* is sound; `REPORT.md:39` claims evidence the image doesn't carry.
- `smoke.ts:290-293` — asserts the hidden `data-total` attribute, not the rendered `formatAmount` text (`InstrumentListPage.tsx:341`); bound to the API aggregate rather than this run's `supplierAmount`, and `01-*.png` shows the total contaminated by leftover debug rows (disclosed at `REPORT.md:50`).
- `InstrumentListPage.tsx:223` — column header still hardcodes `receivedDate` on a mixed-direction table.
- `useExpenses.ts:243-254` — bare `['instruments']` / `['maturing-instruments']` invalidation. **Fourth consecutive round.** Functionally correct (tenant/company are key *suffixes*); over-broad blast radius only.
- Round-1 MINORs re-checked and **not reproducible**: the claimed opposite lock order (both paths lock instrument first — `PaymentController.php:745`→`:899`; `OutboundInstrumentService.php:529`→`:603`) and the unscoped replay lookup (`PaymentController.php:110-113` scopes tenant+company).
- Round-1 MINOR "no `InstrumentBounced` expense listener" is **spec-consistent**, not a defect — spec §4.3 says AP stays closed on bounce. Log as a reporting caveat.
- `InstrumentEventLocales.test.ts:7-17` — hardcoded array, currently 9/9 correct, but cannot fail when a 10th PHP enum case ships. That is the exact drift class that produced round-1 B1.

## 2. Round-1 resolution table

| # | Round-1 finding | Status |
|---|---|---|
| B1 | `events.issued` missing en/fr, no `events` block in ar | ✅ **Fixed** — all 9 `InstrumentEventType` cases present in en (`treasury.json:229-239`), fr (`:229-239`), ar (`:26-36`); `issued` renders "Instrument issued" (visible in re-captured 02/03) |
| M1 | Expense reset non-atomic (`afterCommit` + throwing listener) | ✅ **Fixed** for clear/represent/cancel — in-transaction `event(...)` at `:182,:492,:696`; event boundary retained (`EventServiceProvider.php:69-74`). Rollback proven by live failure injection at `ExpensePayByInstrumentTest.php:254-284` (instrument status, JE count, movement, balance, expense metadata). `bounce` left asymmetric → MINOR |
| M2 | Rule-6 breach + triplicated digest | ⚠️ **Partial** — contract `OutboundInstrumentIssuerInterface` + provider binding (`TreasuryServiceProvider.php:62`) + `PaymentController.php:69,1009` convergence all real; digest ownership now Treasury-only. But 2 digests remain and 3 new Expense→Treasury couplings added → M-B |
| M3 | Backfill blind to inactive `403`/`4035` | ✅ **Fixed** in the command (`:85-94`, `:156`) and tested with exit-code assertion (`PayableInstrumentAccountsTest.php:131-148`) — ⚠️ **neutralized by the prescribed deploy invocation** → M-A |
| M4 | Vacuous `^…$` `not.toContainText` on payable total | ✅ **Fixed** — `smoke.ts:290-293` `toHaveAttribute('data-total', maturity.meta.grand_total.total_out)` against a real element (`InstrumentListPage.tsx:338-339`); exact equality, fails at zero |
| M5 | Paid assertion matched the "Paid From" label | ✅ **Fixed** — `smoke.ts:478` anchored `/^(Paid\|Payé\|مدفوع)$/`; badge exists at `ExpenseDetailPage.tsx:322-326`. Screenshot still doesn't show it → MINOR |
| M6 | Direction-blind "Received Date" / "Current Location" | ✅ **Fixed** — `InstrumentDetailPage.tsx:250,261` switch on `isOutbound`; keys in all 3 locales; pinned at `InstrumentDetailPage.test.tsx:149-174` |

## 3. Exit-invariant checklist

| Invariant | Status |
|---|---|
| Metadata never creates money outside the authorized GL/movement action | ❌ **B1** — inbound `cancel` mutates an outbound instrument's status with no JE |
| Idempotency + digest precede transition validation and GL; replay returns original IDs | ✅ `OutboundInstrumentService.php:71-95,224-246,381-406,533-555`; proven cross-process at `OutboundInstrumentConcurrencyTest.php:110-112` — ⚠️ B1's path writes no action key at all |
| Numeric strings + explicit currency scale | ✅ Zero float casts in the backend diff; `getScale($instrument->currency)` / `getScaleSafe(…, 3)` in console |
| Issue moves no cash / clear debits once / bounce compensates / represent advances cycle / cancel append-only | ✅ `OutboundInstrumentIssuer.php:201`; `:147`, `:296-313`, `:377-380`, `:641-665` — ❌ "cancel append-only and safe" fails via B1 |
| Deferred supplier: one issue JE, legacy JE suppressed | ✅ `PaymentController.php:1005-1032`; `DeferredSupplierPaymentTest.php:107-114,226` |
| Cancellation/clear + Expense projection atomic | ⚠️ Atomic on the ⑤a path; ❌ B1 bypasses the projection entirely |
| Tenant/company/permission/UUID boundaries; routes in Treasury group | ✅ `routes.php:145-159` inside the existing group; split permissions; `Str::isUuid` pre-query |
| Reconcile: portfolio drift ≠ cash drift, no freeze on portfolio-only | ✅ `ReconcileTreasuryCommand.php:198-204,468-505` — `alertPortfolioDrift()` never calls `freeze()`; distinct counters at `:226-239` |
| FE: cash preserves non-paper, instrument = cheque/effet, split schedules | ✅ `PayExpenseDialog.tsx:80-82,244-245,295-298`; `InstrumentListPage.tsx:316-361` |
| Deploy: 3 migrations in order, fail-loud backfill, per-tenant reseed + cache reset, API-driven verification, stop conditions | ⚠️ Migrations/reseed/cache-reset/API-driven scoping all correct (`:11-14,26-27,45`) — ❌ stop conditions undetectable under `tenants:run` (M-A) |

## 4. Test & evidence assessment

The **backend** suite remains the strongest part: `OutboundInstrumentConcurrencyTest.php:41-46` disables `RefreshDatabase` wrapping and forks a real second process on an independent PDO — real row locks. The new M1 rollback test uses live `Event::listen` failure injection with the real listener still registered, not `Event::fake()`. No `assertTrue(true)`, no mocking of the unit under test.

The **coverage shape** is the problem, and it is what let B1 through: every ⑤a test exercises `cancel-outbound`; not one exercises `cancel` against an outbound instrument. The only test that does — `OutboundInstrumentGuardTest.php:171` — asserts the hole is *correct*.

**Evidence integrity is better than round 1.** The five PNGs and `REPORT.md` are all `M` in `cffb5f76f`, so the re-capture is real, and 02/03 now visibly render "Instrument issued". Screenshot 05 is the exception (clipped).

**Unverified:** I did not execute the suites, and there is no committed artifact recording "49 tests / 287 assertions" or "25 tests" — both appear only in the implementer's own `.gates/gate-t5a-exit-r2-request-treasury.md:58-59`. Round 1 closed by asking that someone with execution rights actually run lint, typecheck, and the touched suites; that requirement is still satisfied only by self-report. Separately, `.github/workflows/smoke-test.yml:52-57` never sets `TREASURY_PHASE5A_API_BASE`, so the smoke provides zero standing regression protection — it is one-shot manual evidence.

One thing the branch gets right that I want on record: the deploy checklist at `:45` states plainly that outbound clear/bounce/re-present/cancel controls are not part of this phase. That is honest scoping, and "no outbound lifecycle UI" is therefore **not** a blocker. B1 is a blocker because the UI ships *wrong* affordances into a now-GL-bearing path, not because it ships too few.

---

VERDICT: spec ❌ + quality CHANGES-REQUESTED

**Add the outbound direction guard to `InstrumentLifecycleService::cancel()` (`:596-654`) mirroring `:137`, invert `OutboundInstrumentGuardTest.php:171` to assert rejection, gate the five inbound actions on `!isOutbound` at `InstrumentDetailPage.tsx:184-188`, and add the `tenants:run` failure-detection gate to the deploy checklist.**
