# Adversarial review, round 2 — v3 refund-chain integration spec revision 2

**Target:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md`

**Canonical revision checked:** `7d42c463f` (`feat/v3-refund-chain`)

**Repository baseline checked:** `e3341e2ae24a15553c227f07296f0060577bdc7e` (`dev`, including Lane B merge `4bb87b483`)

**Review type:** pre-implementation adversarial design/specification review

**Verdict:** **REJECT**

Revision 2 materially improves the failure trace, chooses a settlement model, adds a versioned payload direction, prohibits VOID, adopts cash-only refund rounding, and supplies far more concrete rollout and file-scope prose. It is still not safe to plan for implementation. The proposed v4 event cannot be authored through the current device engine with the files in §9; the durable intent transaction references an event ID before the engine generates it; approval recovery reuses a source-ID scheme that the server rejects; the state model cannot tell whether cash was paid or whether server projections applied; the verifier repair keys historical hashing to a mutable terminal version and therefore breaks mixed v2→v3 history; store-voucher fallback is blocked by the same guard the spec mandates; and Model 1 still dead-letters authoritative, already-paid events without defining any compensating event or write-off ledger.

## Review method and repository evidence

The untracked main-checkout copy is byte-identical to the canonical file at `7d42c463f` (`git diff --no-index` produced no output). The review checked revision 2 against both round-1 review files, the current `dev` code, current migrations, current tests, and Lane B's actual merge. In particular, `git show --stat --oneline 4bb87b483` reports only these Lane B paths:

```text
apps/pos/eslint.config.js
apps/pos/src/lib/db/__tests__/offlineReceiptRepository.cashRounding.test.ts
apps/pos/src/lib/fiscal/__tests__/CashRoundingCapsDrift.test.ts
apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts
```

Lane B did not move or modify the event registry, server validator, canonical DTO/reader, `FiscalPayloadKeyDrift.test.ts`, or golden-vector corpus. That matters to the §9 post-Lane-B fence.

## 12. Fold-completeness audit against round-1 §12 items 1–12

### 12.1 Item 1 — **INCORPORATED BUT WEAKENED**

The main correction is incorporated. Revision 2 gives the three state-dependent outcomes at `spec:90-118`: counter `0` produces the PostgreSQL CHECK failure; ordinary counter `1` can collide immediately with the first projected sale; a gap can produce an orphan; the outer transaction rolls back; the controller returns 422; and VOID is described separately at `spec:82-88`. Those statements match the current writers and constraints: `TerminalController.php:111-124,387-399,450-465`, `DemoPharmacySeeder.php:752-771`, `ReceiptFinalizationService.php:83-111`, `2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:25-33`, and `ReceiptController.php:246-356`.

The verifier partition was also determined correctly for a committed v3-hashed orphan. `VerifyPosChainCommand.php:179-194` trivially returns valid while the legacy-row count is zero, then invokes both arms once the count is nonzero. `ReceiptHashService.php:313-353` recomputes every null-`fiscal_event_id` row with the legacy pipe hash, whereas `ReceiptFinalizationService.php:97-103` seals a schema-3 row with `V3ReceiptHashComputer`; `Nf525DataProvider.php:380-446` repeats the legacy recomputation. This supports `spec:128-157`'s zero-row trivial pass followed by a persistent hash mismatch.

It is weakened by two remaining contradictions. First, `spec:168-170` again says a legacy-sealed “return/void” creates the permanent red state, even though `spec:82-88` correctly establishes that `ReceiptVoidService.php:53-135` creates no correction row and never calls finalization. Second, the acceptance scenario calls the initial sale a “`SALE_RECEIPT` (event_version 4) sale” at `spec:202-205`, while the contract and manifest require SALE/TRAINING to remain version 3 (`spec:334-337,968-970`).

### 12.2 Item 2 — **INCORPORATED BUT WEAKENED**

Revision 2 chooses Model 1 and defines payout after local commit at `spec:350-369`. That closes the round-1 indecision in form.

It does not close it in substance. The model says an authoritative device event “must project” and must not lose its money/inventory effects (`spec:352-356`), but the state machine deliberately dead-letters the losing over-refund and books nothing while acknowledging that cash may already have left the drawer (`spec:435-437`); the only stated resolution is a loss write-off. No compensating fiscal event type, payload, builder, projector, accounting writer, idempotency key, or manifest file is defined. The only plausible correction vocabulary remains reserved: `FiscalEventPayloadRegistry.ts:65-69,162-175` leaves `SALE_CORRECTION`, `REFUND_RECEIPT`, and `PARTIAL_REFUND` unimplemented. “Operator-recorded write-off” likewise has no named model/service/command in `spec:899-1014`. Model 1 is therefore contradicted by its own permanent-rejection path.

### 12.3 Item 3 — **INCORPORATED BUT WEAKENED**

The spec now provides device-stable targets, preserves all seven approval-reference fields, removes force-sync, introduces a stable intent UUID, and proposes a durable table (`spec:371-403,444-468`). Those are the right components.

Three code-grounded holes remain:

1. `spec:405-417` says the transaction first stores `refund_fiscal_event_id` and then calls `engine.append()`. The ID does not exist yet: `FiscalEventEngine.append()` generates it internally only at `FiscalEventEngine.ts:637`, after validation, version selection, idempotency lookup, and hash construction (`:539-635`). The manifest does not change the engine to accept a caller-supplied ID.
2. Approval-orphan recovery says both approval events can be found by `source_event_id` (`spec:427-429`), but the reused `authorPosOverride()` generates a random approval UUID for the first event and uses `${targetReferenceId}:${approvalScope}` for the override (`posOverrideAuthoring.ts:73-75,111-145`). The caller cannot supply the first source ID, and the second value is not a UUID. The server requires every non-null `source_event_id` to be a UUID (`FiscalEventEnvelope.php:201-228`) and stores it in a UUID column (`2026_05_14_100001_create_fiscal_events_table.php:51-52`). Section 9 explicitly says `posOverrideAuthoring.ts` has no field changes and only its caller changes (`spec:981-982`), so the proposed approval pair cannot reliably sync or be recovered as specified.
3. The table has no `payout_status`, `payout_acknowledged_at`, `printed_at`, or recovery token (`spec:379-388`). A crash after local commit but before cash handover is indistinguishable from a crash after handover. Resuming the same `refund_event_appended` row can therefore omit or duplicate the physical payout. This does not satisfy round 1's explicit restart/retry/print/payout-recovery requirement.

There is also no uniqueness/active-intent rule preventing a cashier from starting a new intent for the same frozen original/line selection while an earlier appended intent is pending. `FiscalEventEngine` deduplicates only an exact reused source tuple and returns the existing row without comparing the retried payload (`FiscalEventEngine.ts:578-588,746-772`); a fresh intent UUID is a fresh fiscal event.

### 12.4 Item 4 — **INCORPORATED BUT WEAKENED**

The spec does select `SALE_RECEIPT` event version 4 and defines original-line references, disposition, refund destination, settlement allocation, positive magnitudes, and inherited V1–V3 aggregate/rounding identities (`spec:294-344`). It also explicitly says V1–V3 remain immutable.

The contract is not yet implementable or complete:

- Device event-version resolution is type-only: `FiscalEventPayloadRegistry.eventVersionFor(type)` returns `3` for every `SALE_RECEIPT` (`FiscalEventPayloadRegistry.ts:162-174`), and `FiscalEventEngine.append()` calls it before payload validation with only `request.event_type` (`FiscalEventEngine.ts:567-571`). Section 9 changes the registry but omits `FiscalEventEngine.ts`; conditional versioning by `invoice_type_code` needs either a payload-aware registry API or an explicit requested version threaded through the engine.
- The engine itself owns the v3 exact key set and always validates every SALE_RECEIPT against it (`FiscalEventEngine.ts:1082-1113,1451-1463`). A v4 refund with three extra fields is rejected locally unless this file changes. Section 9 lists only the registry and drift test, not the engine (`spec:965-1010`).
- Round 1 required policy/approval evidence sufficient for deterministic projection. V4 adds no policy snapshot for the return window, daily cap, or destination policy. Existing `approval_references[]` is only syntactically validated (`FiscalPayloadConstraintValidator.php:1046-1081`); the projector has no linkage check against the actual approval events, unlike the legacy controller's full chain/target verification at `ReceiptController.php:364-431`.
- `refund_destination` is defined as including `store_voucher` at `spec:317-319`, then declared illegal for launch at `spec:712-715`. This is not one exact v4 schema.
- The spec never pins the positional relationship between `original_line_references[]` and the refund's `line_items[]`, nor requires each reference quantity/product to equal the corresponding signed refund line. “One entry per refunded line” (`spec:306-316`) is insufficient for a strict parallel-array contract.

### 12.5 Item 5 — **INCORPORATED BUT WEAKENED**

Revision 2 now designs a per-original row lock and quantity cap (`spec:578-597`), disposition-aware stock behavior in the manifest (`spec:919-923`), original-payment proration (`spec:722-752`), and idempotent manual retry. Store-voucher issuance is at least recognized as unimplemented rather than claimed complete.

Major required semantics remain absent:

- The current legacy path enforces the return window, daily cap, manager threshold, destination resolution, disposition combinations, and regulated-stock policy in `ReceiptReturnService.php:278-319,366-467,536-645,1058-1137`. Revision 2 defines no device cache/snapshot or server advisory/acceptance rule for the return window or daily cap, and no projector-time verification of the signed manager evidence. The only server business rejection designed in detail is quantity excess.
- Model 1 requires late policy violations to project and be flagged, but §4.5 makes quantity excess a permanent projector exception and dead-letter (`spec:590-596`). No alert row or compensating projection is specified for the other policy classes.
- Store-voucher fallback is self-contradictory. `spec:712-720` sends a v3/v4 voucher refund to the legacy `/return` endpoint, while `spec:516-525,938-940` requires that endpoint to reject every `fiscal_schema_version >= 3` terminal. There is no destination-specific carve-out in the guard design.
- The signed allocation uses original payment ordinals, but the current `PaymentRefundService` loads Treasury payments by amount DESC/id ASC (`PaymentRefundService.php:645-656`) and `pos_receipt_payments` has no ordinal column (`2026_01_08_190640_create_pos_receipt_payments_table.php:20-57`; `ReceiptPayment.php:74-87`). The spec does not define how canonical ordinal maps deterministically to the original Treasury `Payment` ID on replay.

### 12.6 Item 6 — **INCORPORATED BUT WEAKENED**

The revision replaces S0–S5 with a broader state table that names approval orphaning, append crash, ingress quarantine, sibling-projector partial success, dead-letter/manual retry, cross-device over-refund, and terminal success (`spec:423-438`). This is a real improvement.

It is not a complete durable state machine. The persisted `state` enum is only `drafted → approval_authored → refund_event_appended → synced → applied`, plus `dead_lettered`/`abandoned` (`spec:385`). It has no persisted approval-orphan, payout-pending/paid, print-pending/printed, quarantine, or POS-applied/Treasury-dead-lettered state. More importantly, the current device sync response reports ingestion only (`fiscalEventRepository.ts:62-71`; `syncService.ts:382-413` marks the event synced on an accepted ingest). It does not report projector states. The spec adds a server operator dead-letter API but no device poll/push contract or POS file that advances a local intent to `applied`, `dead_lettered`, or the sibling-partial state. Those advertised transitions are therefore unobservable and cannot be persisted.

### 12.7 Item 7 — **INCORPORATED BUT WEAKENED**

The main ruling is incorporated: `spec:490-525` prohibits VOID on schema-3+ terminals, removes the legacy fallback choice, and names “process as a REFUND” as the operator workflow. That matches the round-1 option and correctly acknowledges that current canonical VOID would be misclassified (`PosCoreReceiptProjection.php:537-544`; `Nf525DataProvider.php:129-196`; `ReportGenerationService.php:936-953`).

The prohibition is weakened by the v4 contract and manifest. `spec:334-340` first requires a genuine v4 VOID vector, §9 later says no VOID vector (`spec:914-915`), and the device registry is told to resolve version 4 for `{REFUND, VOID}` (`spec:968-970`) rather than to reject VOID authoring at the device boundary. There is no explicit engine/validator test proving a `SALE_RECEIPT` with `invoice_type_code=VOID` is launch-rejected. The operator workflow also fails for a voucher-funded original because §6.2's voucher fallback is blocked by the schema-3 guard.

### 12.8 Item 8 — **INCORPORATED BUT WEAKENED**

The new lookup contract names an endpoint, authorization, eligibility checks, authoritative response fields, version capability, and manual dead-letter recovery (`spec:527-576`). This incorporates most of the requested surface.

It still contains contradictions and non-exact choices. The endpoint promises one uniform not-found/not-eligible response (`spec:547-552`) and then says the UI may distinguish the `fiscal_event_id IS NULL` case (`spec:553-561`); the client cannot make that distinction unless the response leaks a reason. The throttle count is explicitly “TBD” (`spec:547-549`). `max-returnable-quantity` is described as computed under the §4.5 lock so the device “never” misestimates (`spec:562-566`), but a GET cannot retain that row lock through offline authoring and later projection; another terminal can refund after lookup. Under Model 1 this value is necessarily advisory, not a reservation.

### 12.9 Item 9 — **INCORPORATED**

The E1 rework is substantively incorporated. The spec anchors on `buildSaleReceiptV3Payload` (`spec:229-251`; actual production call `receiptService.ts:357-399`), gates rounding on an all-cash refund payout (`spec:603-621`; `cashRounding.ts:173-186`), requires both rounding keys (`spec:623-631`; server enforcement `FiscalPayloadConstraintValidator.php:832-865`), retains the landed `pos_cash_rounding_refund` GL contract (`spec:633-651`; `TreasuryReceiptBridge.php:395-470`), names the real device Z readers and migration work (`spec:653-681`), and makes the AVOIR rounding line unconditional (`spec:683-688`; current hardcode `buildReceiptData.ts:713-714`).

The separate `ReportGenerationService` issue in §6.5 is discussed under the manifest/new-surface audit; it does not undo the E1 correction itself.

### 12.10 Item 10 — **INCORPORATED BUT WEAKENED**

Revision 2 adds the requested preflight, server-first deployment, disabled device rollout, per-terminal enablement, counter-0/counter-1 clone tests, and no-rewrite/no-head-reset prohibitions (`spec:818-881`).

The capability gate cannot be implemented from the specified files. The only concrete capability field is returned by the online cross-terminal lookup (`spec:562-572`), but the same-device path is explicitly fully offline and never calls that endpoint (`spec:470-474`). No terminal capability column, `TerminalResource.php` field, device sync/cache field, or feature-gate service is listed in §9. Therefore the disabled device build cannot learn that a same-device refund cohort is enabled, and the “guard + enablement are one atomic deploy per terminal cohort” rule (`spec:855-862`) has no state or API that can enact it.

The post-Lane-B precondition is also stale in this checkout: Lane B is already merged at `4bb87b483`, while `spec:901-902` still says dispatch must wait for it to merge “to local dev.”

### 12.11 Item 11 — **INCORPORATED BUT WEAKENED**

The manifest is much more extensive, but it is not exact and omits load-bearing writes. Concrete defects:

- `spec:910-912` still says “or the correct canonical-view class per Lane B's post-merge location.” Both exact files already exist at `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php` and `.../Canonical/SaleReceiptCanonicalView.php`; the actual reader is `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:62-116`, and that reader is omitted.
- `spec:947-950` names a controller “and its route” without naming `apps/api/app/Modules/Fiscal/routes.php`; no controller test is named.
- `spec:938-940` requires “one shared guard helper” without naming its file.
- `spec:999-1003` lists the `apps/pos/src/lib/i18n/` directory and defers the exact namespace file to code phase.
- `spec:981-982` includes `posOverrideAuthoring.ts` while explicitly saying it receives no changes; `spec:983-985` likewise does not identify a concrete write to `refundSettlementService.ts`. This repeats the round-1 no-change-entry problem.
- `FiscalEventEngine.ts` is omitted despite owning both version resolution invocation and v3 exact-key validation (`FiscalEventEngine.ts:567-571,855-862,1082-1113,1451-1463`). `FiscalEventPayloadRegistry.test.ts` and `FiscalEventEngine.test.ts` are also omitted.
- `CanonicalPayloadReader.php` and exact new nested DTO files for original-line references and settlement allocations are omitted.
- A golden fixture is named with only `payload.json` (`spec:914`), but every current fixture directory contains both `payload.json` and `expected.json`; `GoldenFixtureBuilder.php:28-45` owns the F-01…F-14 corpus and the current round-trip test asserts exactly 14 fixtures (`FiscalPayloadConstraintValidatorTest.php:1715-1732`). The proposed `F-10-refund-v4` also reuses the F-10 number already occupied by `F-10-void-eur`. No PHP/TS cross-language v4 canonical-byte/hash parity test is named.
- The SQLite migration has no named migration-version test, despite the repository's `migrations.vNN.test.ts` pattern.
- The preflight command, dead-letter API, capability gate, and guard have no exact test/exception/resource/route manifest sufficient for their promised behavior.
- `ReportGenerationService.php` is described as v3-relevant (`spec:763-796`), but both of its X/Z entry points reject schema-3 terminals before the private expected-per-method query can run (`ReportGenerationService.php:69-73,94-108,159-169`), and `buildExpectedPerMethod()` has no other caller (`:219,486`). The v3 Z projection instead consumes device-authored `cash_count.expected_cash` (`ZReportProjection.php:140-158`). The proposed server write is therefore a v2/legacy fix, not the v3 launch fix claimed; the device `refund_intents` Z/expected-cash work is the relevant path.

### 12.12 Item 12 — **INCORPORATED BUT WEAKENED**

Most stale citations and all broken internal `§3.3a`/`§3.3b`/`§3.3d` links are corrected or removed. The acceptance command now carries `--tenant`, `--terminal`, and `--actor-id` at `spec:211-214`, matching `VerifyEventChainCommand.php:69-75,88-145`, and the test calls for real ingest/job/projection/Z orchestration (`spec:202-217`).

It is weakened by the two uncorrected behavioral statements already identified: `spec:168-170` reintroduces VOID into the orphan-verifier symptom, and `spec:405-417` replaces the old unsupported atomicity assertion with a transaction order that cannot obtain the generated event ID. The acceptance test's “event_version 4 sale” at `spec:202-205` is also incompatible with the version ruling.

## Citation-ledger re-verification

The table below rechecks every numbered correction from round 1 §11, not merely the existence of revision 2's §10.

| Round-1 ledger item | R2 result | Repository/spec evidence |
|---|---|---|
| 1. Engine append range | **Applied** | `spec:80-81` now cites `FiscalEventEngine.ts:608-612`, which is the previous-hash/sequence calculation. |
| 2. Counter writers | **Applied** | `spec:54-65` names controller, resolver, factory, and demo seeder writers; the three controller assignments are at `TerminalController.php:111-124,387-399,450-465`. |
| 3. Return number/reset range | **Applied by removal** | The stale `:730-736` claim is gone; revision 2 no longer claims that range contains formatting. |
| 4. Default-0 behavioral inference | **Applied** | `spec:54-74` distinguishes runtime `1`, demo `0`, and PG SQLSTATE `23514`; migration CHECK is at `2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:25-33`. |
| 5. Shared counter claim | **Applied** | `spec:75-81` correctly separates `z_session` and `operational`; `FiscalEventEngine.ts:209-246`. |
| 6. Every-first-return/orphan/certainty/500 | **Applied** | `spec:90-118` is state-dependent and identifies the 422 rollback. |
| 7. VOID advances legacy chain | **Partially applied** | Correct at `spec:82-88`, but contradicted by “return/void” at `spec:168-170`; `ReceiptVoidService.php:53-135` still has no finalization call. |
| 8. Stale finalization docblock | **Applied by removal** | Revision 2 cites live callers/implementation, not the stale docblock. |
| 9. Wrong replay-test range | **Applied by removal** | The old `PosRefundReceiptBridgeTest.php:135-165` idempotency claim is absent. |
| 10. F-09 proves current refund shape | **Applied** | `spec:339-340` correctly calls it pre-v3. Its current fixture lacks v3 rounding fields. |
| 11. Only builder / rounding unwired | **Applied** | `spec:229-251` cites the real builder; `receiptService.ts:357-399` is the production call. |
| 12. Approval structures identical | **Applied** | `spec:327-332,446-462` identifies the dropped/renamed fields; current types are `FiscalEventEngine.ts:444-452`, `refundApproval.ts:98-105`, and `posOverrideAuthoring.ts:27-35`. |
| 13. Phase 1 unchanged yet offline | **Applied in prose** | `spec:454-468` removes force-sync and server IDs. The new source-ID/recovery defect is a separate implementation failure discussed above. |
| 14. Append atomically includes unnamed writes | **Not applied** | The writes are named at `spec:405-421`, but the first write needs the result ID generated only inside `FiscalEventEngine.ts:637`; the described order is impossible. |
| 15. Non-retryable exception already supported | **Applied** | `spec:590-593` and `:435` now acknowledge every `Throwable` retries; job behavior is `ApplyFiscalEventProjectionJob.php:390-410,413-470`. |
| 16. Dead-letter auto-recovers | **Applied** | `spec:435,573-576` requires manual `fiscal:retry-projections`; terminal short-circuit is `ApplyFiscalEventProjectionJob.php:253-261`. |
| 17. Local miss distinguishes v2/cross-terminal | **Applied** | `spec:470-484,527-576` explicitly treats the causes as locally indistinguishable and adds an online contract. |
| 18. No VOID server work | **Applied** | `spec:496-514` enumerates projector/NF525/report/device work and prohibits it for launch. |
| 19. Replay silently un-voids | **Applied by removal** | The unsupported replay assertion is gone. |
| 20. Server happy path complete | **Applied as a correction** | `spec:272-292` now lists projector/stock/voucher/payment gaps. The new design still fails to close all of them, but the old claim is withdrawn. |
| 21. Refund rounding GL absent | **Applied** | `spec:633-651` retains `pos_cash_rounding_refund`; current code/test are `TreasuryReceiptBridge.php:395-470` and `TreasuryReceiptBridgeRoundingGlTest.php:233-269`. |
| 22. V3 rounding keys optional | **Applied** | `spec:623-631` states required-always/canonical zero; validator enforcement is `FiscalPayloadConstraintValidator.php:832-865`. |
| 23. AVOIR line range | **Applied** | `spec:686-687,893-895` cites `buildReceiptData.ts:713-714`, which is exact. |
| 24. Acceptance command flags | **Applied** | `spec:211-214` includes all required flags from `VerifyEventChainCommand.php:69-75,88-145`. |
| 25. Broken internal section refs | **Applied** | Repository search finds the old labels only in `spec:1021-1023`'s historical note, not as live links. |

Result: 23 corrections are applied or validly removed; item 7 is only partial, and item 14 remains false in its replacement form.

## New-surface attack

### A. `SALE_RECEIPT` event version 4

#### Additive-only and historical paths

The intended server shape is additive: the current registry explicitly retains `[1,2,3]` (`FiscalEventPayloadRegistry.php:112-149`), the current validator has separate V1/V2 and V3 top-level key sets (`FiscalPayloadConstraintValidator.php:310-370`), and the DTO deliberately round-trips old versions without injecting v3 keys (`SaleReceiptPayload.php:16-22,152-173,177-216`). Revision 2 says not to mutate V1–V3 (`spec:298-304`). That direction is sound.

It is not protected by a complete implementation/test contract. The current device registry has no payload-aware version API and the engine does not thread a version into its SALE_RECEIPT validator. Merely editing `FiscalEventPayloadRegistry.ts` as §9 says cannot make REFUND version 4 while SALE/TRAINING stay version 3. An implementation that simply changes `eventVersionFor('SALE_RECEIPT')` to `4` would stamp all new sales as v4 and contradict `spec:334-337,968-970`; one that leaves it at `3` cannot author a v4 refund.

The spec must define the exact API, for example a payload-discriminator-aware `eventVersionFor(type, payload)` called before validation plus `validateSaleReceiptPayload(payload, eventVersion)`, or an explicit version chosen by the builder and checked by the registry. In either case `FiscalEventEngine.ts` and its tests are mandatory manifest entries.

#### Drift and golden coverage

Coverage is incomplete:

- `FiscalPayloadKeyDrift.test.ts:11-49` currently proves PHP/TS V1/V2 and V3 key-set parity and strict-superset behavior. Extending it for V4 is necessary but not sufficient; the manifest must explicitly preserve these existing assertions and add V4-as-strict-superset-of-V3.
- The manifest names one JSON payload only. Existing golden directories contain `payload.json` and `expected.json`, while `GoldenFixtureBuilder.php:28-45` and `FiscalPayloadConstraintValidatorTest.php:1715-1732` lock the current corpus. A real v4 vector needs exact canonical bytes/hash expectation and a test on both PHP and TypeScript readers/encoders.
- The DTO/view files alone cannot create typed nested values. `CanonicalPayloadReader.php:78-115` constructs each nested DTO explicitly; it and exact new DTO class names are required.
- `spec:334-340` promises refund and VOID vectors; `spec:914-915` omits VOID. If VOID is prohibited, version resolution must reject it and a negative vector/test must prove that boundary. It should not be silently assigned v4.
- `spec:202-205` must say the initial sale is version 3 and the refund is version 4.

### B. `refund_intents` state machine

#### Crash recovery

The table records authorization to pay but not the physical side effect. After `refund_event_appended`, these two executions are indistinguishable: (a) commit → crash → no cash handed over, and (b) commit → cash handed over → crash. The recovery rule at `spec:390-398` only prevents re-authoring; it cannot prevent missed or duplicate payout/printing. A launch-safe design needs durable `payout_pending`/`payout_confirmed` and print status, with an explicit cashier reconciliation screen for the unavoidable crash window around a physical handover.

The write-gate order is also invalid because the engine generates the fiscal event ID. The minimal coherent transaction is append with the stable intent source ID, receive the append result, then update the intent with that result and `refund_event_appended`, all inside the same transaction. The spec currently says the reverse.

#### Retry duplication

An intent UUID makes one attempt idempotent, but nothing prevents a second active intent for the same original and line fingerprint. The spec references “device pre-flight duplicate detection” only as a mitigation at `spec:435-436`; it defines neither a repository query/constraint nor a transition. The state machine needs an explicit active-intent uniqueness/reuse rule and tests for restart, double-click, reopened cart, and concurrent UI submissions.

Approval recovery is not implementable through the current helper: its two source IDs are not both intent-derived, and the override's source ID violates the server UUID contract. Either `authorPosOverride()` must accept deterministic UUID source IDs/transaction context and be modified/tested, or the refund flow needs an exact new authoring service. “Only its caller changes” is insufficient.

#### Replay and server-state holes

The local intent cannot observe `applied` or `dead_lettered` from today's ingest response. `FiscalEventSyncResultItem` contains only stored/event-ID/sequence-conflict/exception-class (`fiscalEventRepository.ts:62-71`), and `pushOfflineReceipts()` marks `synced` as soon as ingest accepts the envelope (`syncService.ts:382-399`). Projection status lives only in server `fiscal_event_projections`; the proposed read-only operator API has no POS consumer. The state machine must either stop at `synced` locally or specify an authenticated status endpoint/pull model and all device files/transitions that consume it.

### C. Verifier repair and NF525 continuity

Branching by the owning terminal's current `fiscal_schema_version` is unsafe. That field is mutable by design: `FiscalSchemaCutoverService.php:18-23,43-61,119-146` upgrades a terminal from 2 to 3 after prior receipts and writes only an audit event. A cut-over terminal can therefore contain null-`fiscal_event_id` rows sealed under the v2 pipe algorithm before cutover and a v3-hashed legacy return after cutover. Revision 2's proposed branch would run `V3ReceiptHashComputer` over every legacy row merely because the terminal is now version 3, breaking valid v2 history.

This also risks NF525 continuity. Both current legacy loops keep one `$previousHash` across ordered rows (`ReceiptHashService.php:315-350`; `Nf525DataProvider.php:380-446`). A repair must select the algorithm per immutable row while preserving the same link walk and terminal-tail check. The existing receipt row has no sealing-schema column, so the spec must define a non-mutating classifier. One implementable option is to compute both permitted algorithms for each null-`fiscal_event_id` row, accept exactly the one that matches its stored hash, fail if neither or both match, and continue the same previous-hash chain; another is to use immutable cutover provenance with an exact boundary. Using current terminal state is not acceptable.

Required tests must include: pure v2 legacy history; pure v3-hashed orphan; a real v2→v3 cutover terminal with both algorithms in one ordered legacy chain; tampering under each algorithm; and identical results from `pos:verify-chains` and NF525 verify-chains. The manifest currently names only a V3 legacy-arm test and no NF525 verifier-repair test (`spec:951-958`).

### D. Model-1 compensating-event story by realistic rejection class

“Project deterministically, flag for review” is not implementable for every realistic class in the current design.

| Rejection/failure class | Current repository behavior | Revision-2 handling | Result |
|---|---|---|---|
| Local builder/append validation fails before SQLite commit | Engine validates before insert (`FiscalEventEngine.ts:559-571`) | No payout yet | Safe, once the transaction order is corrected. |
| Approval override has non-UUID source ID | Server envelope requires UUID (`FiscalEventEnvelope.php:226-228`); current override uses `${target}:${scope}` (`posOverrideAuthoring.ts:143-145`) | Not enumerated | Approval fails ingest; later refund meets a missing sequence/link. Payout may already have occurred. No compensation. |
| Unsupported v4 / exact-key or canonical parse failure | Parser checks supported versions and exact constraints (`StrictCanonicalParser.php:192-209,621-632`); parse failure suppresses projection (`OutboxIngestor.php:918-920`) | “Integrity workflow, out of scope” (`spec:432-434`) | Authoritative paid event has no business projection. Model 1 cannot declare this unrelated. |
| Sequence conflict or malformed envelope | Quarantine/no fiscal row (`IngestionResult.php:89-120`); device marks failed/stops on chain break (`syncService.ts:400-445`) | Existing integrity workflow | No receipt/stock/money projection and no defined payout reversal/write-off. |
| Original receipt dependency missing | POS projector's correction resolution can throw dependency missing; Treasury separately throws when POS row is absent (`TreasuryReceiptBridge.php:224-273`) | Five retries then manual dead-letter | Recoverable only if dependency appears and an operator retries; no outcome for permanent absence after payout. |
| Quantity cap exceeded / concurrent double refund | New permanent exception, five retries, dead-letter (`spec:584-597`) | Loss write-off prose | Direct contradiction of “must project”; no compensating event or accounting write-off implementation. |
| Window, daily cap, stale/revoked approval, destination-policy violation | Legacy checks exist in `ReceiptReturnService.php:278-319,536-645`; projector has no equivalent and only syntactic approval validation exists | Not classified as accept+flag versus reject | Cannot deterministically project or compensate because the policy/evidence/alert contract is absent. |
| Invalid disposition / regulated restock | Legacy guards are `ReceiptReturnService.php:1093-1128` | Manifest says disposition-aware projection but no accept/flag/reject matrix | A late policy conflict has no defined authoritative effect or compensating path. |
| Payment method/repository/purpose-account/config failure | POS throws on unresolved method (`PosCoreReceiptProjection.php:846-880`); Treasury has multiple fail-closed dependencies and GL writes | POS-applied/Treasury-dead-lettered is displayed in operator API | Manual retry can fix configuration, but permanent failure leaves paid cash and partial books; no compensation. |
| Original-payment allocation exhausted/invalid/unsettled | `PaymentRefundService.php:641-680,663-665,970-1007` can reject totals, instruments, IDs, or sums | Spec says verify then call service | No defined authoritative fallback allocation, write-off, or compensating event. |
| POS applies, Treasury dead-letters | Sibling jobs are independent (`ApplyFiscalEventProjectionJob.php:324-410`; `TreasuryReceiptBridge.php:224-273`) | Explicit inconsistent state (`spec:434`) | Discoverability only; not deterministic settlement or compensation. |

The current codebase contains examples of the Model-1-compatible pattern—accept the event and write an advisory flag, such as the late-sale path in `PosCoreReceiptProjection.php:1051-1065`—but revision 2 does not define an analogous refund-violation record, taxonomy, idempotency, report, or accounting consequence. A read-only dead-letter list is not a compensating protocol.

### E. Section-9 manifest and post-Lane-B re-fence

The manifest fails its own “no conditional, alternative, e.g., or already-complete entry” assertion (`spec:901-903`) for the concrete reasons in §12.11. It also does not reflect the actual post-Lane-B state:

- Lane B is already merged at `4bb87b483` on this checkout, so no path-location alternative remains to resolve.
- Lane B touched `zReportService.cashRounding.test.ts`, and revision 2 correctly says to extend it rather than fork it (`spec:1008-1009`).
- Lane B did **not** touch the registry, server validator, canonical DTO/reader, `FiscalPayloadKeyDrift.test.ts`, or fiscal golden fixtures. These files do not require an “or post-merge equivalent” fence; their current exact paths are known and must be listed.
- Conversely, the files actually required by the new version-selection design—`FiscalEventEngine.ts`, `FiscalEventEngine.test.ts`, and `FiscalEventPayloadRegistry.test.ts`—are absent.

The manifest is therefore neither exact nor sufficient to dispatch.

## Final verdict

**REJECT**

Revision 2 is a substantial design improvement, and E1 is now correctly framed, but implementation planning must not begin. The remaining failures are architectural rather than editorial: the chosen authoritative-event model conflicts with permanent dead-lettering, physical payout is not durably represented, the approval chain as reused cannot sync under the server UUID contract, v4 cannot be selected or validated through the current device engine from the listed files, the proposed verifier repair breaks mixed-version terminals, and the rollout/manifest cannot enact the claimed capability gate.

### Required re-work before another implementation gate

1. **Make the v4 authoring contract executable.** Specify the exact payload-aware version-selection API and version-aware device validation; add `FiscalEventEngine.ts`, its tests, registry tests, and explicit V1/V2/V3 non-regression gates. Correct the acceptance scenario so SALE is v3 and REFUND is v4.
2. **Freeze one exact v4 schema.** Remove `store_voucher` from the launch enum or implement it; define the one-to-one mapping and equality invariants between `line_items[]` and `original_line_references[]`; define `payments[]` for each destination; define canonical-ordinal-to-Treasury-payment mapping; and include the missing policy/approval evidence or explicitly classify those policies as advisory with signed/cache inputs.
3. **Repair the approval protocol.** Give both approval events deterministic UUID source IDs derived from the intent, make their recovery query exact, verify the seven-field references against the actual approval/override events during refund projection, and list the production/test files that implement that verification.
4. **Replace the intent transaction/state model.** Append first and store the returned event ID inside the same SQLite transaction; add durable payout and print acknowledgement/reconciliation states; add an active-intent reuse/uniqueness rule; and either specify a device projection-status pull contract or stop claiming local `applied`/`dead_lettered` transitions.
5. **Close Model 1's rejection matrix.** For every ingress, quantity, policy, disposition, approval, allocation, and configuration class, choose accept+project+idempotent alert, retryable dependency, or an exact compensating/write-off path. If any already-paid event may permanently fail to book, define and manifest the compensating fiscal event or write-off ledger, projector, accounting entries, operator action, and replay tests. Otherwise choose Model 2.
6. **Resolve store-voucher and VOID contradictions.** A schema-3-wide legacy guard cannot coexist with store-voucher fallback to `/return`. Either prohibit voucher refunds with clear operator policy or define a destination-specific guarded legacy exception and its chain consequences. Make VOID fail closed at the device authoring boundary and replace the contradictory positive v4-VOID vector/version-resolution prose with negative tests.
7. **Complete server policy semantics.** Specify window, daily-cap, manager/stale-approval, destination, disposition, regulated-stock, quantity, and original-payment rules under Model 1, including which are booking blockers versus advisory review flags and how every outcome remains replay-idempotent.
8. **Replace the verifier repair.** Select the sealing algorithm per immutable receipt provenance, not current terminal version; preserve one ordered previous-hash walk; and add pure-v2, pure-v3-orphan, mixed v2→v3, tamper, terminal-tail, and NF525 parity tests.
9. **Make rollout gating implementable.** Name the server capability/per-terminal state, resource/API, device cache/sync, and enablement files so the fully-offline same-device path can honor a disabled rollout. Update the baseline to acknowledge Lane B is already merged.
10. **Rewrite §9 as a truly exact manifest.** Remove the canonical-view alternative, directory wildcard, unnamed route/helper, TBD throttle, and no-change entries; add the engine/version tests, canonical reader and exact nested DTOs, both golden files plus builder/parity tests, migration-version test, NF525 verifier test, preflight/dead-letter/guard/capability tests, and all compensation/policy files required by items 1–9. Remove or separately scope the unreachable v3 `ReportGenerationService` write.
11. **Correct the remaining citation/wording defects.** Remove VOID from the orphan-verifier symptom, fix the impossible write-gate ordering, remove the event-version-4 sale statement, and reconcile the refund/VOID golden-vector statements.
