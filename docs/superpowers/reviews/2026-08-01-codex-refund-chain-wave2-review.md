# Lane C Wave 2 — POS-Device v4 Refund-Chain Adversarial Review

- **Commit range:** `ea2487e9b..07408707e`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain`
- **Review date:** 2026-08-01
- **Reviewer:** Codex CLI adversarial review (dual-gate arm 2)

**Verdict: FAIL — the range can sign and report incorrect taxable-refund data, can make refusal decisions from an insufficiently authenticated local mirror, and contains recovery interleavings that can strand or repeatedly prompt durable refund intents.**

## Findings

### CRITICAL

#### C-1 — Original-event refusal checks do not establish that they are reading the resolved signed event

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:171-249`, especially `:175-190` and `:209-241`; diff hunk `@@ -122,20 +124,142 @@`.
- **Category:** Original-receipt resolution / refusal fail-closed behavior.
- **Description:** `resolveOriginalFiscalEventLocally()` proves only that some `fiscal_events` row exists for the source pair, selects only its `id`, and then parses `offline_receipts.canonical_bytes`. It never reads or compares the fiscal row's canonical bytes/hash, and it does not validate the envelope's event type/version/identity or the payload's receipt discriminator and receipt UUID. Its array checks also accept unvalidated element shapes. Consequently, a stale, mismatched, or corrupted receipt mirror with `training_flag: false`, `transaction_discount_amount: "0"`, and two arrays can authorize a refund even though those values were not proved to belong to the referenced signed original fiscal event. This is a permissive trust boundary, even though malformed JSON itself returns `null`.
- **Spec clause:** §3.5 requires refusing based on the **original transaction's** signed non-zero transaction discount; §3.7 requires the training refusal to inspect the original signed payload's `training_flag`; §2 establishes the contract's fail-closed rule for absent/unknown discriminators rather than guessing a supported payload shape.
- **Suggested fix:** Fetch the canonical bytes plus event type/version/identity from the matched `fiscal_events` row and validate that envelope with the canonical fiscal-event validator. Require a recognized original receipt discriminator (`SALE` or the ruled training representation), matching receipt UUID/source identity, matching business date, and fully validated line/payment members. If the receipt mirror remains necessary, require byte/hash equality with the fiscal row; otherwise return `null` and refuse.

#### C-2 — Quantity enters the v4 fiscal and authority paths as an IEEE-754 number

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:257-263` and `:315-328`, new-file hunk `@@ -0,0 +1,348 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/refundReceiptService.ts:45-80`, new-file hunk `@@ -0,0 +1,289 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:355-395`, new-file hunk `@@ -0,0 +1,398 @@`.
- **Category:** Float contamination on money/quantity.
- **Description:** The v4 builder normalizes with `Math.abs(item.quantity)`, the local refund line contract stores `quantity` as a number, and the cumulative-refund authority backstop reconstructs magnitudes with `Math.abs(quantity).toFixed(4)`. `String(...)` or `toFixed(...)` after float arithmetic cannot restore an exact source decimal. This contaminates the signed `line_items` / `original_line_references` equality invariant and the cumulative quantity cap with binary-float rounding.
- **Spec clause:** §3.1 requires canonical positive quantity strings; §3.2 explicitly defines normalization as `positiveQuantity = bcabs(item.quantity)`; §3.3 requires the frozen reference quantity to be a positive decimal string identical to the corresponding signed line quantity; §7.2 requires decimal-string local refund quantities with sign applied by decimal arithmetic.
- **Suggested fix:** Change refund quantity to a decimal string at the cart/refund boundary and retain it as a string through the snapshot, local receipt row, cumulative-cap query, and payload builder. Normalize only with `bcabs` at the quantity scale and format once at the frozen payload scale. Remove `Math.abs`, `Number`, `toFixed`, and number-typed quantity from this path.

#### C-3 — Taxable refund VAT aggregation treats gross `line_total` as net and adds VAT twice

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/refundReceiptService.ts:65-77`, new-file hunk `@@ -0,0 +1,289 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/zReportService.ts:850-865`, hunk `@@ -759,45 +803,95 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/endOfDayPreview.ts:256-273`, hunk `@@ -202,87 +206,127 @@`.
- **Category:** Z-report / end-of-day sign and aggregation.
- **Description:** The refund writer copies the cart's `line_total`, which is the gross/TTC line amount, into the negative local line. Both new reporting branches then call that value `lineNet` and compute `lineGross = lineNet + lineVat`. For a refund whose gross is 12.00 and VAT is 2.00, the signed Z reverses net 12.00 and gross 14.00 instead of net 10.00 and gross 12.00; the end-of-day preview makes the same error additively. The new tests mask the integration bug by constructing refund fixtures whose `line_total` is already net, unlike the actual writer.
- **Spec clause:** §7.2 defines the negative local mirror of the AVOIR; §7.3 requires subtracting each refund line's correct net/VAT/gross from the Z VAT buckets; §7.3a permits additive negative-line arithmetic only when those components are the correct negative net/VAT/gross values.
- **Suggested fix:** Treat local `line_total` as gross/TTC: `gross = abs(line_total)`, `vat = abs(tax_amount)`, and `net = gross - vat` at the currency scale (with the signed equivalent in the preview). Alternatively persist an explicit canonical line net/subtotal and consume it consistently. Add an end-to-end taxable-refund test using the real refund writer output, then assert both Z and preview net/VAT/gross buckets.

