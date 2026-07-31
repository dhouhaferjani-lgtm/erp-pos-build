# Adversarial review — v3 refund/void chain integration spec

**Target:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md`  
**Revision checked:** `711f3d79f8103f199fb4457745577b8f0795c07c`  
**Review type:** pre-implementation design/specification review, not a code-diff review  
**Verdict:** **REJECT**

The proposed top-level direction is correct: a v3 refund must be authored as an immutable correction on the device's authoritative operational `fiscal_events` chain, rather than sealed beside that chain through `ReceiptFinalizationService`. The spec is nevertheless not implementable or launch-safe as written. Its description of today's first-return failure is materially wrong; “the server side already exists” omits the policy, line-identity, disposition, voucher, original-payment, VOID reporting, and reconciliation semantics that the current projector does not implement; the advertised fully-offline path contradicts the unchanged approval flow; S0–S5 omit several irreversible states; E1 work that the spec calls new already exists under a different frozen source-type contract; and the “exact” manifest is neither exact nor sufficient. The baseline Critical finding is therefore recognized but not resolved.

## 1. Lane C contract audit

Lane C in `docs/handoff/DISPATCH-PLAN-v3-first-tenant-2026-07-31.md:76-122`, incorporated by v4 at `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md:58-68`, requires six items and forbids deferral of the four round-2 decision areas. The spec has headings corresponding to the six items, but substantive compliance is as follows.

| Required contract item | Result | Review |
|---|---|---|
| 1. Precise current failure mode and v3 sale → return → next sale → Z test shape | **Fail** | The two-counter diagnosis is directionally right, but the claimed `0,1,2…` correction sequence, first-return orphan, inevitable later unique collision, uncaught 500, and inclusion of VOID in the legacy counter are wrong. The proposed verifier invocation also omits required `--tenant` and `--actor-id` arguments. See §2. |
| 2. Integration author and immutable payload choice | **Fail** | Device authoring on `SALE_RECEIPT` + `invoice_type_code` is the right axis. The claimed device-only gap is false: the current shape cannot carry original-line identity, return disposition, or refund destination, and current server projections do the wrong thing for voucher issuance, original-payment proration, policy enforcement, and VOID reporting. See §§3 and 6. |
| 3. Four mandatory decision areas | **Fail** | All four have headings, but none is closed adequately: offline contradicts unchanged force-sync approval; atomicity leaves irreversible payout/dead-letter states; VOID offers mutually exclusive launch choices and is misclassified by current reports; legacy/cross-terminal lookup is under-specified and its retry claim is false. See §§4–7. |
| 4. E1 on top, including mixed tender and Z | **Fail** | The E1 principles are repeated correctly, but the proposed mixed-tender algorithm contradicts `isCashOnlyTender()`, the device has no production proration input, refund rounding GL already exists under `pos_cash_rounding_refund`, and the Z production path is absent from the manifest. See §8. |
| 5. Constraints | **Partial / fail overall** | The `hydrateFromReceipt` and current AVOIR observations are correct. Quantity capping cannot be preserved by “reusing” the legacy methods because canonical refund lines have positive quantities and no `original_line_id`. See §3. |
| 6. Frozen exact code-phase manifest | **Fail** | It contains unnamed files, “e.g.” paths, alternatives, conditional scope, and a file explicitly marked “no changes”; it omits required production paths and includes already-complete E1 work. Its untouched-payload claim is incompatible with the design's own correctness requirements. See §9. |

The four mandatory decision areas from Lane C item 3 are explicitly not closed:

1. **Offline/unsynced:** §3a says same-device refunds are “fully offline” (`spec:324-330`), while §2.2 and §6 keep approval phase 1 unchanged (`spec:233-243,631-633`). Current phase 1 requires server receipt and line IDs and force-syncs both approval events (`apps/pos/src/lib/refundFlow/refundApproval.ts:11-30,40-50,114-121`).
2. **Atomicity/failure ordering:** §3b enumerates S0–S5, but does not define physical payout authority, durable intent/idempotency, crash recovery after append, partial multi-projector outcomes, or compensation for an immutable event that server business logic rejects.
3. **VOID:** §3c first rules full integration mandatory (`spec:365-402`), then offers a launch fallback that retains the legacy mutation (`spec:404-413`), while §2.4 mandates a v3 guard. That is not one ruling. Current projections and reports also do require VOID-specific work.
4. **Mixed-v2/cross-terminal:** the v2 prohibition is reasonable, but local absence cannot distinguish v2, cross-terminal v3, and pruned local v3. The lookup response is insufficient, and a dead-letter does not automatically recover.

Lane C item 4's two additional mandatory decisions are likewise incomplete: the spec states only the cash leg rounds and refund adjustments enter Z, but supplies neither an executable mixed-tender split contract nor the required Z production writes.

## 2. Critical — the actual first legacy return does not follow the spec's narrative

### 2.1 What the spec claims

The spec says:

- the legacy correction counter starts `0, 1, 2, …` (`spec:77-90`);
- every successful first return starts an orphan side-chain (`spec:95-113`);
- a later `pos_receipts_terminal_sequence` collision is a “structural certainty” (`spec:115-132`); and
- the operator receives an uncaught bare 500 (`spec:121-132`).

It also repeatedly treats VOID as another user of this legacy correction counter (`spec:61,79-80,90,105,119`).

### 2.2 What the current code actually does

The database migration does declare `current_sequence` default `0` (`apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:38-42`), but that is not the effective creation behavior across the repository:

- all three public terminal creation paths explicitly set `current_sequence = 1` (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:111-124,387-399,450-465`);
- the virtual admin resolver and factory also use `1` (`apps/api/app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php:38-50`; `apps/api/database/factories/TerminalFactory.php:20-33`);
- the staging demo v3 terminal is an important exception: `DemoPharmacySeeder` explicitly sets `current_sequence = 0` and `fiscal_schema_version = 3` (`apps/api/database/seeders/DemoPharmacySeeder.php:752-771`).

