# Lane C — Wave 2 (POS device leg, v3→v4 refund chain) — Fiscal/POS Adversarial Gate

**Range reviewed:** `ea2487e9b..07408707e` (9 commits), worktree `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain`, branch `feat/v3-refund-chain-integration`.
**Spec:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` (rev 4.2/4.3 FINAL).
**Scope:** full re-review, treated as never reviewed (the prior dual-review's findings were lost).
**Method:** every finding below was read in the actual working-tree file, not the diff. Targeted vitest run executed (`resolveOriginalFiscalEventLocally.realAuthoring`, `RefundReceiptV4Payload`, `refundCheckoutStore`): **57/57 pass**.

---

## VERDICT: spec ❌ + quality **CHANGES-REQUESTED (REJECT)**

Six Critical defects. Three of them (C-1, C-2, C-4) are unrepairable-after-the-fact fiscal or
money outcomes; one (C-2) blocks the register outright. The recovered envelope-payload fix
(`07408707e`) is **correct and well-proven** — but it is not the only fiscal hole in this wave.
The wave cannot ship as-is.

---

## CRITICAL

### C-1 — Signed X_REPORT silently folds v4 refunds into sale aggregates and reports `refunds_count: 0`
`apps/pos/src/api/reportApi.ts:405-411` (query), `:427-449` (loop), `:487-533` (fiscal append)

`generateLocalXReport()` selects **every** `offline_receipts` row for the shift with no
`receipt_kind` branch:

```
SELECT * FROM offline_receipts WHERE terminal_id = $1 AND created_at >= $2   -- :407-409
...
grossSales = bcadd(grossSales, receipt.total);      // :428  ← refund total is NEGATIVE (§7.2)
netSales   = bcadd(netSales, receipt.subtotal);     // :429
taxAmount  = bcadd(taxAmount, receipt.tax_amount);  // :430
...
sales_count: receipts.length,                        // :454  ← counts refunds as sales
refunds_count: 0,                                    // :459  ← hardcoded
```

and then **signs** those totals into an immutable `X_REPORT` fiscal event
(`appendXReport(...) reportTotals: { sales_count, gross_sales, net_sales, tax_amount,
refunds_count, refunds_amount: bcformat('0', decimals) }`, `:490-511`).

This file is a **third, structurally separate consumer** of `offline_receipts` that §7.3
(`zReportService.ts`) and §7.3a (`endOfDayPreview.ts`) both fixed and that §17's manifest never
enumerated. It is reachable on the **primary** path, not a fallback: `generateXReport()` routes
straight to the local generator whenever `opts.fiscalSessionId` is defined (`:145-148`).

**Failure scenario.** Shift sells 100.00 and refunds 20.00. The cashier runs an X report. The
signed `X_REPORT` event claims `gross_sales = 80.00`, `sales_count = 2` (one of them a refund),
`refunds_count = 0`, `refunds_amount = 0.000` — sale-only semantics violated in the exact
direction §7.3 forbids ("never folded into gross/net sales"), and the refund is invisible. Events
are immutable forever (rule 8); a wrong signed X cannot be corrected, only superseded by an
explanation. This is a regression **created by** this wave: before §7.2's insert, no refund row
could exist in `offline_receipts`.

**Fix.** Give `generateLocalXReport()` the same explicit `receipt_kind` branch §7.3 gives
`aggregateReportData()` — sale-only gross/net/tax/`sales_count`, `refundsCount++` /
`refundsAmount = bcadd(refundsAmount, bcabs(receipt.total), decimals)`, payment-method
subtraction — and add the file to the manifest. (Note while you are there: this query also lacks
`is_training = 0` and `voided = 0`, both of which the Z has — pre-existing, out of this scope,
but worth a ticket.)

### C-2 — "No / Not sure" on the payout prompt is an infinite, unescapable modal loop; the only exit is a false attestation
`apps/pos/src/lib/db/repositories/refundIntentRepository.ts:304-313`,
`apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:170-217`, `:252-253`

```php-none
-- refundIntentRepository.ts:308-311
SELECT * FROM refund_intents
 WHERE refund_fiscal_event_id IS NOT NULL AND payout_confirmed_at IS NULL
 ORDER BY created_at ASC