### MAJOR

#### M-1 — The signed original reference uses the refund day's business date

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:976-997`, especially `:985` and `:992`; diff hunk `@@ -446,40 +655,396 @@`.
- **Category:** Spec-contract violations in the frozen v4 payload shape.
- **Description:** The store passes `input.approvalContext.businessDate` to both the new refund's `businessDate` and `originalBusinessDate`. The resolver does not return the original fiscal event's business date. A cross-business-day refund therefore signs a false `original_receipt_reference.original_business_date`, breaking the provenance portion of the frozen v4 shape.
- **Spec clause:** §3.2 step 4 requires composing `original_receipt_reference` from the resolved original receipt, and §3.1 makes that non-null original reference one of the two fields carrying refund direction/provenance.
- **Suggested fix:** Resolve and validate the original signed payload/envelope business date, add it to `OriginalFiscalEventLocalView`, and pass that value as `originalBusinessDate`; retain `approvalContext.businessDate` only for the new refund event.

#### M-2 — The local refund receipt stores gross total in the `subtotal` column

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/refundReceiptService.ts:220-233`, especially `:229-232`; new-file hunk `@@ -0,0 +1,289 @@`.
- **Category:** Z-report / end-of-day sign and aggregation.
- **Description:** `subtotal` is assigned `negativeTotal`, while `negativeTotal` is the gross refund total. For any taxed refund, the row therefore records `subtotal === total` instead of the negative net subtotal already available as `payload.subtotal`. This corrupts the required `offline_receipts` accounting shape independently of the per-line VAT error in C-3.
- **Spec clause:** §7.2 and the exact column contract in §7.2a require negative `subtotal`, `tax_amount`, and `total` as their respective signed accounting values, not three aliases of gross total.
- **Suggested fix:** Set `subtotal` to `bcmul(payload.subtotal, '-1', scale)`, keep `tax_amount = -payload.vat_total` and `total = -payload.total`, and add a taxed-refund assertion that `subtotal + tax_amount = total` at the configured scale.

#### M-3 — Reusing an active `refund_event_appended` intent sends it back through non-idempotent state transitions

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:35-41` and `:145-153`, new-file hunk `@@ -0,0 +1,398 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:797-817` and `:932-948`, hunk `@@ -446,40 +655,396 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/refundReceiptService.ts:200-210`, new-file hunk `@@ -0,0 +1,289 @@`.
- **Category:** `refund_intents` races and idempotency.
- **Description:** `refund_event_appended` is deliberately active and `createOrReuseActiveRefundIntent()` can return it, but the store ignores the reused row's state and reopens confirmation. Approval authoring may return its existing source events, after which the rejected `markApprovalAuthored()` transition is swallowed. `createRefundReceipt()` can likewise receive the already-appended fiscal event, but then unconditionally attempts `markRefundEventAppended()` from `approval_authored`; an already-appended row fails that transition and rolls back. The same intent can therefore remain stuck in an error loop rather than entering payout/print recovery.
- **Spec clause:** §4.4 keeps `refund_event_appended` in the active uniqueness set only as an in-flight duplicate guard; §4.5 requires a post-append intent to proceed through payout confirmation and print/reprint recovery without changing the already-signed fiscal effect.
- **Suggested fix:** Branch on the reused intent state. For `refund_event_appended`, verify its linked event and local receipt and route directly to reconciliation/reprint recovery. For `approval_authored`, recover the existing approval references and resume only the append step. Make each resume path explicitly idempotent and reject inconsistent linkages fail closed.

#### M-4 — Server ACK is followed by three non-atomic local status flips, and all guarded transition errors are swallowed

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/sync/syncService.ts:395-434`; diff hunk `@@ -380,20 +393,51 @@`.
- **Category:** `refund_intents` races and idempotency.
- **Description:** On an accepted refund event, the code independently marks the fiscal event synced, locates and marks the receipt by `idempotency_key = intent id`, and then marks the intent synced. A crash or SQLite failure after the first write removes the fiscal event from the pending queue while leaving the receipt pending and/or the intent active forever. In addition, the catch at `:427-433` suppresses every `InvalidRefundIntentTransitionError`, although the comment says only an already-`synced` row is benign; a missing row or wrong state is silently accepted. The receipt update's affected-row count is also not checked here.
- **Spec clause:** §7.2a requires the successful refund mirror flip to be keyed by `offline_receipts.idempotency_key = refund_intents.id`; §4.4 requires the intent to reach terminal `synced` so it leaves the active uniqueness set.
- **Suggested fix:** After the server ACK, apply all three local flips inside one SQLite write-gate transaction and require exactly one matching receipt and intent. If a transition reports an invalid state, re-read it and suppress only a row already in `synced`; propagate missing or inconsistent states. Add crash-injection tests after each write boundary.