`ReceiptReturnService::buildReturnDraft()` resets a stale-year counter to `1`, not `0`, then reads the counter and formats the receipt number at line 739 (`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:721-739`). PostgreSQL also enforces `chain_sequence IS NULL OR chain_sequence > 0` (`apps/api/database/migrations/tenant/2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:25-33`). The spec cites `ReceiptReturnService.php:730-736` for both the read and the formatted number, but the format is at `:739`, and the omitted reset is behaviorally decisive.

The authoritative device counters are per chain context, not one counter shared by sessions, Z reports, and sales. `SESSION_OPEN`, `OPENING_FLOAT`, `SESSION_CLOSE`, `X_REPORT`, and `Z_REPORT` use `z_session`; `SALE_RECEIPT` and the approval/override events use `operational` (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:209-246`). The append calculation the spec cites as `:606-611` is actually at `:608-612`. Thus a normal shift open does not consume operational sequence 1; a simple first sale normally does.

The exact legacy return ordering is:

1. The current POS prepares server line IDs, authors two operational approval events, force-syncs them, then calls `/return` (`apps/pos/src/stores/refundCheckoutStore.ts:331-390`). Those approval events advance the device operational chain, but create no `pos_receipts` rows.
2. The API locks the original and terminal, validates quantity, window/cap/manager policy and destination, writes the draft, lines, voucher/payment/cash side effects and stock effects, then calls `ReceiptFinalizationService::finalize()`—all inside one outer transaction (`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:188-265,278-395,397-482`).
3. Finalization assigns the legacy `last_hash/current_sequence`, computes a v3 canonical receipt hash when the terminal is schema 3, saves the receipt, and only then advances the terminal counter (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:83-111`).
4. `pos_receipts` enforces `UNIQUE(terminal_id, receipt_year, chain_sequence)` (`apps/api/database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:93-97`). The return service's unique-violation recovery recognizes only an error mentioning `refund_request_id` (`ReceiptReturnService.php:148-166,519-530`).

### 2.3 First-return outcomes by real terminal state

- **Staging/demo topology (`current_sequence = 0`, same current year):** the first finalization attempts `chain_sequence = 0` and fails the `pos_receipts_sequence` CHECK immediately. It does not first create an orphan receipt.
- **Normal controller/factory topology (`current_sequence = 1`) with a simple first v3 sale at operational sequence 1 in the same receipt year:** the first finalization attempts sequence 1 and immediately collides with the projected sale on `pos_receipts_terminal_sequence`. Again, it does not first create an orphan receipt.
- **Conditional orphan case:** if sequence 1 is not occupied by a projected receipt—for example operational sequence 1 was an approval/account event, the first sale is at a later sequence, or the original is cross-terminal/previous-year—the first return can commit with `previous_hash = NULL`, producing the disconnected legacy side-chain the spec describes. A later collision can occur if the correction counter reaches a projected receipt sequence in the same year. It is not guaranteed for every first return or every finite terminal lifetime.

On either standard immediate-failure path, no draft, stock, voucher, payment, GL, or drawer side effect commits: the outer transaction rolls everything back. The service rethrows the `QueryException`, but `ReceiptController::processReturn()` catches `RuntimeException` and returns a `RETURN_FAILED` 422 with the exception message (`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:246-356`); Laravel's `QueryException` extends `PDOException`, which extends `RuntimeException`. This is loud and transactionally safe, though it leaks a low-level database error and is not a useful domain response. It is not the bare 500 claimed at `spec:128-132`.

VOID is a different failure class entirely. `ReceiptVoidService` never calls `ReceiptFinalizationService`; it updates the original receipt in place, reverses stock/batches, records a drawer refund, and emits `ReceiptVoided` (`apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:53-135`). Repository-wide finalization callers confirm no VOID call (`ReceiptReturnService.php:472`, `ReceiptPaymentService.php:436`, `ExchangeService.php:299-302`). Therefore VOID neither advances Chain B nor causes the described chain-sequence unique collision. The `ReceiptFinalizationService` docblock on which `spec:282-284` relies is stale: it names `ReceiptReturnService::createReturn` although the live method is `processReturn`, and says void depends on finalization although the implementation does not (`ReceiptFinalizationService.php:33-57`).

### 2.4 What remains true

The core compliance gap is real whenever a legacy return does commit on a v3 terminal. `VerifyPosChainCommand` excludes `fiscal_event_id IS NOT NULL` rows from the legacy verifier (`apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:169-184,211-222`), while `fiscal:verify-event-chain` walks `fiscal_events` only (`apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:19-42`). Such a return is absent from the authoritative v3 chain. The baseline Critical finding—no proven device sale → return → next sale → Z composition—is therefore real, but the spec's rollout-risk characterization is not.

The proposed test shape also needs correction. `fiscal:verify-event-chain --terminal=<id>` at `spec:169-171` cannot run successfully as written: the command requires `--tenant`, `--terminal`, and `--actor-id` (`VerifyEventChainCommand.php:69-75,88-145`). The integration test must exercise the real ingest/job lifecycle, both projectors, a genuine device-authored v3 payload, and the signed Z path; directly calling only `PosCoreReceiptProjection::apply()` is not an end-to-end proof.

## 3. Critical — the existing fiscal payload cannot implement the promised return semantics

The spec says “the server side of this design already exists and is already tested” and “the gap is entirely on the device side” (`spec:37-41,188-211,267-271`). That is true only for a very narrow synthetic REFUND: non-negative canonical magnitudes, a receipt-level original UUID, unconditional return projection, generic outward tender legs, and a generic GL reversal. It is false for the live return contract.

