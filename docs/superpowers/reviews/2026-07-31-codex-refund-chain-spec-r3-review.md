# Adversarial Review — Lane C v3 Refund-Chain Integration Spec, Revision 3

**Review date:** 2026-07-31  
**Review target:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md`  
**Canonical revision:** `e93f9378cb922fe0cd8edbb4667468d7c0c80694` on `feat/v3-refund-chain`  
**Checkout reviewed:** `dev` at `35bec87401d549082594a536776462766a38dcde`  
**Prior records:** `2026-07-31-codex-refund-chain-spec-r2-review.md` and `2026-07-31-lane-c-spec-r2-fiscal-treasury-reviews.md`

## Verdict: REJECT

Revision 3 is materially better and the scope reduction is mostly real: cash is the only reachable refund destination, `settlement_allocation` is null, a refund has exactly one cash payment leg, and VOID is fail-closed at authoring and ingress. The payload-aware V4 registry direction, append-first local ordering, deterministic approval UUID injection, per-row seal discriminator, device-side expected-cash calculation, and explicit v2-original limitation are all substantial advances. The spec nevertheless still contains genuine open design decisions rather than only editorial residue. In particular, the specified full-refund transaction-discount math is incompatible with the current receipt invariants; the server write-off flow depends on device-local state that the server cannot observe and has no idempotent compensation record; the seal-algorithm backfill is forbidden by the current fiscal-row immutability trigger; and the proposed offline capability gate cannot atomically make a stale device capable before the server blocks its legacy path. Those boundaries must be resolved before a code plan can safely be frozen.

## 1. Baseline and review method

I read the complete revision-3 spec, both required round-2 review records, and the relevant files in the current merged checkout. The local spec is byte-identical to the canonical revision stated above (SHA-256 `9a9f43a7a7526f2be98fee0eb638c97b75ad308b0a39884ac7bafa267d878ec2`). The checkout contains the merged first-wave Lane A, B, D2-a, D1, and F commits between the spec's old `711f3d79f` baseline and current `35bec8740`.

The review distinguishes three outcomes for each prior finding:

- **Closed:** revision 3 supplies an executable ruling for the narrowed slice.
- **Mooted:** the narrowed schema and boundary checks make every formerly problematic path unreachable.
- **Persists:** a reachable path remains unspecified, contradictory, or incompatible with current code.

## 2. Fold-completeness for the eleven round-2 required rework items

| R2 item | R3 status | Grounded assessment |
|---|---|---|
| 1. Make V4 authoring executable | **Closed, subject to item 2's schema defects** | Sections 3.1 and 17 now name a payload-aware `eventVersionFor(type, payload?)`, the engine call-site threading, exact SALE/TRAINING/REFUND/VOID behavior, and registry/engine/drift tests. Current code is indeed type-only in `FiscalEventPayloadRegistry.ts:162-174`, calls it without payload in `FiscalEventEngine.ts:569-571`, and still builds a fixed V3 key set in `FiscalEventEngine.ts:1082-1113`, so the manifest targets the right authoring seam. |
| 2. Freeze the exact V4 schema and invariants | **Persists** | The three new top-level keys are fixed, but the return-line object key set and enum are not. More importantly, the spec does not reconcile the negative values created by `hydrateFromReceipt.ts:55-83` with the current positive canonical refund fixture and the server's non-negative-money constraints. Its transaction-discount ruling is mathematically inconsistent with the current separate transaction-discount model. See section 4.1. |
| 3. Make approval IDs deterministic and verifiable | **Partly closed; one execution gap persists** | Optional caller-supplied approval/override source UUIDs are source-compatible with existing callers and provide the required recovery keys. The projector-side verification is specified. However, the new exception is called non-retryable while `ApplyFiscalEventProjectionJob.php:392-401` catches and retries every `Throwable`; neither that job nor a non-retry regression test is in the manifest. |
| 4. Make intent ordering and lifecycle crash-safe | **Partly closed** | Append-first within the same SQLite write transaction and stop-at-`synced` are now explicit; payout reconciliation is described. The partial unique index treats `synced` as active because it excludes only nonexistent/terminal names including `applied`, so it prevents a second legitimate identical partial return. Print recovery is still only a nullable timestamp, not a recovery transition or startup flow. See section 4.3. |
| 5. Complete the Model-1 rejection matrix and compensation | **Persists** | The matrix and `RefundWriteOff` journal direction are present, but the proposed server action depends on a device-local `refund_intents.payout_confirmed_at`, cannot address ingress quarantine rows through a dead-lettered-projection ID, and has no persistent idempotency/provenance contract. Permission seeding, account provisioning, posting, and the no-receipt cash-drawer adjustment are also absent. See section 4.4. |
| 6. Resolve voucher and VOID contradictions | **Mooted/closed for launch scope** | The V4 schema fixes `refund_destination='cash'`, `settlement_allocation=null`, and one cash payment leg. The refund authoring path does not call voucher redemption. `original_payment` and voucher destinations therefore have no reachable launch path. VOID throws at payload/version authoring and is rejected at both ingress boundaries, with negative tests. Deferral to non-normative section 16 is genuine for these classes. |
| 7. State server policy semantics precisely | **Persists** | Section 4 calls the return window, daily cap, manager threshold, and disposition device-enforced with signed evidence, but V4 contains no signed policy snapshot/evidence fields and the manifest contains no device policy-cache or enforcement work. The server-side accept-and-flag half does not establish the asserted real-time device gate. |
| 8. Repair sealed-receipt verification per row | **Design direction closed; executable migration persists** | The per-row discriminator and one-pass previous-hash walk are correctly specified. The backfill cannot execute against the current `prevent_receipt_modification()` trigger in `2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:43-65`, and section 10.4 simultaneously forbids modifying any existing `pos_receipts` row. See section 4.5. |
| 9. Make rollout capability gating operational | **Persists** | A server field and local cache field are named, but the manifest omits the local terminal-state repository and actual checkout dispatch seam. There is no enablement command or acknowledgement protocol, and the unconditional server guard can become active before an offline/stale device has pulled the flag. Single-terminal and v3-original assumptions are not enforced or preflighted. See section 4.7. |
| 10. Provide an exact code-phase manifest | **Persists** | Section 17 is much more concrete, but it still has missing production seams/tests, incorrect new-vs-existing labels, unnamed API migrations, an avoidable “or next free” migration placeholder, an out-of-scope no-change entry, and stale citations. See section 5. |
| 11. Repair wording and source citations | **Partly closed; bounded residue persists** | The principal scope and boundary language is clearer. D1 line citations are stale after promotion, two ticket paths are wrong/nonexistent, and the statement that tenant #1 originals are “always cash” is not established by the repository. These are bounded fixes, but they accompany the design failures above. |

## 3. Scope-moot audit

The main scope reductions are real where the schema and boundary rules enforce them:

1. **Voucher refund destination is unreachable.** Sections 3.2 and 3.4 require `refund_destination='cash'`, `settlement_allocation=null`, and exactly one CASH payment leg. Section 10.6 also guards voucher redemption for REFUND. Nothing in the launch manifest authors a voucher refund.
2. **`original_payment` allocation is unreachable.** The allocation field is fixed to null, not merely ignored by the projector.
3. **VOID is unreachable through both supported authoring and ingress.** The registry throws before authoring, while the current and legacy endpoints reject it. This is fail-closed rather than deferred validation.
4. **Cross-terminal refund execution is not yet proven unreachable.** The product scope says single terminal, but merged D1 still exposes multiple terminal-creation paths in `TerminalController.php`; the spec has no enablement preflight or provisioning lock. This is therefore an unimplemented rollout precondition, not a safely mooted class.
5. **v2 originals are explicitly unsupported, not technically impossible.** D1 makes new DEVICE terminals v3, but preserves web terminals at v2 and does not rewrite existing terminal rows. The accepted limitation in section 9.4 is clear, but repository state alone cannot prove that tenant #1 has no v2 originals.

## 4. Adversarial findings in the remaining launch slice

### 4.1 V4 schema and refund arithmetic are not yet implementable

The payload-aware version selection is now good: a REFUND with the three identifying keys selects V4, SALE/TRAINING remain V3, and VOID fails. The remaining problem is what the V4 payload means.

First, section 3.1 does not freeze the exact return-line object. It constrains alignment and equality, then later mentions `disposition`, but never states the only allowed keys, their types, or the disposition enum. A fiscal schema described as exact must spell out the object shape rather than leave it to implementation inference.

Second, the builder instruction to compose through `buildSaleReceiptV3Payload` omits a required normalization step. `hydrateFromReceipt.ts:55-83` creates a return cart with negative quantity, line total, and tax. By contrast, the current F-09 canonical refund fixture uses positive magnitudes, `SaleReceiptPayload.ts:262-294` still requires line total to equal unit price times quantity less discount, and `FiscalPayloadConstraintValidator.php:822-825` rejects negative monetary fields. Passing the hydrated cart directly into the V3 builder cannot satisfy those constraints. The spec must rule that refund authoring converts return quantities, line totals, tax, and relevant aggregates to positive refund magnitudes before delegating, and test that exact path.

Third, the full-receipt transaction-discount exception is wrong. The spec permits a full refund of a transaction-discounted receipt only if the refund emits `transaction_discount_amount=0`, asserting that per-line discounts already account for the transaction discount. Current persistence contradicts that premise: `offlineReceiptRepository.ts:37-47` stores transaction discount separately from the lines; `receiptService.ts:523-558` persists per-line discount and transaction discount independently; and `SaleReceiptPayload.ts:121-147,193-201` enforces `subtotal + VAT = total + transaction_discount_amount`. Zeroing a nonzero original transaction discount either over-refunds the customer or violates the aggregate identity. The correct frozen ruling for this slice is: partial refunds of transaction-discount receipts remain refused, while an exact full-receipt refund preserves the original positive `transaction_discount_amount` and reason after magnitude normalization.

### 4.2 Deterministic approval IDs are compatible, but “non-retryable” is not enacted

Adding an optional `{ approval, override }` source-ID parameter to `posOverrideAuthoring` is backward-compatible with the current payment and discount callers: omitted IDs retain generated behavior, while refund authoring can create and persist two UUIDs before append. This closes the round-2 recovery-key concern, and the manifest includes the authoring implementation and test.

The verification failure path remains incomplete. Revision 3 defines `ApprovalEvidenceUnresolvedException` as non-retryable, but the current projection job retries any thrown `Throwable` (`ApplyFiscalEventProjectionJob.php:392-401`) and only dead-letters in `failed()` after exhaustion (`ApplyFiscalEventProjectionJob.php:413-470`). The manifest does not modify the job or add a regression proving immediate terminal handling. Naming an exception non-retryable does not make it so; the job's classification contract must be changed and tested.

### 4.3 The intent ledger has an active-index error and no print recovery contract

Append-first is now explicit and correctly ordered: the fiscal append yields the event ID, then the same SQLite write transaction records `refund_event_id` and advances the intent. Stopping the local state machine at `synced` also respects the device/server boundary.

The proposed active-intent index is inconsistent with that lifecycle. It excludes `applied`, `dead_lettered_local`, and `abandoned`, although `applied` is deliberately no longer a state and `synced` is the local terminal success state. Consequently a synced partial refund remains permanently active. If an original line has quantity two, refunding one unit twice creates the same original-ID/line-snapshot fingerprint and the second valid refund reuses or collides with the first. Revision 3 must exclude `synced` from the active index and separately define cumulative returned-quantity enforcement/idempotency, or introduce an occurrence/remaining-quantity dimension that distinguishes two valid identical partial returns.

Payout recovery is credible: an appended intent without payout confirmation produces an explicit operator reconcile/dispute decision. Printing is not equally specified. `printed_at` exists, but there is no post-payout transition, startup query, reprint behavior, or UI recovery for payout-confirmed but unprinted refunds. That leaves the round-2 payout/print requirement only half closed.

### 4.4 The Model-1 compensation crosses an unavailable boundary

The remaining rejection matrix correctly distinguishes pre-money, post-money, and infrastructure classes, and `SystemAccountPurpose::RefundWriteOff` gives the accounting intent a name. The proposed implementation cannot currently determine or apply that compensation safely:

1. `refund_intents` is a new SQLite device table. No server table, sync payload, or server-owned payout-confirmation record is defined. The server dead-letter action therefore cannot read `payout_confirmed_at` or derive a shift from that table as sections 5.2 and 5.3 require. A signed refund payload can supply `shift_id`, but payout confirmation needs an explicit, authenticated server input or a synced server record.
2. An ingress-quarantined event does not necessarily have a `fiscal_event_projection_failures` row or projection ID. Routing all post-money quarantine compensation through `/fiscal/dead-lettered-projections/{id}/write-off` leaves that matrix row without a target.
3. The operation has no durable compensation record or unique idempotency key tying one write-off to one rejected fiscal event. Repeating the POST can otherwise duplicate the Dr Refund Write-Off Expense / Cr Cash entry and the drawer adjustment.
4. The spec does not require that the journal be posted, only that it be created, and does not freeze atomicity between the journal, compensation record, and drawer adjustment.
5. Current `CashDrawerService::recordPayout()` requires a separate cash-drawer-control approval, while `recordRefund()` expects a receipt. The manifest names neither an exact no-receipt adjustment operation nor the service file/test that would implement it.
6. The new `fiscal.refunds.manage_dead_letters` permission is absent from `RolesAndPermissionsSeeder.php`, which is absent from the manifest. No real role is granted the proposed route.
7. Adding `RefundWriteOff` to the existing `SystemAccountPurpose` enum also requires updating its exhaustive `label()` and `expectedAccountType()` matches and provisioning or explicitly configuring an account for tenant #1. The rollout does not preflight that account.

These are not optional implementation details. Revision 3 must choose a server-observable payout-evidence boundary and define one idempotent, auditable compensation aggregate that covers projection dead letters and ingress quarantine. It must also state the exact posting and drawer behavior.

### 4.5 The per-row seal repair conflicts with the current immutability trigger

The new discriminator is the right verification model: store `sealed_hash_algorithm` per receipt, backfill deterministically, recompute the candidate row with its stored algorithm, and advance the previous-hash pointer only once. The proposed command cannot update existing fiscalized rows under the current PostgreSQL trigger. `prevent_receipt_modification()` in `2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:43-65` rejects ordinary updates to a fiscalized `pos_receipts` row. Section 10.4 then independently says no existing `pos_receipts` row may be backfilled, contradicting the discriminator backfill in section 6.

The spec must freeze a migration that changes the trigger to allow exactly one metadata-only transition from null to `legacy_pipe_v1` or `canonical_v3`, with all other columns unchanged, while continuing to reject every other fiscal-row mutation. It also needs a PostgreSQL trigger regression test and should narrow the no-rewrite rule to permit only that discriminator transition. Depending on the chosen write path, the `Receipt` model must also expose/cast the new fields; it is currently omitted from the manifest.

### 4.6 Device expected cash is now on the correct path

This round-2 item is closed in design. Revision 3 correctly moves the calculation to the device's `zReportService`, defines positive `refund_total` and negative `cash_impact`, and rewires both report generation and end-of-day preview from `local_refund_records` to synced refund intents. Extending the Lane B cash-rounding test is consistent with the merged checkout. The unrelated broader `CashDrawerService` expected-cash issue remains properly outside this slice, although its ticket citation must be corrected as noted in section 5.

### 4.7 Offline rollout is still not atomic or fully manifested

Persisting a server capability on the terminal and caching it locally is necessary, but it does not provide the atomicity claimed in section 9:

1. `terminalStateRepository.ts` owns the local terminal-state type, selects, and upsert SQL, yet is absent from section 17. Adding a migration and parsing the server response in `syncService.ts` cannot persist/read the capability without changing that repository and its tests.
2. The spec says the local `classifyCartForCheckout` entry point checks the capability. Current classification in `cartClassification.ts:21-67` is a pure cart classifier; actual dispatch occurs in `HomePage.tsx:1265-1330`. Neither the actual gating seam nor its regression test is listed in the manifest.
3. No exact command or operator action turns the server capability on. More importantly, an unconditional server-side legacy guard can be deployed/enabled before an offline or stale device successfully pulls the true capability. “Same deployment action” cannot eliminate asynchronous propagation. Revision 3 needs a two-phase enablement/acknowledgement protocol, or a server guard conditioned on an acknowledged device capability, with offline tests for both sides of the transition.
4. The single-terminal premise is not enforced by merged D1. `TerminalController.php` still permits terminal creation through multiple endpoints. Enablement must at least refuse when more than one active physical terminal exists, and the launch tenant must be protected against provisioning another terminal while the narrow capability is active (or define how that automatically disables/refuses refund authoring).
5. D1 guarantees v3 for newly created DEVICE terminals, but keeps web terminals at v2 and does not rewrite existing rows. Since tenant data is not verifiable from this repository, the enablement preflight must prove exactly one active DEVICE terminal and prove that eligible originals are v3-from-birth/no-v2, rather than asserting those facts in prose.

### 4.8 Section 9.4's accepted v2-original limitation

Section 9.4 now clearly states that a v3+ terminal cannot refund a v2 original through this launch chain and that such cases require a manual off-system correction. That is an explicit accepted limitation, not a hidden fallback, and is adequate as a product ruling if rollout preflight makes the staging premise true. I could not verify the asserted tenant #1 receipt population or “eight staging receipts” from repository files, so those facts must remain deployment checks, not repository-grounded claims.

## 5. Section 17 manifest and merged-checkout exactness

### 5.1 First-wave changes

- **D1 / TerminalController:** the cited `current_sequence` assignments have moved. In current `TerminalController.php`, relevant assignments are at lines 120, 400, and 465; cited lines 395 and 458 do not identify them. D1 also demonstrates why the rollout must distinguish DEVICE and web terminal paths.
- **Lane B tests:** `apps/pos/src/services/__tests__/zReportService.cashRounding.test.ts` exists and was edited by the merged first wave. Revision 3 correctly calls for extending it rather than creating a parallel test.
- **Lane F fixtures:** F-16 is genuinely the next free fixture number in this checkout. The golden directories are F-01 through F-14 plus F-15-large; `GoldenFixtureBuilder.php:31-44` registers F-01 through F-14, and `FiscalPayloadConstraintValidatorTest.php:1715-1722` expects 14. Registering F-16 as the fifteenth builder fixture is therefore consistent. F-10 remains the VOID fixture. The manifest should use the full path `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php`.

### 5.2 Exact manifest repairs required

Section 17 is not yet an exact implementation manifest:

- Name exact API migration files instead of three generic migration descriptions.
- `apps/pos/src/lib/db/__tests__/migrations.v65.test.ts` is the exact next device migration test here; remove “or next free migration test.”
- Mark `apps/pos/src/lib/operatorApproval/__tests__/posOverrideAuthoring.test.ts` as a **new** file; it does not exist.
- Mark `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` as an **existing modified** file, not a new file.
- Add the projection job and tests required to make approval-evidence failures immediately non-retryable.
- Add the `Receipt` and `Terminal` model changes/casts required by the new stored columns.
- Add the immutability-trigger migration and PostgreSQL trigger regression test required for the seal discriminator backfill.
- Add the permission seeder/test, account-purpose exhaustive-match and tenant account configuration/preflight tests, durable write-off/compensation persistence and idempotency test, exact cash-drawer adjustment implementation/test, posting/atomicity test, and ingress-quarantine compensation target.
- Add `terminalStateRepository.ts` and its tests, the real checkout dispatch/gating seam and test, and the exact capability enable/acknowledgement command or endpoint and transition tests.
- Remove `refundSettlementService.ts` from the manifest if no change is made. A retained, uninvoked manual-workaround file is not an implementation task and conflicts with the manifest's no-change-free rule.
- Replace the nonexistent training-gate ticket citation with the merged ticket `docs/superpowers/tickets/2026-07-31-treasury-bridge-training-money-legs.md`. The named cash-drawer expected-cash ticket also does not exist in this checkout; cite its actual path if created elsewhere or remove the citation.
- Replace the claim that tenant #1 originals are “always cash.” The merged launch-contract test proves an active CASH tender exists, not that every eligible original used cash.

## 6. Required rework before code planning

1. **Freeze correct V4 refund arithmetic.** Specify the exact return-line object key set/types/disposition enum; require positive-magnitude normalization before the V3 builder; preserve the original positive transaction discount and reason for an exact full-receipt refund; keep partial refunds of transaction-discount receipts refused; add builder, registry, drift, and PHP symmetry tests for these cases.
2. **Either provide signed policy evidence or narrow the policy claim.** Define the exact signed window/cap/manager/disposition snapshot, device cache/enforcement files, and tests, or explicitly rule those controls server-advisory for this launch and remove the claim that the device proves them.
3. **Finish the local intent lifecycle.** Make `synced` inactive in uniqueness, define cumulative-quantity/idempotency behavior for two valid identical partial returns, and add an explicit payout-confirmed/unprinted startup recovery and reprint flow with tests.
4. **Redesign Model-1 compensation at the server boundary.** Choose server-observable authenticated payout evidence; give ingress quarantine an addressable compensation target; persist one unique compensation record per rejected event; atomically post the journal and defined drawer adjustment; classify the approval failure as immediately non-retryable; seed/grant the permission; complete `RefundWriteOff` enum/account provisioning; and test replay idempotency, authorization, posting, and both projection/quarantine sources.
5. **Make the seal backfill legal.** Add an exact migration that permits only a one-time discriminator-only update on an otherwise immutable fiscal row, revise section 10.4 accordingly, include model changes, and add PostgreSQL trigger/backfill/mixed-chain tests.
6. **Replace rollout simultaneity with an executable protocol.** Manifest the local repository and real checkout gate; define the server enable action plus device acknowledgement/two-phase guard; preflight and enforce the one-active-DEVICE-terminal/v3-original/account prerequisites; and test offline/stale-device behavior during every transition.
7. **Make section 17 and citations mechanically exact.** Apply every path/new-vs-existing/migration/test correction in section 5.2, use the current D1 line citations and full fixture-builder path, retain F-16 as the verified next number, and remove unverifiable tenant facts from normative reasoning.

These seven changes include genuine boundary choices in items 1, 4, 5, and 6. They cannot be safely converted into code-phase assumptions, so this review cannot be reduced to APPROVE-WITH-FIXES.
