# Adversarial execution-plan gate r1

Plan reviewed: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-1.md`

Audit HEAD: `765aabc8a108240a6faedfac67cc2bd4904a1e66`

Result: **1 BLOCKER, 8 MAJOR, 1 MINOR.**

The planning pin `73f2040c6d997b26a2a55dfb7d3c8742d361792e` is valid under the stated rule: the only files changed between it and audit HEAD are documentation files.

## Citation audit

Checked **389 `path:line` citation occurrences representing 244 distinct citation strings**.

- Unresolved paths: **0**
- Out-of-range lines/ranges: **0**
- Citations whose target does not support the attached claim: **2 claim clusters**

Unsupported claims:

1. Plan §8.7, line 2219 says reset must use `Arr::except(..., self::CASH_CONTROL_KEYS)` “exactly as today.” The cited current implementation at `apps/api/app/Http/Controllers/Api/V1/FraudSettingsController.php:200-203` uses `self::REFUND_EXPOSURE_KEYS`, as does accepted spec §4.5.
2. Plan §6.5/§7.5/§7.8 says the new `StockTransferReceiptService` reuses `StockTransferService::lockTransfer()` and `markMovementAsTransfer()` unchanged. The cited symbols at `StockTransferService.php:734` and `:745` are `private`; the same is true of `capitalizeTransferCost()` at `:631` and `computeAllocationWeights()` at `:714`. They cannot be called from the planned new service without a specified extraction or visibility change.

The §0 errata are correct against the accepted spec tables:

- E1: 10 R5 families, seven surface rows 19–25, twelve R5 response-shape rows.
- E2: POS device types mirror rows 8–9, not rows 19–23/25.
- E3: the R5 surface range is 19–25.
- E4: the rev-11 change log overstates that correction.
- E5: mixed payload surfaces are rows 7–9.

## Findings

### BLOCKER B1 — Feature-lane manifest and live-gate work are absent from every slice

The plan adds at least **23 PHP Feature test classes** to parked lane groups but neither lists nor modifies `apps/api/tests/feature-lane-manifest.json`, and it supplies no corresponding `.github/workflows/ci.yml` allowlist decisions.

Current enforced ceilings are:

| Manifest group | Current | Planned addition | Required minimum |
|---|---:|---:|---:|
| `Inventory` | 129 | 17 | 146 |
| `Replenishment` | 8 | 1 | 9 |
| `Migrations` | 14 | 1 | 15 |
| `Compliance` | 23 | 3 | 26 |
| `Notification` | 1 | 1 | 2 |
| Global `gated_ceiling` | 1253 | 23 | 1276 |

The manifest explicitly says these parked-group ceilings remain enforced because a new class otherwise runs nowhere. The plan names local by-path commands and sometimes names the manifest group, but its file lists and source-push ledger at lines 2885–2927 omit the manifest completely.

As written, the first slice introducing a Feature class makes the manifest checker fail. The plan must assign the manifest change to each affected slice, give exact per-group and union ceiling arithmetic, and state which launch-relevant/PG-only classes enter the live `backend-test-pgsql` allowlist while the owning lanes remain parked.

### MAJOR M1 — Fraud-settings reset changes unrelated policy fields

Plan line 2219 specifies:

`Arr::except(CompanyFraudSettings::getDefaults(), self::CASH_CONTROL_KEYS)`

Accepted spec §4.5 and current `FraudSettingsController.php:200-203` require:

`Arr::except(CompanyFraudSettings::getDefaults(), self::REFUND_EXPOSURE_KEYS)`

This is not a harmless naming difference. `Arr::except` identifies fields preserved during reset. The planned constant would preserve cash-control fields and reset refund-exposure fields that must remain untouched. It violates the accepted settings behavior and makes the reset portion of T19 wrong.

### MAJOR M2 — Receive responses expose close-only keys as `null`

Accepted spec §5.0b and §5.3 require receive-receipt payloads to **omit** `quantity_written_off` and `quantity_returned`; those members are present only for `kind=close`.

The planned DTOs at lines 1755–1802 declare both as ordinary nullable properties, and line 1843 explicitly says a normal receipt populates them as `null`. Ordinary nullable Spatie Data properties serialize as keys. That changes “absent” into “present with null,” contradicts the exact response-shape table, and fails T9’s forbidden-key scan for the receive echo.

The plan needs an exact serialization design—separate line DTOs, `Optional`, or a proven conditional exclusion—that makes the keys structurally absent on `kind=receipt`.

### MAJOR M3 — The receipt writer cannot use its specified service seams

The new writer is `StockTransferReceiptService`, but the execution rules require it to call helpers that remain private to `StockTransferService`:

- `lockTransfer()`
- `markMovementAsTransfer()`
- `capitalizeTransferCost()`
- `computeAllocationWeights()`

The plan also requires extracted `restockAtSource()` to serve both `cancel()` and the new writer without specifying its owning class or visibility. No complete receive/close service signatures or dependency graph are supplied.

This is not executable literally. The plan must identify the shared collaborator or exact public/package seam, give its constructor and method signatures, and assign every caller and file modification. Merely saying “reused, unchanged” contradicts the cited code.

### MAJOR M4 — Required DTO/enum text is not reproduced in full

The audit contract requires complete DTO, enum, event, and migration text without ellipsis. The plan reproduces the migrations and most new events/enums, but not all required transport and modified-enum definitions:

- `TransferReconciliationData` is only described in prose at line 1845.
- `TransferReceiverViewData` is only described as a shape at lines 2168–2197.
- `CompanyFraudSettingsData` changes are prose only.
- The modified `TransferStatus` definition is not reproduced as a complete enum.
- Exact conditional receipt-line serialization needed by §5.0b is absent.

These types are cross-layer contracts consumed by generated TypeScript and cannot be left to implementation inference.

### MAJOR M5 — Test ownership is incompatible with slice order

The plan claims every §10 row belongs to exactly one independently dispatchable slice, but several rows depend on production work owned by a later slice:

- **T19 is assigned in full to S2** at lines 1921 and 2327–2329. T19 requires `visibility_version` on stock matrix, both POS stock feeds, and both replenishment feeds. Those carriage changes are explicitly deferred to S3 at lines 2276 and 2385–2400.
- **T9 is assigned in full to S3** at lines 2355 and 2434–2557, including notification surface step 6o. Transfer notification classes and listeners are not introduced until S4 at lines 2621–2625 and 2681–2720.
- **T3 is assigned in full to S1**, although its discrepancy-alert assertion depends on S4 notification implementation.
- S4’s verification does not rerun the supposedly completed T9/T19 rows after their final dependencies land.

The rollout order can remain S1→S2→S3→S4, but test rows must be split into explicitly named partial contracts and then rerun in the slice that closes them, or their ownership must move to the first slice containing every dependency.

### MAJOR M6 — Several required tests are absent or are not genuinely red-first

The red-first tables do not satisfy the stated “FIRST FAILING ASSERTION” contract.

Concrete defects:

- T10 requires **two workers racing the same notification id**. The plan names only sequential “process the same job twice”; no concurrent test method, first failing assertion, or exact command exists.
- T18’s complete authority matrix is asserted in prose, but the named red-first methods do not cover all required rows: view-only, complete-only full route matrix, close-only, manager all-2xx, source-only receive refusal, and no-permission combinations are not each mapped to exact executable cases.
- S1 T4b’s claimed first red assertion is unchanged journal count `0`; that passes before close exists.
- S4’s plain-partial-notification first assertion is notification count `0`; that also passes before notification implementation.
- S3’s positive-control assertions can pass against current visible payloads and therefore are not first-red evidence.
- The migration rerun equality check can pass vacuously unless preceded by an exact assertion proving the backfill rows exist.

The plan must supply the actual first failing assertion for every red-first row and add the missing concurrent notification race and full authority matrix cases.

### MAJOR M7 — Spec §11 rollout step 4 is not implemented

Accepted spec §11.4 requires `docs/handoff/PROMOTION-CHECKLIST-*` to receive:

- tenant permission seeding;
- permission cache reset;
- frontend permission-map export;
- the manual post-commit migration-drift recovery rule.

S1 claims this rollout step, but no promotion-checklist file appears in its exact file list, source-push ledger, or handback deliverables. Dispatch-order shell instructions are not a substitute for modifying the required durable checklist.

### MAJOR M8 — S4 cannot commit its required mobile change

S4’s dispatch packet says:

> Work ONLY inside that worktree.

That worktree belongs to the ERP repository. The same packet requires editing:

`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts`

The mobile file is absent from S4’s exact file inventory and source-push ledger, and the plan provides no mobile repository pin validation, branch/worktree, commit, review, verification command, or separate handback. One ERP commit cannot contain that sibling-repository edit.

Spec §9 does require the mobile contract comments/type. The plan must make that a separately dispatchable mobile-repository action or formally define a second pinned worktree/commit and its verification within S4.

### MINOR m1 — Dirty-tree census says three untracked files but lists two

The baseline lists two untracked paths, while dispatch line 3181 and checklist line 3232 say “three untracked files.” Correct the count or list the missing third path.

## Slice executability assessment

| Requirement | S1 | S2 | S3 | S4 |
|---|---|---|---|---|
| Exact production/test files | Mostly present | Mostly present | Present | Incomplete: mobile sibling file omitted |
| Exact symbols | Incomplete private-helper/extraction seam | Mostly present | Present | Present |
| Full migration text | Present | Present | N/A | N/A |
| Full DTO/enum/event text | Incomplete | Incomplete | N/A | N/A |
| Red-first assertion and exact command | Incomplete | Incomplete T18/T19 | Incomplete T9 | Incomplete T10 |
| Manifest group/ceiling update | Missing | Missing | Missing | Missing |
| Named PG-only classes | Present | Present | None required for core oracle | Incomplete race specification |
| Convention-09 evidence | Present | Present | Read-surface matrix present | Client/notification evidence present |
| Required reviewers | Present | Present | Present | Present |
| Named handback | Present | Present | Present | Present |
| Private PG database letter | `autoerp_test_v` | `autoerp_test_w` | `autoerp_test_x` | `autoerp_test_y` |
| Stops at `status: review` | Yes | Yes | Yes | Yes |

## Spec fidelity matrix

| Accepted-spec item | Plan implementation | Result |
|---|---|---|
| §3.1 receipt counters/CHECKs | S1 migrations §7.3 | PRESENT |
| §3.1 close columns/freight residual | S1 migration §7.3 | PRESENT |
| §3.1 blind setting/version | S2 migration §8.3 | PRESENT |
| §3.1b decimal casts and quantity strings | S1 §7.12, S2 §8.4 | ALTERED/INCOMPLETE: receive-only key omission and full DTO text |
| §3.2 receipt header/line/lot tables | S1 §7.3 | PRESENT |
| §3.3 enums/reason applicability | S1 §7.12 | SEMANTICALLY PRESENT; full modified enum text incomplete |
| §3.4 seven-state machine | S1 status/service rules | PRESENT |
| §3.5 canonical remainder | S1 §7.7 | PRESENT |
| §3.6 I1–I7 | S1 tests/services | PRESENT |
| §3.6 I8 settings singleton/version | S2 §8.7/T19b | PRESENT, except reset mutation |
| §4.0 stored-event mechanism/aggregate anchor | S1 §7.9 | PRESENT |
| §4.0 plain `StockTransferReceiptPosted` | S1 event; S4 listener | PRESENT |
| §4.1 `StockTransferReceivedV1` | S1 §7.9 | PRESENT |
| §4.1 `StockTransferClosedV1` | S1 §7.9 | PRESENT |
| §4.2 `StockTransferReceiptLineRecordedV1` | S1 §7.9 | PRESENT |
| §4.3 replay/atomicity and untouched legacy rows | S1 rebuilder/T15 | PRESENT |
| §4.4 pattern-detection query and attribution | S1 §7.10/T16 | PRESENT |
| §4.5 `ReceivingControlsChangedV1` | S2 §8.7/T19/T19b | ALTERED by reset constant; carriage test ordered too early |
| §5 middleware set | S1 routes; existing group retained | PRESENT |
| §5.0 surfaces 1–6 transfer/receive/close/error shapes | S1/S2 builders and endpoints | ALTERED: receive close-only keys emitted as null |
| §5.0 surfaces 7–9 incoming aggregates | S3 §9.2 | PRESENT |
| §5.0 surfaces 10–11 movement feeds | S3 §9.4 | PRESENT |
| §5.0 surface 12 reconciliation | S1 §7.6 | PRESENT |
| §5.0 surface 13 notifications | S4 §10.3 | PRESENT; T9 ownership incorrect |
| §5.0 surface 14 generated TS types | S4 §10.4 | PRESENT, subject to missing full source DTOs |
| §5.0 surface 15 mobile | S4 §10.9 | NOT EXECUTABLE across repositories |
| §5.0 surface 16 POS cache/version gate | S4 §10.8 | PRESENT |
| §5.0 surfaces 17–18 replenishment | S3 §9.3 | PRESENT |
| §5.0 R5 surfaces 19–25 unchanged | S3 zero-file rule/T9 controls | PRESENT |
| §5.0b exact response shapes | S1–S4 | ALTERED by nullable close-only receipt keys |
| §5.1 receive rules | S1 §7.5 | PRESENT |
| §5.1 error codes `VALIDATION_ERROR`, `LINE_NOT_ON_TRANSFER`, `OVER_RECEIPT`, `LOT_REQUIRED`, `LOT_NOT_ALLOWED`, `UNKNOWN_LOT`, `LOT_OVER_RECEIPT`, `LOT_SUM_MISMATCH`, `LOT_TRACKING_MISMATCH`, `DISCREPANCY_REASON_REQUIRED`, `DISCREPANCY_REASON_INVALID`, `NOTHING_TO_RECEIVE`, `IDEMPOTENCY_KEY_REUSED`, `VALUATION_MODE_UNSUPPORTED` | S1 request/service/enum | PRESENT |
| §5.2 close rules and `DISPOSITION_REQUIRED` | S1 §7.5 | PRESENT |
| §5.3 visibility/builders | S1 full builder, S2 receiver builder | PRESENT; DTO text incomplete |
| §5.4 reconciliation endpoint | S1 §7.6 | PRESENT; DTO body missing |
| §5.5 company setting | S2 §8.7 | ALTERED on reset |
| §5.6 complete and `BLIND_REQUIRES_COUNTED_RECEIPT`/`INVALID_TRANSFER_STATE` | S1/S2 | PRESENT |
| §5.7 aggregate shared contracts | S3 §9.2 | PRESENT |
| §5.8 permissions/any-of route gate/403/404 | S1/S2 | PRESENT; T18 proof incomplete |
| §5.9 replenishment masking | S3 §9.3 | PRESENT |
| §5.10 movement masking and location scope | S3 §9.4–§9.5 | PRESENT |
| §6.1 movement identities/reasons | S1 §7.8 | SEMANTICALLY PRESENT; private helper seam unresolved |
| §6.2 GL buffer boundary | S1 §7.8/T13 | PRESENT |
| §6.3 return-to-source/no GL | S1 §7.8/T4b | PRESENT; red-first assertion defective |
| §6.4 freight allocation/residual | S1 §7.11 | PRESENT |
| §7 notification types/recipients/no quantities | S4 §10.3 | PRESENT |
| §7 rollback/idempotency/context/timestamps/order | S4 §10.10 | INCOMPLETE: two-worker race absent |
| §8 web receiver/full split, receive/close/reconcile UI | S4 §10.4–§10.7 | PRESENT |
| §8 web version invalidation/masked rendering | S4 §10.6–§10.7 | PRESENT |
| §8b POS nullability/cache/version handling | S4 §10.8 | PRESENT |
| §9 mobile contract | S4 §10.9 | REQUIRED CONTENT PRESENT, DISPATCH MECHANICS MISSING |
| T1 | S1 | PRESENT |
| T2 | S1 | PRESENT |
| T3 | S1 | ALTERED ownership: notification dependency is S4 |
| T4 | S1 | PRESENT |
| T4b | S1 | PRESENT, but first-red proof invalid |
| T5 | S1 | PRESENT |
| T6 | S1 | PRESENT |
| T7 | S1 PG | PRESENT |
| T7b | S1 PG | PRESENT |
| T7c | S1 PG | PRESENT |
| T8 | S1 | PRESENT |
| T9 | S3 | ALTERED ownership: notification surface arrives in S4 |
| T9b | S2 | PRESENT |
| T10 | S4 | INCOMPLETE: required two-worker race absent |
| T11 | S1 | PRESENT |
| T12 | S1 | PRESENT |
| T13 | S1 | PRESENT |
| T14 | S1 | PRESENT |
| T15 | S1 | PRESENT |
| T16 | S1 | PRESENT |
| T17 | S3 | PRESENT |
| T18 | S2 | INCOMPLETE exact authority-matrix methods |
| T19 | S2 | ALTERED ownership: five carriage surfaces arrive in S3 |
| T19b | S2 PG | PRESENT |
| T20 | S3 | PRESENT |
| S-matrix receive/close/backfill | S1 | PRESENT |
| S-matrix settings | S2 | PRESENT |
| S-matrix masking/read surfaces | S3 | PRESENT |
| S-matrix notifications/client behavior | S4 | PRESENT |
| §11 step 1 migrations/backfill | S1/S2 | PRESENT |
| §11 step 2 readers with backfill | S1 | PRESENT; deviation is permitted |
| §11 step 3 default-off/no feature flag | S1/S2 | PRESENT |
| §11 step 4 permissions/promotion checklist | S1 | MISSING checklist edit |
| §11 step 5 realignment log | S4 | PRESENT |
| §11 step 6 glossary/generated types/maps | S1/S4 | PRESENT, subject to DTO and mobile defects |

## Slicing deviations against spec §11

1. **Readers and backfill both in S1:** accepted. Spec §11.2 requires the same merge, and S1 does that.
2. **Receipt spine before the blind setting:** accepted only because the setting defaults off and activation is explicitly deferred until after S4.
3. **T18 in S2:** the placement is permissible because S2 introduces the visibility/permission behavior, but the promised full matrix is not executable as currently enumerated.
4. **Entry-exit scope grouped with S3 masking:** accepted. It changes the same read surface and precedes activation.

## Rejected false positives

- The §0 E1–E5 corrections are valid errata, not unauthorized design changes.
- R5 current-stock and per-lot positions remaining visible is the ruled Oracle approach, not a blind-receiving leak.
- Absence of `visibility_version` on movement and entry-exit feeds is intentional.
- Return-to-source and uncapitalized freight residual posting no journal is correct.
- Existing transfer events remain untouched; the plan adds new classes as required by rule 8.
- Recipient resolution before queuing and worker execution without `CompanyContext` satisfy rule 20.
- Explicit currency is carried where monetary scale resolution is needed.
- No mobile screen is required; only the mobile contract/type update is required.
- The documentation-only ancestry from pin `73f2040c6` is valid.
- Arabic using the namespace’s documented English fallback is not itself a missing-translation defect.
- Parent-scoped unique constraints need not redundantly include `company_id`.
- POS locally normalizing masked incoming to zero while preserving null in the distribution payload follows the accepted client contract.
- All T-2/T-3 owner questions are ruled; none is open.

## Preserve

- The five §0 errata.
- The T-1 stock↔GL seam inventory and seven handed-over facts.
- Additive tenant migrations, named constraints, transactional PG backfill, and forward-only recovery.
- Decimal strings at column scale, `QuantityScale::SCALE`, bcmath arithmetic, and the bans on floats and `(float)` casts.
- Existing-event immutability and the three new stored receipt-event classes.
- Explicit recipient resolution before queued notification work.
- R5’s zero-file rule for surfaces 19–25 and the positive-control oracle.
- The complete owner-ruling register: D1, D2, OQ-1–OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4, and R5.
- Per-slice private PG database letters, named reviewers, handback paths, and `status: review` terminal state.
- Convention-09 second-company, second-location, and rerun/idempotency matrices.
- The S1→S2→S3→S4 production order with activation only after S4.

## Owner decisions required

VERDICT: CHANGES-REQUIRED