The current v3 `line_items[]` exact key set contains no `original_line_id`, disposition, physical-receipt, or resalable fields (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1825-1872`; device mirror in `FiscalEventEngine.ts:2652-2660`). `OriginalReceiptReferenceInput` is receipt-level only (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:367-376`). The projector creates return lines without `original_line_id` or disposition (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:692-711`) and unconditionally restocks every REFUND/VOID line (`:1016-1049,1300-1335`). This cannot preserve the current `RESTOCK` / `SCRAP` / `NOT_RECEIVED` behavior or the regulated `restock_policy = never` guard implemented by `ReceiptReturnService` (`ReceiptReturnService.php:397-467,1093-1137`).

The proposed quantity-cap reuse is also mechanically wrong:

- legacy return lines carry exact `original_line_id` and **negative** stored quantity (`ReceiptReturnService.php:955-975`);
- `calculateAlreadyReturnedQuantities()` multiplies that negative quantity by `-1` and keys it by `original_line_id` (`:1148-1182`);
- canonical REFUND lines are required to carry positive magnitudes and the projector stores that positive value unchanged (`FiscalPayloadConstraintValidator.php:2724-2742`; `PosCoreReceiptProjection.php:692-711`).

Reusing the legacy calculation against canonical rows would subtract returned quantity, and fallback matching is ambiguous when an original contains duplicate lines for the same product/variant. An authoritative cap therefore requires stable original-line identity in the signed payload, a direction-aware calculation, and a server transaction that locks the original receipt (or an equivalent per-original serialization key) before checking and inserting. Without that lock, two terminal projection jobs can both observe the same remaining quantity and both pass; §3b's S5 is not closed by merely adding a query.

The payload also has no refund-destination field. `payments[]` alone does not encode the current destination semantics:

- a `store_voucher` instrument is interpreted by `PosCoreReceiptProjection` as **voucher redemption**, not issuance (`PosCoreReceiptProjection.php:934-965`), whereas the live return path calls `VoucherIssuanceService::issueFromRefund()` (`ReceiptReturnService.php:791-830`);
- the live `original_payment` path prorates against original Treasury payment rows, records negative `PaymentType::Refund` rows, preserves `original_payment_id`, and applies cumulative exhaustion/idempotency (`ReceiptReturnService.php:832-860`; `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:628-708,729-825,924-946`);
- `TreasuryReceiptBridge` instead creates new positive `PaymentType::POS` rows keyed only to the refund fiscal event and canonical leg ordinal, with no `original_payment_id` (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1311-1339`).