```

The pending-confirmation query has **no `payout_disputed_at IS NULL` term**.
`handleDisputePayout()` calls `disputeRefundIntentPayout()` (which sets only
`payout_disputed_at`, `:281-289`) and then `await refresh()` (`:213`) — which re-runs the query
above, gets the same row back (its `payout_confirmed_at` is still NULL), and re-renders the same
prompt. The modal is `isOpen` unconditionally with `onClose={() => {}}` and `closable={false}`
(`:250-253`) and the confirm branch has **no Skip button** (only "Yes" and "No / Not sure",
`:274-293`).

**Failure scenario.** A cashier who genuinely cannot confirm the cash left the drawer taps
"No / Not sure". The prompt reappears immediately. Every subsequent tap re-stamps
`payout_disputed_at` and re-shows it. Restarting the app re-prompts on mount (`:140-143`). The
POS is blocked behind a non-dismissible modal until the cashier taps **"Yes"** — recording a
false payout confirmation, which is precisely the evidence §4.5/§5.2 relies on for the
write-off attestation. The reprint branch, by contrast, *does* have a working Skip (`:236-241`).

**Fix.** Add `AND payout_disputed_at IS NULL` to `getRefundIntentsPendingPayoutConfirmation()`
(the disputed state is a terminal reconciliation outcome, §4.5 — it must not re-prompt), or give
the confirm branch its own local Skip. Then extend the test (see I-5).

### C-3 — A re-authored approval regenerates `approval_id`, so the refund's evidence can never resolve server-side → non-retryable dead-letter **after** the cash left the drawer
`apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:114`, `:158`, `:189`;
`apps/pos/src/lib/db/repositories/refundIntentRepository.ts:37-41`, `:145-153`;
`apps/pos/src/stores/refundCheckoutStore.ts:651-657`, `:910-934`;
`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1045-1055`

`authorPosOverride()` mints a **fresh** `approval_id` on every call (`:114
const approvalId = crypto.randomUUID();`) but threads the intent's **stable, pre-generated**
`sourceEventIds` into `engine.append()` (`:158`, `:189`). `FiscalEventEngine.append()`'s
source-based idempotency (`FiscalEventEngine.ts:580-592`) therefore returns the **existing**
approval/override events on a second call — while the returned `PosOverrideEvidence` carries the
**new** random `approval_id` (`:201`).

The projector cross-checks that field against both resolved events' own payloads:

```php
foreach (['approval_id', 'approval_scope', 'policy_version', 'supervisor_user_id'] as $field) {
    if ($referenceValue !== ($approvalPayload[$field] ?? null) || ...) {
        throw new ApprovalEvidenceUnresolvedException(...);   // NonRetryableProjectionException
```
(`PosCoreReceiptProjection.php:1045-1055`)

**Reachability (an ordinary cashier action, not an edge case).**
1. Cashier begins a v4 refund, enters the manager PIN → approval + override signed against the
   intent's pre-generated ids; intent state `approval_authored`.
2. The settle fails for any local reason, or the cashier simply presses **Cancel** at the
   approval step — `cancel()` is permitted there (`refundCheckoutStore.ts:655`) and resets the
   store, discarding the cached `approval`.
3. Cashier presses Pay again on the same return cart. `createOrReuseActiveRefundIntent()`
   **reuses** the same intent row (`approval_authored` ∈ `ACTIVE_STATES`, `:37-41`), so the same
   `approval_source_event_id`/`override_source_event_id` are threaded again.
4. `authorRefundReturnApprovalV3()` → `authorPosOverride()` → idempotent hit returns the OLD
   events but a NEW `approval_id`.
5. The v4 refund is signed with `approval_references[0].approval_id` = new UUID, cash is paid
   out, the AVOIR prints.
6. Server: `approval_id mismatch across reference/approval/override` →
   `ApprovalEvidenceUnresolvedException` → **immediate dead-letter, no retry** (§4.2). The refund
   never books. Recovery is the §5 manual write-off compensation flow.

**Fix.** Persist `approval_id` on `refund_intents` alongside the two source-event ids and pass it
into `authorPosOverride()` (new optional `approvalId` parameter), or have `authorPosOverride()`
read the `approval_id` back out of the existing event's `canonical_bytes` when the append was an
idempotent hit. Add a regression test that authors twice with the same `sourceEventIds` and
asserts the returned `approval_id` is stable.

### C-4 — `original_receipt_reference.original_business_date` is stamped with **today**, permanently, in the signed chain
`apps/pos/src/stores/refundCheckoutStore.ts:992`, `apps/pos/src/pages/HomePage.tsx:210`,
`apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:337`,
`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1941`

```ts
// refundCheckoutStore.ts:985-992
businessDate:         input.approvalContext.businessDate,
...
originalBusinessDate: input.approvalContext.businessDate,   // ← the CURRENT business date
```
and `approvalContext.businessDate` is `new Date().toISOString().slice(0, 10)`
(`HomePage.tsx:210`) — i.e. **today**, not the original sale's date. It lands verbatim in the
signed payload (`RefundReceiptV4Payload.ts:337`), and the server only checks the field's *shape*
(`$this->assertIsoDate($ref, 'original_business_date', ...)`,
`FiscalPayloadConstraintValidator.php:1941`) — there is no cross-check against the resolved
original, so the falsehood is accepted silently and sealed forever.

**Failure scenario.** A 1 July sale refunded on 1 August produces a signed v4 payload asserting
the original was sold on 2026-08-01. Every canonical-payload consumer (NF525 export, auditor,
future return-window advisory per §3.6) reads a fabricated fact. Rule 8 makes it unrepairable.

**Fix.** The correct value is already one field away — the envelope this wave now correctly
parses carries `payload.business_date`. Add `businessDate` to `OriginalFiscalEventLocalView`
(`fiscalEventRepository.ts:142-154`), read it fail-closed like the other four fields
(`:223-241`), and pass `original.businessDate` at `refundCheckoutStore.ts:992`.

### C-5 — No cash-tender check on the original: a card-tendered sale is refunded in **cash** out of the drawer
`apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:147` (resolved),
`apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:219-248`,
`apps/pos/src/lib/offline/refundReceiptService.ts:159`

`resolveOriginalFiscalEventLocally()` resolves the original's `payments[]` into the view
(`readonly payments: readonly PaymentInput[];`, `:147`) — and **nothing ever reads it**
(`grep '\.payments\b'` across `lib/refundFlow`, `RefundReceiptV4Payload.ts`,
`refundCheckoutStore.ts`, `refundReceiptService.ts`: no non-test hits). The builder validates
only that the *refund's own* leg is CASH (`:239-248`); `createRefundReceipt()` unconditionally
constructs `payment: { methodCode: 'CASH', amount: total }` (`:159`).

Spec §9.6 is explicit: *"The cash-only launch payload (§3) simply cannot represent a refund of an
original that wasn't cash-tendered — such an attempt is **refused** by the same typed mechanism
as any other unsupported destination."* That refusal does not exist.

**Failure scenario.** Customer returns a €200 card purchase. The device pays out €200 **cash**
from the drawer, the card leg is never reversed, and the shift's expected cash correctly drops by
€200 with no offsetting cash-in. The books are internally consistent and the outcome is still
wrong money: an uncontrolled cash-out-for-card-sale channel on a single-terminal launch tenant.

**Fix.** In `beginV4()`, immediately after `assertOriginalRefundable()`
(`refundCheckoutStore.ts:742-755`), refuse when the resolved original's `payments[]` is not a
single `method_code === 'CASH'` leg. The typed refusal copy already exists
(`refundFlow.capabilityUnavailable` is unused — see M-4 — or add a dedicated key). Extend
`assertOriginalRefundable()` so the builder re-asserts it as defense-in-depth, matching the
§3.5/§3.7 pattern.

### C-6 — The §9.3 Phase-2 acknowledgement endpoint does not exist server-side, so `LegacyCorrectionGuard` can never activate — and the failure is swallowed
`apps/pos/src/lib/sync/syncService.ts:1311-1320`, `:1390-1393`, `:1415-1418`;
`apps/api/app/Modules/POS/routes.php:53-72`;
`apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php:36`

The device posts `POST /pos/terminals/{id}/acknowledge-v4-refund-authoring`
(`syncService.ts:1313`). No such route exists: `apps/api/app/Modules/POS/routes.php` defines
`show`, `update`, `activate`, `deactivate`, `archive`, `toggle-training`, `z-chain-state`,
`fiscal-schema-cutover` — and nothing else on `/pos/terminals/{id}`. A repo-wide grep for
`acknowledge-v4-refund-authoring` returns **only** the device call site. The call is wrapped in a
best-effort try/catch that downgrades the 404 to a `console.warn` (`:1315-1320`), so nothing
surfaces.

`v4_refund_authoring_acknowledged_at` therefore stays NULL forever, and
`LegacyCorrectionGuard::…` (`:36 if ($terminal->v4_refund_authoring_acknowledged_at !== null)`)
never fires.

**Failure scenario.** Tenant #1 is enabled for v4. The device correctly routes to the v4 flow, but
the legacy `/return` endpoint stays open **permanently** for that terminal — the exact endpoint
whose chain-corruption failure mode (§1: `23514` / `23505` / orphan-commit / 422) this entire lane
exists to lock out. Any other client (web POS, a stale device build, a manual call) reproduces §1
against a live v4 terminal, and §9.5 step 4 of the rollout can never be marked complete. §9.3
budgeted "one ordinary sync cycle" of exposure; as shipped it is unbounded and unobservable.

**Fix.** Either land the server endpoint + `v4_refund_authoring_acknowledged_at` write in this
wave (the column, model cast and guard already exist from wave 1 —
`Terminal.php:134-135`, `LegacyCorrectionGuard.php:36`), or get an explicit orchestrator ruling
deferring it to wave 3 **and** make the client failure loud (a surfaced sync warning, not a
`console.warn`). Shipping the device leg against a nonexistent endpoint with a silent catch is
not acceptable either way.

---

## IMPORTANT

### I-1 — A partial refund of a line that carried a per-line discount fails hard, after the manager PIN has already been spent
`apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts:90-97`,
`apps/pos/src/stores/cartStore.ts:175-206` (esp. `:181`, `:188-190`),
`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:281-294`

`hydrateFromReceipt()` carries the original line's `discount_amount` onto the return `CartItem`
(`:92-96`) but **not** `discount_type`. `recalcLineTotal()` only subtracts a discount when
`item.discount_type === 'fixed'` (`:188-190`), so the moment the cashier edits the return
quantity, `line_total` becomes `unit_price × qty` with the discount silently dropped — while
`discount_amount` stays on the item.

`buildLineItems()` then enforces `line_total == unit_price × quantity − discount` exactly
(`SaleReceiptPayload.ts:281-294`) and throws `LineArithmeticInvariantError`.

**Failure scenario.** Cashier refunds 1 of 3 units of a discounted line. `begin()` succeeds, the
confirm screen shows, the manager enters the PIN (approval + override fiscal events **signed and
appended**), then `buildRefundReceiptV4Payload()` throws and the UI shows the generic
`errorInternal`. The refund is impossible, an orphan approval pair is permanently on the chain,
and the intent is stuck at `approval_authored`. Fail-closed (no wrong money), but the capability
is broken for a routine case and the diagnosis is invisible to the operator.

**Fix.** Either carry `discount_type: 'fixed'` through `hydrateFromReceipt()` and prorate the
discount in `recalcLineTotal()` for return lines, or refuse quantity edits on discounted return
lines with a typed, translated refusal **before** the approval step.

### I-2 — Retrying an already-appended intent rolls back with a generic internal error; a legitimate repeat identical partial is impossible until sync
`apps/pos/src/lib/db/repositories/refundIntentRepository.ts:37-41`, `:225-238`, `:209-211`;
`apps/pos/src/lib/offline/refundReceiptService.ts:194-210`

`refund_event_appended` is in `ACTIVE_STATES`, so `createOrReuseActiveRefundIntent()` reuses an
intent whose fiscal event is already appended. `createRefundReceipt()` then re-enters:
`engine.append()` idempotent-returns the existing event, and `markRefundEventAppended()` — which
guards `fromStates = ['approval_authored']` — updates zero rows and throws
`InvalidRefundIntentTransitionError` (`:209-211`), rolling back the whole write-gate transaction.

**Failure scenario A (repeat business).** §4.4 rules that repeat identical partials ("1 of 2 units
today, 1 of 2 next week") must be allowed, with §12's server cap as the sole authority. If the
first intent has not yet synced, the second identical attempt reuses it and dies with
`errorInternal`.
**Failure scenario B (double-tap after commit).** Same generic error, no indication the refund
already succeeded.

**Fix.** In `createRefundReceipt()`, detect the idempotent-append hit (returned event id ==
`intent.refund_fiscal_event_id`) and short-circuit to a success result instead of re-running
writes 2 and 3; and/or surface a typed `refundFlow.alreadyInFlight` refusal from `beginV4()` when
the reused intent is already at `refund_event_appended`.

### I-3 — The v4 AVOIR prints without the original receipt number/QR — a regression against the legacy path
`apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:75-81` (esp. `:78`),
`apps/pos/src/lib/buildReceiptData.ts:241-243`, `apps/pos/src/pages/HomePage.tsx:1206-1211`

`attemptPrint()` builds the AVOIR with `extras: { receiptKind: 'refund', qrToken: null }`
(`:78`) — no `originalReceiptNumber`, no `originalReceiptQrToken` — so
`buildEscPosReceiptData()` emits `original_receipt_number: null` /
`original_receipt_qr_token: null` (`buildReceiptData.ts:242-243`) and the REMBOURSEMENT header
block is empty. The legacy path passes both (`HomePage.tsx:1206-1211`).

**Failure scenario.** Every v4 AVOIR prints with no reference to the ticket it corrects, and no
QR for a follow-up partial refund. For a corrective receipt this is a fiscal-presentation
regression, not cosmetics.

**Fix.** The intent row carries `original_local_receipt_id`; read the original's
`receipt_number` (`getOfflineReceiptById`) and thread it into `extras`.

### I-4 — Offline shift-receipts list labels every refund row as a sale
`apps/pos/src/api/reportApi.ts:598-614` (esp. `:606`, `:613-616`)

`fetchLocalShiftReceipts()` maps every `offline_receipts` row with a hardcoded
`receipt_type: 'sale'` (`:606`) and `payments: [{ ..., amount: receipt.total }]` (`:613-616`).
With v4 refund rows now in the table, the offline shift-receipts screen shows refunds as **sales
with negative totals and negative payments**. This is the operator-visible surface used exactly
when the server projection is not yet available (offline / 404) — i.e. right after a refund.

**Fix.** Map `receipt_type: receipt.receipt_kind === 'refund' ? 'return' : 'sale'`.

### I-5 — The reconciliation modal test mocks the very query that would prove C-2
`apps/pos/src/components/pos/__tests__/RefundPayoutReconciliationModal.test.tsx:38`, `:174-198`

`getRefundIntentsPendingPayoutConfirmation` is `vi.fn()`-mocked with a fixed array, so the
post-dispute `refresh()` returns the same row and the test simply never asserts what the operator
sees next. The test asserts `disputeRefundIntentPayout` was called and the evidence event was
authored — both true — while the prompt-loop defect passes green.

**Fix.** Make the mock stateful (drop the row once `payout_disputed_at` is set) or drive the test
against a real SQLite adapter, and add an explicit assertion that the modal is gone after
"No / Not sure".

---

## MINOR

- **M-1 — errata T7 (explicit scale) not honored in the new Z refund branch.**
  `apps/pos/src/lib/offline/zReportService.ts:848`, `:858-869`, `:881-885` call
  `bcabs(...)`/`bcadd(...)`/`bcsub(...)` with no scale argument, falling back to `lib/decimal`'s
  scale-3 default. §17's errata T7 says *"No new call site in this manifest may rely on the
  scale-3 default."* Harmless today (every input is ≤ scale 3 and the result is `bcformat`-ed at
  `decimals` on exit) and it mirrors the untouched sale branch, but it is a stated-rule miss.
- **M-2 — float formatting on quantity in the cumulative backstop.**
  `apps/pos/src/lib/db/repositories/refundIntentRepository.ts:392`:
  `Math.abs(quantity).toFixed(QUANTITY_SCALE)` on a JS `number`. Bounded by `CartItem.quantity`
  being a `number` today (pre-existing), and `Math.abs` is exact, but `toFixed` is float
  formatting on a quantity (rule 19). Prefer the string path used elsewhere.
- **M-3 — stale docblock now contradicted by the schema.**
  `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:289-290` still says *"Refund/return
  records live in the separate `local_refund_records` table and are deliberately NOT read here."*
  v4 refund rows now DO live in this table. Behaviour is unaffected **only** because
  `apps/pos/src/lib/stock/availability.ts:222` drops non-positive quantities — verified. Fix the
  comment so a future reader does not "restore" symmetry and re-open availability.
- **M-4 — dead refusal copy.** `refundFlow.capabilityUnavailable` was added to both locales but is
  never referenced: the §9.2 dispatch seam **routes** (legacy vs v4) rather than refusing
  (`apps/pos/src/pages/HomePage.tsx:1122-1135`), which is the ruled dual-path behaviour. Either
  wire it to C-5's new refusal or drop it.
- **M-5 — refund rows inflate two counters.** `apps/pos/src/lib/offline/zReportService.ts:392`
  (`transaction_count` per cash-count entry) and `:737` (`operationalEventRange.receipt_count`)
  both count refund rows. Defensible for the chain range at `:737`; misleading at `:392`.
- **M-6 — generic error for structurally-refused originals.** A refund-of-a-refund, or an original
  that is not locally resolvable, both surface as
  `refundFlow.checkout.errorInternal` (`apps/pos/src/stores/refundCheckoutStore.ts:729-736`).
  Fail-closed and correct (verified: a refund row's `offline_receipts.id` has no
  `fiscal_events` row with `source_event_class='offline_receipts'`, so
  `resolveOriginalFiscalEventLocally` returns null) — but the cashier gets no actionable message.
- **M-7 — empty-string fiscal event id in dispute evidence.**
  `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:196` passes
  `prompt.intent.refund_fiscal_event_id ?? ''`. Unreachable (the query requires NOT NULL) but the
  `?? ''` would sign an empty reference if it ever were.
- **M-8 — printed AVOIR sign asymmetry.** The refund's `offline_receipts` row stores negative
  `total`/`lines` and **positive** `payments_json` (correct per §7.2a), so the printed AVOIR shows
  `total −20.00` next to `payment +20.00`
  (`apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts:99-110`). Cosmetic; confirm with the
  owner it matches the legacy AVOIR's appearance.

---

## Verified clean (read, and confirmed correct)

**The recovered FISCAL CRITICAL fix is real and well-proven.**
`apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:209-249` parses
`offline_receipts.canonical_bytes` as the chain **envelope** and reads the signed payload from
`envelope['payload']`. I independently confirmed the envelope shape at
`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:620-637` (`canonicalPayload = { …, payload:
request.payload, … }`). Every one of the five failure modes returns `null` (fail-closed): unparseable
JSON (`:212`), non-object envelope (`:215`), non-object `payload` (`:219`), non-array
`line_items`/`payments` (`:223`, `:228`), non-boolean `training_flag` (`:233`), non-string
`transaction_discount_amount` (`:238`). No permissive default survives.
`resolveOriginalFiscalEventLocally.realAuthoring.test.ts` proves it end-to-end against a **real**
`FiscalEventEngine` seal → real `insertOfflineReceipt` → real resolve → real
`assertOriginalRefundable` throw (6 tests, green).

- **§2 version resolution.** `FiscalEventPayloadRegistry.ts:218-246` — payload-absent ⇒ 3;
  `SALE`/`TRAINING` ⇒ 3; `REFUND` ⇒ 4; `VOID` ⇒ `VoidAuthoringProhibitedError`; missing /
  non-string / unrecognized ⇒ `FiscalEventTypeNotImplementedError`. `readInvoiceTypeCode()`
  (`:163-168`) cannot throw on a null/non-object payload and yields `undefined`, which falls to the
  fail-closed `default`. Byte-compatible with every payload-less call site.
- **§3.1 positive magnitudes.** `RefundReceiptV4Payload.ts:257-313` — `bcabs` on
  `line_total`/`tax_amount`, `Math.abs` on quantity, `unit_price` and `discount_amount` passed
  through unchanged (both already non-negative), delegation to `buildSaleReceiptV3Payload` with
  positive aggregates, `transaction_discount_amount` unconditionally canonical zero,
  `invoice_type_code: 'REFUND'` the only direction marker. The **existing, unmodified**
  `assertSaleReceiptAggregates`/`…V3` invariants run over the normalized values. **No per-line
  TTC-vs-HT assertion was added anywhere** — the only per-line check is the pre-existing gross
  identity `line_total == unit_price × qty − discount` (`SaleReceiptPayload.ts:281-294`), which is
  gross-vs-gross and correct.
- **§3.5/§3.7 refusals fire at the LOOKUP level, before approval authoring.**
  `refundCheckoutStore.ts:742-755` calls `assertOriginalRefundable()` immediately after
  `resolveOriginalFiscalEventLocally()` and before `createOrReuseActiveRefundIntent()` /
  `authorRefundReturnApprovalV3()`, for partial **and** full selections (no carve-out). The builder
  re-asserts the identical function as defense-in-depth (`RefundReceiptV4Payload.ts:226`) — one
  implementation, two call sites, no drift. `bcformatIsNonZero()` (`:346-348`) treats `''`,
  `'-0.000'` and any unparseable value as non-zero ⇒ refuse: fail-closed.
- **Device-side v4 payload validation.** `FiscalEventEngine.ts:1531-1533` (33-key v4 set),
  `:1573-1583` (VOID prohibited + REFUND-only at v4), `:1657-1661`
  (`transaction_discount_amount` must be zero at v4), `:1726-1789` (parallel-array length /
  `product_id` / `quantity` equality + exact 4-key set + `disposition` enum),
  `:1796-1815` (`refund_destination` enum, `settlement_allocation` required-present-and-null),
  `:1700-1702` (single cash leg). All money fields go through non-negative `moneyRegex(scale)`, so
  a negative payment amount cannot be signed.
- **§7.2/§7.2a `offline_receipts` refund row.** `refundReceiptService.ts:192-282` — one
  `withWriteTransaction('fiscal', …)`, **append-first**, then the intent update, then the insert;
  `receipt_kind: 'refund'`; `idempotency_key = refundIntentId`; `id` a distinct fresh UUID;
  `hash_sequence`/`fiscal_hash`/`previous_hash`/`canonical_bytes` all taken from the append result
  (`:234-236`, `:275`) — so the Z's own windowing predicate
  (`zReportService.ts:181-184`) includes it, closing the silent-Z-drop class; negative
  `subtotal`/`tax_amount`/`total`/`discount_amount`; **positive** `payments_json` (§7.2a);
  `transaction_discount_*`, `tendered_amount`, `change_due`, `tolerance_shortfall` all NULL;
  `is_training: 0`; `fiscal_schema_version: 4`.
- **Receipt numbering.** The refund derives its number from `appended.sequence_number`
  (`refundReceiptService.ts:214-218`) — the **same** operational-chain counter a sale uses
  (`receiptService.ts:506-511`), so no duplicate receipt numbers and monotonic ordering.
- **§7.2a status flip.** `syncService.ts:401-425` — `'offline_receipts'` branch unchanged;
  new `'refund_intents'` branch calls `updateReceiptStatusByIdempotencyKey()` (which mirrors the
  `'synced'` column set including `sync_error = NULL`,
  `offlineReceiptRepository.ts:249-254`) **and** advances the intent to `synced`, tolerating a
  repeat `InvalidRefundIntentTransitionError`. `offline_receipts.idempotency_key` is
  `NOT NULL UNIQUE` (`migrations.ts:103`), so the flip cannot touch a second row.
- **No double-projection.** The refund's `offline_receipts` row is never pushed anywhere:
  `getPendingReceiptsForSync` has no live consumer, and `pushOfflineReceipts()` posts fiscal-event
  envelopes only (`syncService.ts:352-370`).
- **§7.3/§7.3a routing.** `zReportService.ts:842-885` — refunds never touch
  `salesCount`/`grossSales`/`netSales`/`taxAmount`; `refundsAmount` accumulates `bcabs(total)`;
  VAT and payment-method breakdowns are **subtracted**. `endOfDayPreview.ts:214-315` — the
  sale-only guard at `:222-226`, the `isRefund`-branched `perMethod`/`cashTenderedSum`
  (`:296-311`) that closes errata 4.2's +2× hazard, and the deliberately unbranched
  additive-over-negative-lines VAT loop (`:262-275`) whose equivalence is stated. The
  **restoration** of the legacy `cashRefundImpact` term (`zReportService.ts:251-254`,
  `endOfDayPreview.ts:408-412`) is correct and non-double-counting: I verified the v4 branch never
  calls `recordRefundSettlementForZ()` (legacy-only, `refundCheckoutStore.ts:606-615`), so the two
  sources are genuinely disjoint. `updateGrandTotals()` receives sale-only gross and
  positive-magnitude refunds (`zReportService.ts:535`).
- **§7.3b net-units ruling.** `productSalesAggregateRepository.ts` unchanged; no `receipt_kind`
  filter, no `ABS()` — negative refund quantities net correctly, per the explicit ruling.
- **SQLite TEXT-timestamp contract.** Every JS-supplied boundary compared against a
  `datetime('now')` column goes through `toSqliteUtc()`: `zReportService.ts:194`,
  `endOfDayPreview.ts:181`, `reportApi.ts:410`, `:556`. No raw `.toISOString()` bound into a
  time comparison anywhere in the diff.
- **§9.1 capability flag.** `setV4RefundAuthoringEnabled()` is a plain guard-independent `UPDATE`
  (`terminalStateRepository.ts:225-235`) called in **both** `pullTerminalState` branches —
  success (`syncService.ts:1390`) and the `FiscalRegressionError` fallback (`:1415`). The reader
  is fail-closed on a missing row, a missing column and a pre-v65 schema
  (`terminalStateRepository.ts:192-209`), and the §9.2 dispatch seam treats a missing terminal id
  as not-enabled (`HomePage.tsx:1122-1124`). Server exposure confirmed at
  `TerminalResource.php:134`.
- **v65 migration.** `migrations.ts:2046-2114` — `refund_intents` with the corrected active
  partial unique index scoped to `('drafted','approval_authored','refund_event_appended')` (i.e.
  `synced` excluded, fold item 7), plus `offline_receipts.receipt_kind` with the `'sale'` default
  (zero behaviour change for every pre-existing row) and the two `terminal_state` columns, each
  `ADD COLUMN` wrapped in `isDuplicateColumnError` tolerance.
- **`approval_scope: 'payout_dispute_evidence'`** is present at both PHP `assertEnum` sites
  (`FiscalPayloadConstraintValidator.php:658`, `:692`) as errata T6 requires, and
  `authorPosOverride()` hard-rejects the scope before appending
  (`posOverrideAuthoring.ts:93-99`) so no bogus paired `OVERRIDE_*` event can be authored.
  `validatePhase4TargetObject()` (`:814-826`) accepts the dispute target shape.
- **Ruled deviations implemented as ruled.** Dual-path store retained with the legacy branch
  byte-intact (`refundCheckoutStore.ts:456-476`, `:499-649`); discriminated v4 settle seam
  (`onV4Settled`, `RefundCheckoutFlow.tsx:113`, `HomePage.tsx:1249-1281`) rather than a fabricated
  `ReturnSettlementResponse`; unconditional `'restock'` disposition with the server's
  `RestockPolicyResolver` as the regulated-item backstop, documented visibly at
  `RefundReceiptV4Payload.ts:46-58` and `refundCheckoutStore.ts:227-234`;
  `authorPayoutDisputeEvidence()` added (`refundApprovalV3.ts:185-244`), idempotent on
  `source_event_id = refundIntentId`.
- **Precision.** No `parseFloat`/`Number(...)`/`(float)` on money anywhere in the diff. The only
  `Math.abs` calls are on `CartItem.quantity`, already a `number` in the pre-existing type
  (`RefundReceiptV4Payload.ts:261`, `refundCheckoutStore.ts:783` via `bcabs(String(...))`,
  `refundIntentRepository.ts:392` — see M-2).
- **Golden-fixture correction (`9e0c5f755`).** `GoldenFixtureBuilder::f16RefundV4Cash()`'s
  `unit_price '10.00' → '12.00'` is correct: `unit_price` on a canonical POS `SALE_RECEIPT` line is
  GROSS/TTC (precision contract), `line_subtotal '10.00'` is net, and no real device could author
  the previous bytes. Canonical string + sha256 regenerated through the same encoder the parity
  test uses.
- **i18n.** en/fr `refundFlow` key sets are exactly equal (programmatic diff: no missing, no
  extra), and every new store error key resolves through
  `RefundCheckoutFlow.tsx:57-63`'s `t(error.key)` under the `pos` namespace.
- **Targeted test run:** 57/57 green across the three highest-risk new suites.

---

## What to fix before merge

C-1 (branch `receipt_kind` in the **signed** X-report), C-2 (exclude disputed intents from the
payout prompt), C-3 (stabilize `approval_id` across a re-authored approval), C-4 (thread the
original's real `business_date`), C-5 (refuse a non-cash-tendered original), C-6 (land or
explicitly defer + surface the Phase-2 acknowledgement endpoint) — then re-run this gate.