#### M-5 — Payout confirmation and dispute are not mutually exclusive, and a disputed intent remains permanently “pending confirmation”

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:264-325`, especially `:267-287` and `:304-324`; new-file hunk `@@ -0,0 +1,398 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:152-217`, new-file hunk `@@ -0,0 +1,327 @@`.
- **Category:** `refund_intents` races and idempotency.
- **Description:** The “Yes” and “No / Not sure” updates guard only on a non-null fiscal-event ID, do not exclude the opposite timestamp, and do not verify that a row changed. Concurrent or repeated actions can set both timestamps. More directly, `getRefundIntentsPendingPayoutConfirmation()` checks only `payout_confirmed_at IS NULL`, so after the dispute handler sets `payout_disputed_at`, `refresh()` immediately selects the same row and prompts for confirmation again. A disputed crash-before-print row is also absent from the reprint query because that query requires a confirmation timestamp.
- **Spec clause:** §4.5 defines confirmation and dispute as mutually exclusive reconciliation branches, while retaining the signed fiscal/Z effect, and requires payout-resolved-but-unprinted intents to enter print recovery.
- **Suggested fix:** Resolve payout with one guarded transition that sets exactly one of `payout_confirmed_at` or `payout_disputed_at`, asserts one affected row, and rejects an already oppositely resolved row. Define pending confirmation as both timestamps null, and define pending reprint as either resolution timestamp non-null while `printed_at` is null.

#### M-6 — Dispute evidence is best-effort after the durable dispute marker, so the mandated signed record can be lost permanently

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:170-213`, especially `:179-205`; new-file hunk `@@ -0,0 +1,327 @@`.
- **Category:** `refund_intents` races and idempotency.
- **Description:** The modal first persists `payout_disputed_at`, then attempts `authorPayoutDisputeEvidence()` inside a catch-and-log block. A crash or authoring failure after the marker leaves no durable “evidence pending” state and no retry path. Thus setting the dispute flag does not reliably trigger the signed, syncable evidence event required by the ruling. The existence and manifest authorization of `authorPayoutDisputeEvidence()` are correct; the durability of its invocation is not.
- **Spec clause:** §4.5 states that setting `payout_disputed_at` triggers a new signed, server-side evidence record while leaving the original fiscal event and Z effect unchanged.
- **Suggested fix:** Make evidence authoring part of a durable, idempotent workflow keyed by the intent ID: either append the evidence before marking the dispute resolved in the same write gate, or persist an `evidence_pending` outbox/state and retry until the source event is found. Do not clear the pending condition merely because the best-effort call was attempted.

### MINOR

None.

### NIT

None.

### Unscored Review Question

#### Q-1 — Is fail-open parsing of the device-local cumulative-quantity backstop an additional authorized ruling?