Finally, moving off `ReceiptReturnService` bypasses the existing return window, daily cashier cap, manager threshold, destination resolver, voucher issuance, original-payment proration, disposition validation, and regulated-stock policy (`ReceiptReturnService.php:278-319,366-467,536-645,1058-1137`). The spec moves only quantity validation. An offline approval explicitly bypasses server revocation/cap checks today (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:247-299`). Calling the current server path “already complete” would silently remove material fraud and inventory controls.

Required design conclusion: either define a new immutable `SALE_RECEIPT` payload **version** carrying at least original-line references, per-line disposition facts, refund destination/settlement semantics, and the approval/policy evidence needed for deterministic projection, or explicitly narrow launch to a much smaller correction feature and retain a synchronous server preauthorization/reservation contract. The present spec attempts to have the richer semantics without encoding them.

## 4. Critical — author-then-sync is not a settlement protocol

### 4.1 The “unchanged” approval phase makes fully offline refunds impossible

The spec says `SaleReceiptApprovalReferenceInput` is “structurally identical” to `RefundApprovalEvidence` and phase 1 remains unchanged (`spec:236-243`). It is not. The signed reference has `approval_event_id`, `override_event_id`, `policy_version`, `supervisor_user_id`, and `target_reference_id` (`FiscalEventEngine.ts:444-452`). `RefundApprovalEvidence` renames several fields, drops `policy_version` and `target_reference_id`, and adds `authorized_by_user_id` (`apps/pos/src/lib/refundFlow/refundSettlementService.ts:82-90`). The underlying `PosOverrideEvidence` has the needed seven fields (`apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:27-35`), but `authorRefundReturnApproval()` discards two of them (`apps/pos/src/lib/refundFlow/refundApproval.ts:98-105`).

More importantly, that helper is contractually bound to the **server** receipt ID and mapped server line IDs, so it must run after online preparation (`refundApproval.ts:11-30,40-50,68-96`). It then force-syncs both approval events before proceeding (`:108-121`; `approvalFiscalSync.ts:24-78`). Same-device original lookup does not remove either dependency. The spec must redesign the approval target around immutable device receipt/line identities, preserve all signed reference fields, and remove or replace force-sync. Those production files are absent from the frozen manifest.

### 4.2 The physical and accounting moment of settlement is undefined

At S2 the spec declares a locally appended event “fiscally final and irreversible” and says there is no longer a settlement-rejected state (`spec:360`). At S4 it admits the server can later reject that same event, leaving local Z to show a settled refund with no server receipt, GL, or drawer effect (`spec:362`). The design never says when the cashier hands over cash or a voucher, who authorizes that physical payout, or how it is reversed if S4/S5 occurs. A chain-valid statement that cash was refunded cannot safely coexist with a server decision that the refund had no business effect.

There are only two coherent models:

1. **Immutable event is authoritative:** once signed, the server must project it deterministically and may flag policy violations for review, but must not erase its money/inventory effects; any later correction is a new compensating fiscal event.
2. **Server acceptance is authoritative:** the device must obtain a reservation/preauthorization before payout and before authoring the final correction, with a durable, expiring idempotency token that serializes quantity/cap/destination checks.

The spec chooses neither. “Dead-letter and manual review” is not a compensation protocol, particularly after cash has left the drawer.

### 4.3 Idempotency and durable intent are missing

`FiscalEventEngine` deduplicates on tenant, terminal, source class, and source ID, but the proposed `refundId` is never defined as a durable identifier. Today's `refund_request_id` and approval evidence live only in Zustand memory (`refundCheckoutStore.ts:222-234,331-377`); reset, cancellation, process death, or restart can lose them. A retry can then author a second approval pair or refund event. The design needs a durable local refund-intent row created before approval, with a stable UUID, original/line/destination snapshot, state, approval IDs, event ID, print/payout state, and recovery rules.

## 5. State-machine attack — all claimed states and missing transitions

The spec claims these states:

| Claimed state | Claimed transition/recovery | Finding |
|---|---|---|
| S0 approval authored, not synced | Retry sync without re-authoring | Evidence is memory-only; a crash loses the mapping and allows duplicate approval authoring. |
| S1 approval sync fails | Return UI to approval, retain evidence | Contradicts fully-offline same-device refunds; unchanged force-sync blocks progress. |
| S2 refund signed locally, sync pending | Existing outbox eventually syncs | Does not distinguish pending, retryable transport failure, server ingress rejection, sequence quarantine, or permanent sync failure. No payout/print transition is specified. |
| S3 local commit fails | All refund event, local receipt, voucher/Z bookkeeping commit or none | The proposed transaction only names `engine.append()`. No local refund-receipt insert, voucher issuance, or Z write is specified. Existing Z data is in a separate table and API (`local_refund_records`), not automatically part of append. |
| S4 server projection refuses event | Dead-letter; local Z remains settled; manual review | A “non-retryable” exception is still retried five times because the job catches every `Throwable`. No report in the manifest exists, and no correction/compensation transition is defined. |
| S5 two devices over-refund | Second arrival dead-letters | A check without a lock can let both pass. Even if one dead-letters, the losing terminal may already have paid the customer. |

Missing states/transitions include:

- approval events committed but payload construction/append fails;
- crash after approval commit but before the in-memory evidence cache update;
- refund append committed but crash before local printable receipt, payout acknowledgement, cart cleanup, or Z record;
- fiscal sync rejected/quarantined before projection;
- POS projection applied but Treasury sibling projection dead-lettered (receipt/stock exists; payment/GL does not);
- Treasury applied after POS, followed by a later discovered business-policy violation;
- dependency becomes available after dead-letter (manual reset required);
- duplicate scan/retry after restart with no durable source ID;
- dead-letter reconciliation resolved by compensating event, accepted exception, or replay;
- VOID correction of a refund/return, which the current UI explicitly prohibits and the new model does not rule on.

Projection lifecycle claims need correction. `ApplyFiscalEventProjectionJob` retries **every** projector exception because its only projector catch is `catch (Throwable)` and rethrow (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:390-401`). `$tries = 5` and the backoff are at `:137-179`; there is no non-retryable branch. Dead-letter is terminal and normal handle re-delivery short-circuits it (`:253-261,413-470`). Recovery is an operator action through `fiscal:retry-projections` (`apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php:21-38,220-239`), not automatic when an original later syncs. Therefore `spec:453-459` is false.

POS and Treasury are sibling projectors, not one transaction. The code's own dependency exception documents that Treasury can run before/after the independently committed POS row (`ProjectionDependencyMissingException.php:9-29`). S4's assertion of “no receipt and no GL/drawer effect” is only achievable for a cap thrown inside POS before its writes; it is not a complete server-refusal state.

## 6. VOID design is incomplete and current server behavior is not VOID-correct

The current mutation gap is real: `ReceiptVoidService` changes `is_voided/fiscal_status` on the original without a v3 correction event (`ReceiptVoidService.php:71-135`). However, the spec's claim that any future documented replay would “silently un-void” it (`spec:373-390`) is not verified by current code. `PosCoreReceiptProjection::apply()` returns immediately when a receipt already exists for that fiscal event (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:185-204`), and PostgreSQL forbids deletion of sealed `pos_receipts` (`apps/api/database/migrations/tenant/2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:17-40`). No rebuild tool that deletes and recreates these rows was identified. The actual problem is that the mutation is outside the authoritative chain and can diverge from canonical truth—not the asserted current replay behavior.

For a new canonical VOID event, current server behavior is not sufficient:

- `PosCoreReceiptProjection` maps both `REFUND` and `VOID` to `ReceiptType::Return` and always writes `is_voided = false` (`PosCoreReceiptProjection.php:303-355,525-544`);
- `Nf525DataProvider` puts only `is_voided = true` rows in the void bucket, while non-void `ReceiptType::Return` rows go to returns (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:129-196`); its canonical return mapper handles REFUND and VOID identically (`:809-882`);
- `ReportGenerationService` increments `voided_count` only for `is_voided`, and otherwise counts a `ReceiptType::Return` as a refund (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:895-995`);
- the projector unconditionally restocks both REFUND and VOID; no link marks the original voided or distinguishes void reporting.

Thus a canonical VOID would be reported as a refund/return, not as an NF525 ANNULATION/void. “No new VOID-specific work” at `spec:397-402` is false. The design must choose the audit model explicitly: whether the original remains immutable-visible plus a separate VOID reversal, how NF525 maps the pair, how reports/Z count it, how original status is derived, and how replay reconstructs that status. The POS currently has no VOID surface—the refund approval helper says the deleted modal left refund as the only correction surface (`apps/pos/src/lib/refundFlow/refundApproval.ts:4-9`)—and the manifest has no VOID payload builder, store/UI, print, Z, or reporting files.

