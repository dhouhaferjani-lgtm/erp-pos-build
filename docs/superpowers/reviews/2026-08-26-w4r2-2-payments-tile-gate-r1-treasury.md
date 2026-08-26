# W4R2-2 — adversarial gate r1 (treasury-reviewer)

**Lane:** dashboard "Payments Received" tile over-counts supplier payments + POS refunds
**Branch:** `fix/campaign-w4r2-2-dashboard-payments-received`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4r2-2-payments-tile`
**Range reviewed:** `8f50d7d54..7d7e229ee` (6 commits, 14 files)
**Mode:** READ-ONLY. Every claim below cites a file:line I opened in this worktree.

---

## VERDICT

**spec ❌ + quality CHANGES-REQUESTED**

The reader was never wrong and the two writer fixes are correct and well-argued. But the
history backfill **silently misses an entire live supplier-payment shape** (deferred /
outbound-instrument supplier payments), so the tile stays wrong on exactly the tenants that
pay suppliers by cheque or traite — the Tunisian norm. Separately, two behaviour-bearing
edits to a money report ship with zero test coverage, and the lane is demonstrably **not**
"tile-only" as LEDGER C-45(i) scopes it.

---

## What I verified as CORRECT (so the fix round does not undo it)

- **The reader is innocent.** `DashboardController.php:144-151` builds the whitelist by
  filtering `PaymentType::cases()` on `isIncoming()`; it never hardcodes a list. The defect
  was purely in the two writers.
- **`isIncoming()` / `isOutgoing()` rulings.** `PaymentType.php:151` (`POSRefund => false`)
  and `:168` (`POSRefund => true`); `SupplierPayment` was already `false` at `:148`. The
  `isIncoming()` match at `:142-156` is exhaustive with no `default`, so a future case
  cannot silently join the whitelist. Whitelist is now exactly
  `['document_payment','advance','pos']`, pinned at `DashboardStatsTest.php:802`.
- **Supplier arm placement.** `PaymentController.php:933-937` puts `$isSupplierPayment`
  first. The flag is set at `:526` from `DocumentType::SupplierInvoice`, the mixed-batch
  guard at `:634-641` refuses a payment that mixes supplier and non-supplier documents, and
  `storeMultiple()` cannot reach this branch (`rejectSupplierInvoiceInMultiline()`,
  `:1398-1410`). The flag is therefore unambiguous for the whole payment. Nothing else in
  `store()` branches on `$paymentType` (only `:933/:934/:936/:956`).
- **Bridge arm.** `TreasuryReceiptBridge.php:1468` keys off `$isRefund`, set at `:445` from
  `invoiceTypeCode === 'REFUND' || 'VOID'` — the same flag that selects
  `createPOSRefundReversalEntry()` at `:1487-1489` and `MovementDirection::Out` at `:1543`.
  Type and journal shape cannot drift. Amount stays positive; direction lives in the type.
- **No GL/ledger change.** No `GeneralLedgerService`, `JournalEntry`, `JournalLine` or
  movement code is in the diff. Confirmed by `git diff --stat`.
- **No AR-balance blast radius.** `increasesReceivable()` / `decreasesReceivable()` /
  `createsCredit()` have **zero** consumers outside `PaymentType.php` (grep across `app/`),
  so retyping `document_payment → supplier_payment` cannot move a partner balance.
- **Widening migration idiom is right.** `…150000_widen_…php:109-143` derives the value list
  from `PaymentType::cases()` (never hand-listed), runs the census FIRST, then
  `DROP CONSTRAINT IF EXISTS` + `ADD … NOT VALID` + `VALIDATE`. The emitted predicate
  `CHECK (payment_type IN (…))` at `:141` is byte-compatible with the shape
  `…130300::checkPredicate()` writes for a NOT NULL column (`…130300.php:238-243`), so
  `PgValueSetCheckReader` / `EnumCheckParityTest` keep parsing it. Idempotent by
  construction; strictly widening, so the census provably cannot find a newly-illegal row.
  Ordering is enforced by filename (`150000` < `150100`, both in
  `apps/api/database/migrations/tenant/`).
- **Behaviour on a tenant with rows outside the widened set:** `…150000:124-136` throws a
  `RuntimeException` naming table/column/value/count and aborts **that tenant only**. That
  is the documented fleet-abort. I also confirmed `…130300` is **not yet on `origin/dev`**
  (`git log origin/dev -1 -- …130300…` is empty), so on the current staging fleet the
  widening lands in the same batch as the constraint it widens and is a no-op.
- **No partial-unique-index collision.** The only partial uniques on `payments.payment_type`
  are `WHERE payment_type = 'refund'` (`2026_05_03_000004…:65,74`) and
  `WHERE payment_type = 'reversal'` (`2026_08_08_000001…:51`). The backfill moves rows out
  of `document_payment`/`pos`, never into those two.
- **Column default is still legal.** `SliceDBatch1EnumFreezeTest::columnDefaults()`
  (`:187-193`) pins `payments.payment_type DEFAULT 'document_payment'`, which remains inside
  the widened set.
- **Freeze pin reconciled, not bypassed.** `SliceDBatch1EnumFreezeTest.php:123-128` re-pins
  `pos_refund` **in declaration order** (between `pos` and `reversal`), which matches
  `PaymentType.php` (`POS` at `:25`, `POSRefund` at `:60`, `Reversal` after) — required
  because the test uses `assertSame` against `$enum::cases()` (`:206-212`). This is exactly
  the "ADDED case → ship a widening migration, then re-pin" path the test's own docblock
  prescribes (`:44-47`). `PaymentTypeReversalTest.php:99` moved 7→8 with an explicit D-6
  ruling at `:87-89`.
- **`generated.d.ts` regenerated, not hand-edited.** `PaymentType` line at `generated.d.ts:2557`
  now carries `'pos_refund'`. The four collateral drifts are additive union widenings and
  are inert for the FE: `apps/web/src/features/inventory-counting/types.ts:200` declares its
  own local `CountingItemFlagReason`, and no `apps/web`/`apps/pos` source imports the
  generated `PosVatRefusalReason`, `AllocationRefusalReason` or `FiscalPeriodCloseRefusalCode`.
- **Red-first on `dev` — genuinely.** `PaymentGlPostingTest.php:+293` asserts
  `PaymentType::SupplierPayment` where `dev` writes `DocumentPayment`, and
  `PosBridgeInstrumentRefundTest.php:+404` asserts `PaymentType::POSRefund` where `dev`
  writes `POS`. Both fail on `dev`. Arm (b) even ships a negative control (the sale leg must
  survive the backfill untouched).
- **No new float touches money.** Rule 19 clean: the diff introduces no cast, no
  `parseFloat`, no `number_format`. The one scale-bearing call I checked in touched code
  (`PaymentRefundService::refundReceiptPayments():2125`) passes
  `getScale($originalReceipt->currency)`, not the throwing no-arg form.

---

## BLOCKING findings

### [Critical] `…150100_retype_supplier_and_pos_refund_payments.php:154-172` — arm (a) does not match DEFERRED supplier payments; the tile stays wrong for cheque/traite AP

Arm (a)'s only evidence is a POSTED journal entry with
`source_type = 'supplier_payment'` and `source_id = payments.id`. That pair is written by
exactly one builder — `GeneralLedgerService::createSupplierPaymentJournalEntry()`
(`GeneralLedgerService.php:1025-1027`) — with exactly one caller,
`PaymentController.php:1181`.

But that caller sits in an **`elseif`**. The branch above it (`PaymentController.php:1163-1174`)
handles `$isDeferredSupplier` — a supplier paid with an outbound instrument (cheque, traite) —
and routes through `OutboundInstrumentIssuer::issueExisting()`
(`OutboundInstrumentIssuer.php:180`) → `createOutboundInstrumentIssueEntry()`, which stamps
`'source_type' => 'instrument'` and `'source_id' => $instrumentId`
(`GeneralLedgerService.php:1199,1201`) — **never** `('supplier_payment', payment.id)`.
The two branches are mutually exclusive.

Consequence: every historical deferred supplier payment keeps
`payment_type = 'document_payment'`, whose `isIncoming()` is `true`
(`PaymentType.php:143`), and therefore **remains counted in the "Payments Received" tile
after this migration runs**. The migration's own stated purpose — "correct the history so
the tile is right for periods already closed" (`:29-30`) — is not met for that shape.

This is not the "ambiguous evidence, leave it alone" case the docblock rules on at `:91-97`.
The evidence is unambiguous and already written down: the payment has a
`payment_allocations` row pointing at a `documents.type = 'supplier_invoice'` — which is
precisely the residual census the docblock prints at `:99-104`, and precisely how the live
writer decides `$isSupplierPayment` in the first place (`PaymentController.php:524-526`).
The lane deliberately widened `store()` to type deferred supplier payments correctly going
forward (the `match(true)` at `:933` fires on `$isSupplierPayment` irrespective of
deferral), so the writer and the backfill now disagree about what a supplier payment is.

The `DeferredSupplierPaymentTest` the report lists as green is a **forward** test; nothing
exercises the backfill against a deferred row.

**Fix (pick one, explicitly):** (i) add a third arm keyed on the
`payment_allocations → documents.type = 'supplier_invoice'` evidence (with the same
`payment_type = 'document_payment'` idempotency guard), and cover it with a test that builds
the row through the real deferred-supplier flow; or (ii) if the owner rules that only
GL-evidenced rows may be rewritten, say so in the docblock **by name** ("deferred supplier
payments post `source_type='instrument'` and are deliberately left for the residual census")
and add the shape to the deploy checklist so an operator fixes it by hand. Silently
under-fixing a known shape is the one option that should not ship.

### [Important] `CashMovementsReportService.php:97` + `:309,:333-338` — two behaviour-bearing edits to a money report, zero test coverage; the existing test now pins a shape production no longer writes

`PaymentType::POSRefund` was added to `OUTGOING_PAYMENT_TYPES` (`:97`) and as a third
positional binding on the direction `CASE` (`:309` widened to `IN (?, ?, ?)`, binding at
`:338`). Both edits are correct as written — I traced `movingPaymentTypes():546-552` and the
de-dup `whereNotExists` at `:474` and `:496`.

No test covers either. The only POS-refund test in the report,
`CashMovementsReportTest.php:671` (`test_cash_movements_report_counts_pos_refund_once_as_a_single_outflow`),
still constructs the refund leg as `paymentType: PaymentType::POS` at `:746`, with a comment
at `:675-677` that now states something false ("payment_type=POS, origin=Pos"). It stays
green via the `pos_receipt_refund` CASE arm, so it is a **legacy-shape** test: after this
lane, production never produces the row it exercises.

Why this matters more than usual here: the file's own `⚠️ HAND-COUNTED POSITIONAL LIST`
warning at `:328-330` says an off-by-one in these bindings "silently mislabels the direction
of EVERY row in the report", and had `POSRefund` been omitted from `OUTGOING_PAYMENT_TYPES`
the report would have dropped every POS refund out of `movingPaymentTypes()` entirely — the
implementer identified that hazard in the comment at `:98-101` but did not pin it.

**Fix:** duplicate `:671` with `paymentType: PaymentType::POSRefund` (asserting
`data.0.source_type = 'payment'`, `direction = 'out'`, count 1 — the count+source_type pair
is what catches an omission from `OUTGOING_PAYMENT_TYPES`, because the GL twin would then be
emitted instead), and refresh the stale comment at `:675-677`.

### [Important] Scope: this is not "tile-only" per LEDGER C-45(i), and the divergence is unacknowledged

The backfill rewrites historical rows, and `payment_type` is an input to reversal
eligibility. `PaymentType::reversalSupport()` answers `CashReversal` for `DocumentPayment`
(`PaymentType.php:226`) but `Unsupported` for `SupplierPayment` (`:231`). So a historical
supplier payment that was reversible through `PaymentRefundService::reversePayment()` before
the migration is **refused after it** (`unsupportedReversalMessage():1471-1473`).

I believe this is a net improvement — for a supplier payment,
`PaymentLedgerPartitionReader::read()` (`PaymentLedgerPartitionReader.php:62-123`) returns an
empty partition (it only sums `customer_payment`/`advance`/`payment_advance_reclass` source
types), and per `InstrumentLifecycleService.php:792-793` "an empty partition keeps the legacy
single AR restoration", i.e. the old path could post a wrong AR-restoring entry for money
that never touched 411. Retyping closes that.

But it is still a user-visible behaviour change on existing data, produced by a lane whose
LEDGER scope says "ledger/GL unchanged, tile-only". It needs an explicit owner ack, and it
needs a test: nothing in the diff pins "a backfilled supplier payment is refused by
`reversePayment()` with the supplier-lane message". Same class of out-of-tile change, lower
stakes: `RepositoryDetailPage.tsx:118` and `PaymentDetailPage.tsx:115` map
`'supplier_payment' → 'supplier_invoice'` for the allocation badge, and
`RepositoryDetailPage.tsx:502` flips `partnerType` to `'supplier'` — retyped historical rows
will render differently.

---

## Non-blocking findings

### [Important] Deploy window: new code can write `pos_refund` before `tenants:migrate` finishes

`TreasuryReceiptBridge.php:1468` writes `pos_refund` as soon as the new image is live. On any
environment where `…130300` has already run, the pre-widening CHECK raises SQLSTATE 23514
inside `apply()` until `…150000` lands, failing the fiscal-projection job (it will retry and
succeed post-migrate, but noisily). Low risk on the current fleet — I confirmed `…130300` is
not yet on `origin/dev` — but this belongs on the deploy checklist next to the two per-tenant
censuses, not only in the report's "concerns".

### [Important] Backfill arm (a) has no negative control

`PaymentGlPostingTest.php:+300-320` runs the backfill against a database containing exactly
one payment. Arm (b) does have a negative control (`PosBridgeInstrumentRefundTest.php:+426-435`
re-taints both legs and asserts the sale leg survives). Arm (a) needs the same: a genuine
customer `document_payment`, with its own posted `customer_payment` entry, present in the same
DB and asserted unchanged after `up()`. Without it, "the predicate does not over-match" is
asserted nowhere.

### [Minor] `CashMovementsReportService.php:319-321` — stale comment

"A POS refund leg keeps payment_type=POS (indistinguishable from a sale on that column)" is
now false; the whole point of the lane is that it is distinguishable.

### [Minor] i18n gap widened in practice — `payments.types.pos_refund` missing in all three locales

`apps/web/src/locales/{en,fr,ar}/treasury.json` carry only
`document_payment, advance, refund, credit_application, supplier_payment`.
`PaymentDetailPage.tsx:160` does `t('payments.types.' + type)`, so an unmapped value renders
the **raw key** to the user (rule 11). Not a regression — `pos` and `reversal` were already
missing, so a POS refund row already rendered a raw key — but the backfill now moves real
rows onto a third missing key. Three lines per locale; cheapest thing on this list.

### [Minor] `PaymentDetailPage.tsx:44` — local `PaymentType` union not widened

`'document_payment' | 'advance' | 'refund' | 'credit_application' | 'supplier_payment'`,
still missing `pos`, `pos_refund`, `reversal`. Deliberately narrower per the report; worth a
follow-up ticket alongside the i18n keys.

### [Minor] `Payment.php:290-296` — `scopeIncoming()` left asymmetric with the lane's own `scopeOutgoing()` edit

`scopeOutgoing()` gained `POSRefund` at `:315`. `scopeIncoming()` still lists only
`DocumentPayment` + `Advance` and omits `POS`, which `isIncoming()` answers `true` for
(`PaymentType.php:145`). Pre-existing drift with no callers today, but the lane touched the
twin scope and left this one, so a future caller inherits a scope that disagrees with the
enum. Either fix it or add a one-line note saying why not.

### [Minor] Hardcoded bcmath scale in a new test assertion

`PosBridgeInstrumentRefundTest.php:+399` — `bccomp((string) $refund->amount, '0', 3)`.
Rule 19 forbids hardcoded scales; `tests/` is outside `phpstan.neon`'s `paths:` so no gate
fires. Prefer the injected resolver or a named constant for consistency.

### [Observation] `DashboardStatsTest.php:+745` exercises the reader only

`test_payments_received_excludes_supplier_payments_and_pos_refunds` inserts rows already
carrying the corrected types, so it pins the reader, not the bug. That is fine and stated
honestly in its docblock — the real red-first coverage is in the two writer tests — but it
should not be cited as "the campaign tile reproduced end-to-end".

---

## One-line fix list before merge

Close the deferred-supplier hole in backfill arm (a) (or name it as deliberate in the
docblock + deploy checklist), add the `POSRefund` cash-movements test and an arm-(a) negative
control, and get an explicit owner ack that retyping history changes reversal eligibility —
i.e. that this lane is not tile-only.

---

## r2 scoped re-review

**Range:** `7d7e229ee..950c7f4df` (5 commits, 14 files). **Mode:** READ-ONLY. Every claim cites a
file:line I opened in the worktree.

**VERDICT: spec ✅ + quality APPROVED — ALL r1 findings ADDRESSED.** Two merge-time residuals
(promotion-checklist row, test-run evidence) and four deferred minors, none blocking.

> Note: the fix report named in the dispatch
> (`.superpowers/sdd/PLAN/task-2-report.md`) **does not exist** in this worktree — only
> `.superpowers/sdd/task-2.2-report.md`, an unrelated Media lane. I reviewed the diff and the
> files directly instead. **Cannot verify** the claimed test runs (no `.env.testing` here; I did
> not execute the suite).

### r1 findings — disposition

**1. [Critical] arm (a) misses deferred (cheque/traite) supplier payments — ADDRESSED.**
Arm (a2) added at `2026_08_25_150100_retype_supplier_and_pos_refund_payments.php:243-261`,
rationale at `:73-117`. I did not take the predicate on trust — I enumerated every writer of
`source_type='instrument'` and confirmed the Dr-401 belt isolates exactly the issue entry:

- `GeneralLedgerService.php:1199` is the shared outbound-instrument helper, so ALL FOUR outbound
  builders carry `source_type='instrument'`. Only ISSUE debits 401 (`:1080-1082`,
  `debitAccountId: $supplierPayable->id`); CLEARING is `Dr payable-instrument / Cr bank`
  (`:1104-1107`); DISHONOR is `Dr bank / Cr payable-instrument` (`:1128-1131`); CANCELLATION
  *credits* 401 (`:1154-1157`, `creditAccountId: $supplierPayable->id`) → cannot satisfy
  `debit > 0` on that account.
- Inbound: `createInstrumentRepresentationEntry()` `Dr portfolio / Cr 411`
  (`GeneralLedgerService.php:3115-3117`); `createInstrumentClearingEntry()` debits bank/fee/VAT
  (`:3178-3204`); `createInstrumentDishonorEntry()` debits nominal + fees (`:3286-3300`);
  `createInstrumentCancellationEntry()` debits are 70x/4457 or 411/419 only
  (`:3530-3575`, `b2bCancellationDebits():3608-3655`). None touches `supplier_payable`.

**Can a genuine INCOMING payment satisfy arm (a2)? No.** The only other writers of
`payments.journal_entry_id` are `ReceiptPaymentService.php:333` and
`TreasuryReceiptBridge.php:1507` (both POS legs, `payment_type` never `document_payment`) and
`PaymentAllocationService.php:396,438,483` (customer payment / customer advance entries —
`createPaymentReceivedJournalEntry`, `source_type='customer_payment'`). A customer cheque
RECEIVED takes `$isDeferredCustomer` and links a `customer_payment` entry
(`PaymentController.php:1159-1161, 1192-1213`). A cancellation credit to 401 fails `debit > 0`.
The Expense lane calls `issue()`, not `issueExisting()` (`ExpenseService.php:874`), and writes no
`payments` row at all — so it cannot be caught either.

**Posted-ness holds:** `OutboundInstrumentIssuer.php:188` calls `postEntryNow()` in-transaction,
so a genuine issue entry is always `posted`. **Idempotent:** the `payment_type='document_payment'`
guard at `:247` makes a re-run match zero rows, and arms (a1)/(a2) are mutually exclusive
(different `source_type`, different join key).

**Covered by a real test:** `DeferredSupplierPaymentTest.php:250-313` builds BOTH rows through the
real HTTP route, re-taints only the supplier row, runs the real migration, asserts the deferred
CUSTOMER cheque (a genuine `document_payment`, also instrument-backed) survives untouched, and
re-runs `up()` to pin idempotency.

**2. [Important] CashMovements coverage / legacy-shape test — ADDRESSED.**
`CashMovementsReportTest.php:783-810` pins the shape production writes now (POSRefund leg + posted
`pos_receipt_refund` entry → `source_type='payment'`, `direction='out'`, count 1 — the
count+source_type pair that catches an omission from `OUTGOING_PAYMENT_TYPES`).
`:824-849` isolates the NEW positional binding by using a **Draft** reversal entry, so the
`pos_receipt_refund` arm cannot mask it and `direction='out'` is the `payment_type IN (?, ?, ?)`
binding and nothing else — exactly the hazard the `⚠️ HAND-COUNTED POSITIONAL LIST` warning
describes. The stale comment is refreshed at `:672-684`, and the service-side one at
`CashMovementsReportService.php:319-330`. I re-counted the bindings: 3 placeholders at `:309`,
3 values at `:339-347`, aligned.

**3. [Important] reversal-eligibility + FE consequences — ADDRESSED (per the orchestrator ruling
that the retype is correct semantics).**
The refusal is pinned by `PaymentReversalRefusalTest.php:222-263`: it asserts the pre-backfill
shape is reversal-ELIGIBLE (`assertNotSame(ReversalSupport::Unsupported, …)`), moves the row with
the **real** migration from real arm-(a1) evidence, then asserts `reversePayment()` throws naming
the *supplier refund lane* and that the refusal is a strict no-op (payment/allocation counts and
status unchanged). The data provider also gained `'pos refund'` at `:144`.
FE: all three locales now label all eight enum cases (verified `en/fr/ar` keys ==
`PaymentType.php:10-74` cases); `RepositoryDetailPage.tsx:557` renders a label for every type
instead of collapsing non-advance rows to `'-'`; both pages alias the generated union
(`PaymentDetailPage.tsx:49`, `RepositoryDetailPage.tsx:66` — the latter tightened from bare
`string | null`), which matches `generated.d.ts:2557`.

**4. [Important] arm-(a1) negative control — ADDRESSED.**
`PaymentGlPostingTest.php:305-316` posts a genuine customer payment through `/api/v1/payments`
into the SAME database before the backfill, `postCustomerPayment():404-457` asserts it carries a
real `customer_payment` journal entry, and `:330-334` + `:342-346` assert it is unchanged after
both `up()` runs. An arm (a1) that matched every `document_payment` now fails this test.

**5. [Important] deploy-window note — ADDRESSED (with a merge-time residual).**
`2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund.php:56-64` carries an explicit
"run `tenants:migrate` BEFORE rolling the new API image" section, sitting next to the censuses as
r1 asked. **Residual:** `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §2 (`:21`) still lists no
row for `2026_08_25_150000` / `…150100` nor the migrate-before-roll ordering — the lane is on the
hold-list at `:7`, so that row is owed at merge, not now.