- **File:line / diff hunk:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:343-395`, especially `:349-353` and `:369-392`, new-file hunk `@@ -0,0 +1,398 @@`; `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:767-794`, hunk `@@ -446,40 +655,396 @@`.
- **Category:** Original-receipt resolution / refusal fail-closed behavior.
- **Question:** The repository deliberately skips malformed JSON, malformed entries, and non-number quantities and then lets the refund proceed using the lower partial total. The store describes this local cumulative refusal as “orchestrator-ruled (required),” but that ruling is not one of the four authorized deviations supplied for this review, while binding §4.4 says the server-side cumulative cap is the sole authority and the device-local mechanism's job is only duplicate in-flight prevention. Is this additional backstop authorized outside the binding document? If it is required to prevent pre-sync payout of an over-refund, should any malformed prior appended snapshot refuse fail closed rather than silently undercount? If it is merely advisory, its policy role and tolerance should be made explicit in the binding spec. This question is not scored in the severity totals.

### Hunt-Category Coverage

| Hunt category | Result |
|---|---|
| 1. Frozen v4 payload: positive magnitudes, frozen keys, unknown discriminators | Reviewed registry dispatch, v4 construction, and exact-key validation. The registry's unknown/missing discriminator paths are fail closed and the frozen key sets are enforced; M-1 and C-2 cover the provenance and positive-quantity contract defects found. |
| 2. Original-receipt resolution and refusal checks | C-1. Discount/training values have type checks, but their bytes are not proved to be the resolved signed original event. |
| 3. `refund_intents` state machine and idempotency | M-3 through M-6. Approval/append recovery, ACK status flips, payout resolution, print recovery, and evidence durability were all traced. |
| 4. Z-report / end-of-day sign and aggregation | C-3 and M-2. Refund count/amount and payment subtraction use positive magnitudes correctly; taxable subtotal and VAT component math do not. |
| 5. Float contamination | C-2. Money calculations in the reviewed additions otherwise use decimal-string helpers; quantity does not remain decimal-string end to end. |
| 6. Envelope-vs-payload confusion | No sibling confusion found in the changed production paths: `resolveOriginalFiscalEventLocally()` now explicitly descends through `envelope.payload`, and the reviewed Z-session authoring parser also reads its nested payload. C-1 is a source-authentication/discriminator gap, not an envelope-level field lookup regression. |

**Verification note:** `git diff --check ea2487e9b..07408707e` completed cleanly. A targeted Vitest run could not start because the read-only review worktree prevented Vite from creating `apps/pos/node_modules/.vite-temp/vitest.config.ts.timestamp-*.mjs` (`EPERM`), so no tests executed. Final status inspection showed an unrelated modification at `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php`; this review did not touch it and left it unchanged. The binding document has no standalone `§4.6` heading in this revision; the `§4.6` callouts in code were evaluated through §4.4's documented `synced` terminal-state and active-index rules.

## Authorized Deviations — Verified As Implemented

1. **Dual-path store retention for non-acknowledged terminals with byte-intact legacy path — verified.** `apps/pos/src/pages/HomePage.tsx:1092-1135` reads and passes the local capability flag; `apps/pos/src/stores/refundCheckoutStore.ts:436-476` selects v4 only when acknowledged and retains the legacy settlement branch at `:499-648`. The legacy Z/EOD contribution remains alongside the v4 receipt path in `apps/pos/src/lib/offline/zReportService.ts:197-216` and `apps/pos/src/lib/offline/endOfDayPreview.ts:410-416`.

2. **Discriminated v4 settle-seam callback to `RefundPayoutReconciliationModal` — verified.** `apps/pos/src/components/pos/RefundCheckoutFlow.tsx:35-53`, `:68-74`, and `:104-116` separate legacy `onSettled` from v4 `onV4Settled`; `apps/pos/src/stores/refundCheckoutStore.ts:648` and `:1028` invoke the corresponding seam; `apps/pos/src/pages/HomePage.tsx:1251-1283` and `:1936-1943` route the v4 epoch to the reconciliation modal.

3. **Restock default disposition — verified.** `apps/pos/src/stores/refundCheckoutStore.ts:236-248` stamps `disposition: 'restock'` onto every v4 refund line rather than exposing an unresolved or permissive selection.

4. **`authorPayoutDisputeEvidence` manifest addition — verified.** `apps/pos/src/lib/fiscal/refundApprovalV3.ts:142-243` authors the dedicated signed `OPERATOR_APPROVAL_GRANTED` evidence event with an intent-keyed source identity; `apps/pos/src/lib/fiscal/posOverrideAuthoring.ts:7-23` and `:94-105` add the dedicated scope while keeping it outside the paired override helper; the modal invokes it at `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:185-199`. M-6 concerns durable triggering, not the authorized manifest/scope addition itself.

## Summary Counts by Severity

| Severity | Count |
|---|---:|
| CRITICAL | 3 |
| MAJOR | 6 |
| MINOR | 0 |
| NIT | 0 |
| **Total** | **9** |