The fallback is also not actionable. A visible caveat “wherever void status is displayed” and a rebuild skip-list require UI/report/rebuild file writes, none listed. It contradicts both the full-integration ruling and the unconditional v3 `/void` guard. Lane C requires one chosen launch policy, not a code-phase choice.

## 7. Cross-terminal and mixed-v2 lookup contract is under-specified

The projector does resolve an original strictly by `pos_receipts.fiscal_event_id` and fail closed when absent (`PosCoreReceiptProjection.php:564-637`). A v2 original therefore cannot be referenced by a valid canonical v3 correction; prohibiting that bridge is sound.

The device behavior is not “already correct with no extra code” (`spec:420-435`). A local miss has three indistinguishable causes: a v2 receipt, a v3 receipt authored on another terminal, or a same-device v3 receipt whose local event is unavailable. The device cannot show the spec's distinct messages without parsing trusted receipt metadata or performing the online lookup.

There is already a company-scoped, permission-gated `GET /pos/receipts/{id}` that returns line data and `returned_quantity` (`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:537-576`), while the dedicated lookup service is deliberately same-terminal (`apps/api/app/Modules/POS/Application/Services/ReceiptLookupService.php:17-35,53-100`). A cross-terminal contract must specify exact files and, at minimum:

- exact QR/receipt-number input semantics and anti-enumeration/rate limits;
- tenant and company scope, authorization, and terminal eligibility;
- proof that the original is a non-void, fiscal-event-backed SALE/TRAINING receipt;
- original fiscal event ID and business date sourced from the authoritative event;
- stable original line IDs or canonical line references, quantities already returned, dispositions allowed, policy window/caps, allowed destinations, and original payment/proration data;
- a capability/version field so an old server cannot accept a new device correction incompletely.

Returning only `fiscal_event_id + business_date` is insufficient to construct the signed line, destination, approval, and payment facts. Conversely, once a lookup has returned a row from `pos_receipts`, that original is already projected, so the spec's immediate “original still mid-sync” race does not describe the lookup-success path. If a dependency later dead-letters, recovery is manual, as §5 establishes.

## 8. E1 attack — principles right, integration claims wrong

The adopted E1 rules are correctly restated: independent rounding at refund time, cash leg only, partials independent, VAT exact, no unwind, dedicated delta (`docs/superpowers/specs/2026-07-27-refund-rounding-research.md:3-11`). The implementation design has four blockers.

### 8.1 Mixed tender is contradictory and has no source of truth

The spec says `isCashOnlyTender()` gates rounding “at all” (`spec:511-521`), then says a mixed original-payment refund rounds its proportional cash leg (`spec:533-540`). `isCashOnlyTender()` returns true only if **every** leg is cash (`apps/pos/src/lib/payment/cashRounding.ts:173-186`), so it disables rounding for a cash+card mix. A different per-leg algorithm is required.

There is no production proration breakdown for the device to sign. `RefundDestinationPicker`'s `prorationBreakdown` and `allowedDestinations` are optional seams, and its comment says backend wiring is deferred (`apps/pos/src/components/pos/RefundDestinationPicker.tsx:8-13,35-48`). The production caller supplies neither. Current proration happens only inside server `PaymentRefundService` after `/return`. The spec must define one deterministic algorithm and ensure the lookup/prepare response, UI display, device payload, and server verification use byte-equivalent allocations.

### 8.2 Current v3 payload fields are required, not optional in practice

The TypeScript input interface marks the two cash-rounding fields optional (`FiscalEventEngine.ts:428-431`), but the device registry now authors `SALE_RECEIPT` event version 3 (`apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:158-174`), and the server requires both keys on every v3 event, using canonical zero when rounding is absent (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:822-865`; server registry at `FiscalEventPayloadRegistry.php:53-72,119-121`). A refund builder modeled merely as a sibling of the old `SaleReceiptPayload.ts` would be rejected unless it builds the full V3 shape. The current sale path is already wired through `buildSaleReceiptV3Payload()` (`apps/pos/src/lib/offline/receiptService.ts:343-399`), contrary to `spec:522-526`.

The existing `F-09-refund-eur` fixture proves only the older REFUND discriminator/reference shape. Its payload lacks V2 variant keys and mandatory V3 rounding keys, and the cited validator tests invoke the default older version (`apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-09-refund-eur/payload.json`; `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:1307-1313`). It is not evidence that a current device-authored event-version-3 refund shape is complete.

### 8.3 Refund rounding GL is already implemented under a different contract

The spec says refund rounding needs new `TreasuryReceiptBridge` work and that inspection found no relevant implementation (`spec:546-552`). Current code already:

- invokes the rounding entry for v3 REFUND/VOID (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:395-405`);
- selects `pos_cash_rounding_refund` for corrections and posts through the rounding income/expense purposes (`:409-470`);
- has a dedicated test proving the symmetric refund reversal and distinct source type (`apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php:233-269`).

The literal string `pos_refund_rounding` is absent because the frozen implementation uses `pos_cash_rounding_refund`. That name is also enforced by `GeneralLedgerService` and a partial unique index (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3471,3509-3512`; `apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:126-129`). Renaming it would require those files and migration/test work absent from the manifest. The spec should retain the existing source type unless a separately reviewed migration is justified.

### 8.4 Device Z production writes are missing

The device Z's rounding summary loops only `offline_receipts` (`apps/pos/src/lib/offline/zReportService.ts:162-190,270-305`). Refund totals/cash impact come from `local_refund_records` (`:195-232`), whose schema/API expects a server return receipt ID/number, negative total, destination, and settled timestamp and has no rounding fields (`apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts:20-90`). Merely extending `zReportService.cashRounding.test.ts` cannot make refund adjustments enter production Z.

The spec must decide whether a refund is inserted into `offline_receipts`, whether `local_refund_records` is redesigned around the device event, or whether `zReportService.ts` reads refund fiscal events directly. That requires production code and possibly a SQLite migration/repository change. `refundZAccounting.ts` by itself cannot satisfy S3 atomicity or the Z-summary ruling. On the server, `ZReportProjection::cashRoundingSummary()` already sums all projected non-void v3 receipts, including canonical returns (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:204-233`), which further underscores the need to reconcile dead-lettered local events rather than merely count them.