**6. All five [Minor]s — ADDRESSED.**
- stale service comment → `CashMovementsReportService.php:319-330`.
- i18n gap → `en/fr/ar treasury.json` `payments.types` now carry `pos`, `pos_refund`, `reversal`;
  all 8 enum cases labelled in all 3 locales (checked programmatically).
- local `PaymentType` union → aliased to the generated type, `PaymentDetailPage.tsx:49`.
- `scopeIncoming()` asymmetry → both scopes now DERIVE from the enum via
  `Payment::paymentTypeValues()` (`Payment.php:299-336`). I verified the derived sets:
  incoming = `{document_payment, advance, pos}` and outgoing =
  `{refund, supplier_payment, pos_refund, reversal}` against `PaymentType::isIncoming():142-156`
  and `isOutgoing():164-173` — the outgoing set is byte-identical to the hand-list it replaces, so
  the "not a behaviour change" claim at `Payment.php:317-320` is true. The one live consumer,
  `PaymentReversalModelSurfaceTest.php:34,49`, still passes on the derived sets.
- hardcoded bcmath scale → `PosBridgeInstrumentRefundTest.php:416-424` now resolves the scale from
  `CurrencyScaleResolverInterface` keyed on the payment's own currency.

### New observations in the fix diff (all non-blocking / deferred)