The current AVOIR observation is correct: it hardcodes no cash rounding (`apps/pos/src/lib/buildReceiptData.ts:690-715`). `hydrateFromReceipt` also correctly reads lines rather than `receipt.total` (`apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts:49-86`). However, making AVOIR support conditional at `spec:642-645` violates the owner ruling that full correction-chain integration plus E1 is one Lane C scope; it is not a frozen decision.

## 9. Frozen manifest audit

### 9.1 It is not exact

Section 6 calls itself an exact frozen file list (`spec:590-594`) but contains:

- “new file, e.g.” for the exception (`spec:603-606`);
- an unnamed endpoint, controller method, and resolver whose names are deferred (`spec:610-613`);
- unnamed projection tests and an alternative test location/name (`spec:147-149,614-619`);
- `ReceiptVoidService` conditional on a later choice (`spec:601-602`);
- `buildReceiptData.ts` conditional on whether E1 is in scope (`spec:642-645`);
- `cashRounding.ts` listed among writes while explicitly requiring no changes (`spec:640-641`).

That is not a dispatchable frozen manifest.

### 9.2 Missing required writes

For the design as described, at least these production/test surfaces are missing or must be explicitly replaced by an alternative exact file:

- `apps/api/app/Modules/POS/routes.php`, `ReceiptController.php`, an exact request/resource/resolver file, and exact endpoint tests;
- `ApplyFiscalEventProjectionJob.php` if “non-retryable” is meant literally, plus explicit dead-letter/retry/reconciliation command or service paths;
- a durable operator-facing dead-letter report/API/UI and alert tests—the spec names `pos_refund_dead_letter_report` but then says it is outside the manifest (`spec:362`);
- the canonical payload version/DTO/key-set/reader/drift/golden-fixture files needed for original-line identity, disposition, destination, and approval/policy evidence;
- projector/service paths for voucher **issuance**, original-payment proration/linkage, destination policy, return window/daily cap, regulated stock and per-line disposition;
- `Nf525DataProvider.php`, `ReportGenerationService.php`, Z/report projection/device authoring paths, and VOID tests so VOID is not counted as a refund;
- the POS VOID builder/store/UI/print/Z path;
- `refundApproval.ts`, `posOverrideAuthoring.ts` and/or a new durable approval adapter, because the current server-ID target and force-sync cannot remain unchanged;
- a durable local refund-intent repository and SQLite migration, plus `localRefundRecordRepository.ts` and/or `zReportService.ts` for atomic local recovery and refund rounding;
- `RefundDestinationPicker.tsx` / `RefundCheckoutFlow.tsx` or exact replacements to display allowed destinations and signed proration;
- i18n/error-mapping files for the promised typed v3 guard response;
- rollout capability/feature-gate files and exact staging-data integration tests.

### 9.3 Extraneous or already-complete writes

- `TreasuryReceiptBridge.php` is extraneous for the E1 GL behavior claimed; it and `TreasuryReceiptBridgeRoundingGlTest.php` already implement refund rounding. It may still need changes for a redesigned destination/proration contract, but that is different work and must be described accurately.
- `cashRounding.ts` is a read-only dependency, not a write-manifest entry.
- A new refund rounding bridge test duplicating the existing test is not minimal; the existing test should be extended only if the new payload/destination behavior creates an uncovered case.

### 9.4 The “registry/validator untouched” claim is not achievable for the full design

The spec is correct in one narrow sense: `REFUND` and `VOID` need not become new **event types**; they can remain `SALE_RECEIPT` discriminators. Therefore event-type registration alone does not force a registry change.

But the design promises authoritative line-level caps, dispositions, destination-specific settlement, mixed-tender allocation, and deterministic approval/policy projection. The existing exact payload shape cannot express those facts. Adding them requires a new immutable `SALE_RECEIPT` event version and necessarily touches:

- device `FiscalEventPayloadRegistry.ts` to author the new version and its runtime key sets/validation;
- server `FiscalEventPayloadRegistry.php` to accept historical versions plus the new version;
- `FiscalPayloadConstraintValidator.php` for version-specific exact keys and invariants;
- canonical DTO/reader and drift/golden-fixture tests.

If those files remain untouched, the implementation must omit or infer signed facts from mutable server state, defeating the spec's cap/disposition/destination claims and risking replay drift. Thus `spec:652-654` is false for the full design.

Lane B owns the still-open payload-guard/test surface and v4 forbids Lane C code work before Lane B merges (`DISPATCH-PLAN-v4:42-68`). The correct fence is: finish/merge Lane B, then issue a Lane C spec addendum and a new exact post-merge manifest that explicitly transfers/version-bumps the payload contract. Silently avoiding the frozen files is not compliance with the fence; it is a correctness hole.

## 10. Migration and rollout risk for existing v3 terminals/receipts

The spec's only rollout ordering is “land the legacy v3 guard first,” accepting a period where v3 refunds are unavailable (`spec:289-303`). It does not protect a mixed application-version rollout or the existing staging data. The repository handoff records 5 demo tenants, 6 companies, and 8 existing receipts (`docs/handoff/HANDOVER-first-tenant-orchestrator-2026-07-31.md:22-30`); the task states those receipts are v3. The demo v3 terminal writer's legacy counter is explicitly zero (`DemoPharmacySeeder.php:752-771`).

Risks not handled:

- deploying a new device before the full server projector/policy/report contract lets the old server accept an immutable REFUND shape and apply incomplete/wrong stock, voucher, payment, VOID, or policy effects;
- deploying only the guard safely prevents new legacy corruption but creates an operational refund/void outage, including old POS builds, without a capability handshake or operator runbook;
- existing disconnected legacy return/void state is not inventoried; no preflight searches v3 terminals for `fiscal_event_id IS NULL` corrections, legacy void markers, counter/check conflicts, or unresolved projection rows;
- no plan proves that the eight existing receipt hashes and operational heads remain untouched and that the next correction/sale appends after the current device head;
- no plan forbids resetting/backfilling `terminal_state` or copying `pos_terminals.current_sequence/last_hash` into the device head—either would corrupt the existing chain;
- no cloned-staging test covers both `current_sequence = 0` demo terminals and `current_sequence = 1` normal terminals.

A safe design must specify: server schema/validator/projector/report support first behind a disabled capability; exact preflight/inventory and remediation of existing legacy correction artifacts; device deployment with durable refund intents but feature disabled until the server advertises the required contract version; controlled per-terminal enablement; legacy endpoint guard coordinated with minimum client version; and sale → refund → next sale → Z plus VOID evidence against a clone of the existing eight-receipt dataset. Existing fiscal rows must never be rewritten or backfilled into a new chain.

## 11. Citation verification ledger

Every code citation and current-code behavioral assertion in the spec was checked. The material failures are individually reported above. This ledger records the remaining results so that no citation is silently accepted.

### Verified accurate, subject to the design qualifications above