- **[Minor] `Payment.php:330` — `scopeOutgoing()` now inherits a non-exhaustive match.**
  `PaymentType::isOutgoing()` ends in `default => false` (`PaymentType.php:172`) whereas
  `isIncoming()` is exhaustive (`:142-156`). Deriving the scope from it means a future enum case
  silently lands outside the outgoing scope with no compile error — the exact drift class the fix
  set out to remove, half-closed. Deferred; making `isOutgoing()` exhaustive would close it.
- **[Minor] `PaymentDetailPage.tsx:412-419` — the "Reverse" button is still unconditional** for any
  `completed` payment, so a backfilled supplier payment now surfaces the (correct) new refusal as a
  422 error toast rather than a disabled control. Outside the orchestrator's scoped FE ask
  (badge + i18n); deferred as a UX follow-up.
- **[Minor] migration `:142-143` overstates the residual census.** "this should now be EMPTY on a
  tenant with no unposted AP" — but `repository_id` is `nullable`
  (`PaymentController.php:395-399`), so a historical IMMEDIATE supplier payment posted without a
  repository has no journal entry at all, matches neither arm, and will legitimately appear in the
  census. Doc precision only; the census is the correct catcher and the "missing entry keeps
  `document_payment`" ruling at `:138-140` already covers the behaviour.
- **[cannot verify] Test execution.** The dispatch's fix report is absent and there is no
  `.env.testing` in this worktree; I reviewed the tests as code and did not run them. A green run
  of `DeferredSupplierPaymentTest`, `PaymentGlPostingTest`, `PaymentReversalRefusalTest`,
  `PosBridgeInstrumentRefundTest`, `CashMovementsReportTest`, `PaymentReversalModelSurfaceTest` is
  owed before merge.

### One-line fix list before merge

Nothing blocking — attach the test-run evidence and add the two migrations + the
migrate-before-roll ordering to `PROMOTION-CHECKLIST-2026-08-26.md` §2 when the lane leaves the
hold-list.