- `PosCoreReceiptProjection.php:84-90,325-328` accurately documents and writes fiscal-event mirror chain columns.
- `2026_01_08_190637_create_pos_receipts_table.php:93,97` accurately identifies the terminal/year/sequence unique constraint.
- `VerifyPosChainCommand.php:171-180,217` accurately identifies the legacy verifier carve-out; it also excludes voided rows at `:181-183,218-220`, which the spec does not mention.
- `ReceiptReturnService.php:148-166,519-530` accurately identifies the narrow idempotency catch, but not the outer HTTP status behavior.
- `ReceiptReturnRefactorTest.php:494` accurately pins the principal fixture to v2; the repository search found no test combining a schema-3 terminal with the live return/void service path.
- `FiscalEventEngine.ts:367-376,408,413,2621-2648` accurately describes the receipt-level original reference and REFUND/VOID requirement.
- `FiscalPayloadConstraintValidator.php:1784-1823,2724-2742` accurately mirrors that rule and positive-magnitude money contract.
- `PosCoreReceiptProjection.php:537-637` accurately maps REFUND/VOID to Return only after resolving `fiscal_event_id` and throws the retryable dependency exception when absent.
- `FiscalEventPayloadRegistry.ts:65,67,84-107,158-174` accurately shows reserved separate correction types are unimplemented and `SALE_RECEIPT` is current version 3.
- `SaleReceiptPayload.ts:159,163` accurately hardcodes SALE/TRAINING and null original reference in that legacy builder; the “only builder” conclusion is false because production calls `SaleReceiptV3Payload`.
- `refundCheckoutStore.ts:286-409` and `refundSettlementService.ts:475-494` accurately show approval sync followed by the legacy POST and local Z mirror.
- `receiptService.ts:479-505` accurately shows the single-writer sale append/source-ID pattern. It does not establish a refund transaction containing the additional writes S3 claims.
- `fiscalEventRepository.ts:6-29,75-89` accurately lists local columns and the pending/failed outbox query.
- the guard examples at `ShiftController.php:60,124`, `SyncController.php:53`, and `ZReportSyncController.php:75` are accurate.
- `refundSettlementService.ts:4-13,264-288,389-429` accurately describes today's online/server-ID requirement and `NOT_SYNCED` result.
- `refundZAccounting.ts:9-24` accurately documents today's best-effort post-server mirror.
- `ReceiptVoidService.php:53-135` accurately proves in-place mutation with no correction event.
- `ReceiptReturnService.php:1058-1089,1148-1182` accurately proves quantity-not-amount capping for the legacy negative-line model.
- `cashRounding.ts:140-171,180-186` accurately identifies the pure rounding helpers and cash-only predicate; the spec applies that predicate inconsistently to mixed tenders.
- `hydrateFromReceipt.ts:49-86` and `buildReceiptData.ts:712-714` accurately describe line hydration and the current no-rounding AVOIR output (the spec's `:712-713` range omits `has_cash_rounding` at current line 714).
- `FiscalEvent`'s model docblock accurately calls the server event row immutable (`apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:14-21,77-80`).

### Wrong, stale, incomplete, or unverifiable citations/claims

1. **`FiscalEventEngine.ts:606-611`:** stale range; the actual previous-hash/sequence calculation is `:608-612`.
2. **“Only ReceiptCreation/Finalization touch terminal counters” (`spec:58-61`):** false; controller creation paths, virtual resolver, factory, demo seeder, and return reset also assign `current_sequence`.
3. **`ReceiptReturnService.php:730-736` includes the formatted number:** stale/incomplete; formatting is `:737-739`, and `:730-733` resets to 1.
4. **Migration default 0 means corrections are `0,1,2…`:** false behavioral inference; most writers explicitly use 1, demo uses 0, and chain sequence 0 violates the live CHECK.
5. **One sequence counter shared by session/cash/Z/sales (`spec:87-90`):** false; chain contexts split z-session from operational events.
6. **Every first return succeeds as an orphan and later certainty/500 (`spec:95-132`):** false; standard paths fail on the first attempt with rollback and HTTP 422. Orphan commit is conditional.
7. **VOID uses/advances the legacy finalization chain:** false; no `ReceiptVoidService` finalization call exists.
8. **`ReceiptFinalizationService.php:44-56` proves current void/fallback topology:** its docblock is stale relative to live callers and names a nonexistent `createReturn` method.
9. **`PosRefundReceiptBridgeTest.php:135-165` proves idempotent replay:** that range proves drawer/GL; idempotent replay is a separate test beginning at `:231-240`.
10. **Golden `F-09` proves the current exact v3 refund shape:** false; it is an older-version REFUND fixture without current V3 required fields.
11. **`SaleReceiptPayload.ts` is the only SALE_RECEIPT builder and sale rounding is not wired (`spec:222-225,522-526`):** false; production uses `buildSaleReceiptV3Payload` at `receiptService.ts:343-399`.
12. **Approval reference structures are identical (`spec:239-243`):** false; names and fields differ as detailed in §4.1.
13. **Phase 1 can stay unchanged while same-device works fully offline:** false; it requires server IDs/line IDs and a successful forced sync.
14. **Event append atomically includes local receipt, voucher and Z bookkeeping (`spec:361`):** unverifiable/unsupported; no such writes are designed, and current data paths are separate.
15. **“Non-retryable” exception uses existing lifecycle without infrastructure work (`spec:490-498`):** false; all `Throwable`s retry five times. A true non-retryable branch requires job changes.
16. **Dead-letter automatically resolves when original syncs (`spec:453-459`):** false; dead-letter is terminal until operator retry/reset.
17. **Local miss distinguishes v2 from cross-terminal without extra code (`spec:420-435`):** false; the states are observationally identical locally.
18. **No VOID-specific server work (`spec:397-402`):** false; projector, NF525, report and Z semantics classify canonical VOID as a return/refund.
19. **Documented rebuild silently un-voids current rows (`spec:386-390`):** unverifiable and contrary to normal idempotent replay/delete guards.
20. **The server side already covers the happy path entirely (`spec:267-271`):** false for policy, line cap identity, disposition, voucher issuance, proration, and VOID/report semantics.
21. **Refund bridge rounding work is absent (`spec:546-552`):** false; implementation and a dedicated symmetric-refund test already exist under `pos_cash_rounding_refund`.
22. **Rounding payload fields may be omitted/zero on v3 (`spec:522-526,533-534`):** false as written; both keys are required on every event-version-3 payload, with zero values when not applied.
23. **`buildReceiptData.ts:712-713` covers both fields:** one-line drift; current fields are at `:713-714`.
24. **`fiscal:verify-event-chain --terminal=…` is a runnable acceptance step:** incomplete; required tenant and actor flags are missing.
25. **Internal references `§3.3a`, `§3.3b`, and `§3.3d` (`spec:204,259,276,286,336,362`) exist:** they do not; the headings are §3a–§3d.

## 12. Required re-work before re-review

1. Replace §1 with the exact state-dependent first-return trace in §2 above: demo counter 0 → CHECK failure; ordinary counter 1 + sale sequence 1 → immediate unique failure; conditional sequence gap → orphan commit; full rollback; controller 422; VOID is a separate in-place-mutation failure. Remove “every first return,” “structural certainty,” and “bare 500.”
2. Choose and specify one settlement authority model: immutable device correction always projects with later violations handled by compensating events, **or** server reservation/preauthorization precedes payout and final authoring. Define exactly when cash/voucher is handed over and how every failure is compensated.
3. Redesign approval/idempotency for offline operation: device-stable original/line targets, full seven-field approval reference, no mandatory force-sync for same-device offline flow, durable refund-intent storage, stable source ID, restart/retry/print/payout recovery.
4. Version the `SALE_RECEIPT` payload to carry stable original-line references, per-line disposition facts, refund destination and deterministic settlement allocation, plus required policy/approval evidence. Specify positive canonical magnitudes and exact aggregate/VAT/rounding identities.
5. Define server projection semantics for quantity locking/caps, daily cap/window/manager policy, disposition and regulated stock, voucher issuance, original-payment proration/linkage, idempotency, and compensating correction. Do not claim existing projector/bridge coverage for these paths.
6. Replace S0–S5 with a complete durable state machine covering approval-orphan, append-before-UI crash, ingress rejection/quarantine, POS-success/Treasury-failure, dead-letter manual retry, physical payout, compensation, and cross-device locking. Name every transition and terminal state.
7. Choose one VOID launch ruling and specify the complete device authoring plus server/NF525/report/Z/original-status semantics. If VOID is prohibited, define a single explicit guard and operator workflow; remove the contradictory legacy fallback.
8. Specify the exact cross-terminal lookup request/response/security/capability contract, including canonical line IDs, returned quantities, policy/destination/proration data and v2 distinction. Correct dead-letter recovery to require operator retry.
9. Rewrite E1 around the existing V3 builder and existing `pos_cash_rounding_refund` GL implementation; define a per-leg mixed-tender algorithm and its authoritative proration source; add the real device Z production data path and make AVOIR support unconditional within Lane C.
10. Add an explicit staged rollout for the existing eight v3 receipts: preflight legacy corrections/voids and projection state, server-first capability-gated deploy, disabled device rollout, coordinated legacy guard, cloned-staging chain tests for counter 0 and 1, and a prohibition on rewriting/backfilling existing events or resetting chain heads.
11. Replace §6 with a genuinely exact, post-Lane-B manifest containing every named production/test file required by items 2–10. Include the payload registries/validator/DTO/reader/drift fixtures required by the version bump, or formally re-fence those files after Lane B. Remove no-change and already-complete entries.
12. Correct every stale citation and internal section reference in §11, and make the acceptance command include required tenant/actor flags and real ingest/projection/Z orchestration.

Until those decisions are made, the code phase cannot be safely planned or dispatched.
