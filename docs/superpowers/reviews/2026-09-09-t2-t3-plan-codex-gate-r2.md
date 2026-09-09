# Adversarial execution-plan gate r2

Plan reviewed: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-2.md`

Audit HEAD: `fa7f4be6aa1719d1b58aafceb24fab1a83ddb457`

Result: **1 BLOCKER, 6 MAJOR, 1 MINOR.**

The plan pin `ab4b321f0` is current under the prescribed rule. `git diff --name-only ab4b321f0..HEAD` contains only the rev-2 plan and its documentation prompt; excluding `docs/` produces no paths.

This was a read-only audit. No files were edited, no tests were run, and no git state was changed.

## Citation audit

Checked **432 `path:line` occurrences representing 266 distinct citation strings**.

- Unresolved paths: **0**
- Out-of-range lines or ranges: **0**
- Semantic claim clusters not supported by the cited/current content: **3**

The unsupported clusters are:

1. Plan §6.7b calls the receipt-writer dependency graph complete, but the constructor at plan lines 971–979 omits collaborators used later, and the cited current `StockTransferService` constructor and `complete()` implementation do not provide the proposed delegation seam.
2. Plan §7.5/§7.12 calls `TransferReconciliationData` complete, but accepted spec §5.4 requires `visibility_version` and the reproduced constructor at plan lines 2884–2899 omits it.
3. Plan §10.4 says six local entity shadows are replaced by generated `StockTransferData`, `StockTransferLineData`, and `StockTransferLineBatchAllocationData`. None of those symbols exists in `packages/shared/types/generated.d.ts` or in a PHP `#[TypeScript]` source at HEAD, and no slice creates them.

The plan’s §0 errata are correct against the accepted spec’s tables:

- E1: ten R5 families, seven surface rows 19–25, twelve response-shape rows.
- E2: POS device types correspond to rows 8–9.
- E3: the R5 range is rows 19–25.
- E4: the rev-11 change log overstates the correction.
- E5: the mixed payload surfaces are rows 7–9.

## Findings

### BLOCKER B1 — S4 depends on three generated transfer DTOs that no slice creates

**Slice:** S1/S4  
**Plan:** §7.2, §7.12, §10.4 lines 4317–4346, §11.1  
**Spec:** §3.1b, §5.3, §8, §11 step 6

S4 deletes the six local transfer entity shadows and imports:

- `StockTransferData`
- `StockTransferLineData`
- `StockTransferLineBatchAllocationData`

The plan then uses those names in `StockTransferListResponse`, `StockTransferShowResponse`, receive responses, and close responses at lines 4319–4346.

At HEAD:

- `packages/shared/types/generated.d.ts` contains the transfer enums but none of those three DTOs.
- No corresponding PHP DTO class exists under `apps/api/app`.
- S1’s exact file inventory and its reproduced DTO section create receipt, close, receiver, reconciliation, and fraud-settings DTOs, but not the three full transfer DTOs.
- `TransferPayloadBuilder` is an application service returning an array; it cannot cause Spatie’s transformer to generate the missing interfaces.

S4 therefore cannot compile from the plan as written. An implementer would have to invent three source files and their mappings outside the exact file inventory.

**Required correction:** Assign exact PHP paths for all three DTOs, reproduce their complete source text, define the nested mapping from `TransferPayloadBuilder`, add them to S1’s exact files and source ledger, and cover them with the generated-type drift/type-level tests. Recalculate the push and union file totals.

### MAJOR M1 — The receipt-writer call graph is still incomplete

**Slice:** S1  
**Plan:** §6.7b lines 962–995; §7.5 lines 1698–1706; §7.5 close lines 1755–1759; §11.6 line 4602  
**Current code:** `StockTransferService.php:72-77`, `:317-339`

The new `StockTransferReceiptService` constructor is missing dependencies required by its own specified implementation:

- Line 1706 invokes `$this->costLock->acquire(...)`, but `ProductCostLock $costLock` is absent from the constructor.
- Receipt-number generation requires the specified `DocumentNumberingService::generateForKey(...)`, but that service is absent.
- `StockTransferService::complete()` is required to delegate to `StockTransferReceiptService::receiveAllRemaining(...)`, but the specified `StockTransferService` constructor change adds only `StockTransferMovementSupport`, not `StockTransferReceiptService`.
- The existing `complete(string $transferId, string $userId)` loads and locks the transfer itself. The proposed delegate accepts an already-loaded `StockTransfer`, without stating the exact post-extraction ownership of the transaction, row lock, and advisory cost lock.
- `return_to_source` requires source-location access, but the exact controller/service symbol performing that check is not pinned.
- §6.7b says `TransferReceiptResult` carries raw entities and the controller maps the response, while §11.6 says S2 changes the receipt service so the response transfer goes through the gated builder. Those are incompatible ownership descriptions.

This leaves `complete`, direct receive, close, numbering, locking, authorization, and response building without a literal constructor-resolvable graph.

**Required correction:** Reproduce the final constructors and method signatures for `StockTransferService`, `StockTransferReceiptService`, and the controller. Include `ProductCostLock`, numbering, receipt-service delegation, exact transaction/lock ownership, the source-location authorization call, and one unambiguous owner for response-builder selection.

### MAJOR M2 — `TransferReconciliationData` omits required `visibility_version`

**Slice:** S1/S2  
**Plan:** §7.5 line 1761; §7.12 lines 2878–2902; §8 and §11.6  
**Spec:** §5.4 lines 386–388

The accepted reconciliation contract includes `visibility_version`. The plan’s supposedly complete `TransferReconciliationData` constructor contains transfer identity, status, locations, close fields, lines, receipts, and summary, but no version.

S2 adds version carriage to full transfer payloads and five aggregate surfaces, yet neither its exact file list nor its ledger modifies:

- `TransferReconciliationData.php`
- `TransferReconciliationService.php`
- the reconciliation response mapping

The generated web reconciliation type would consequently be wrong.

**Required correction:** Assign the reconciliation version change to S2, list every modified file, reproduce the exact DTO delta, source it from the requesting company’s receiving-controls row, and add an exact API assertion and generated-type assertion.

### MAJOR M3 — T11 does not prove historical reader answers survive the backfill and reader switch

**Slice:** S1  
**Plan:** §7.13, §7.14 lines 2953–2957  
**Spec:** T11 at spec line 580; §11 steps 1e–2

T11 explicitly requires the three readers’ totals to be identical before and after the completed-row backfill and the switch to `REMAINDER_SQL` plus `CARRYING_STATUSES`.

The planned T11 methods prove:

- legacy receipt creation;
- shipment-time lot grain;
- counters;
- interrupted PG transaction rollback;
- clean rerun idempotency.

T12 is a source-code ratchet that searches for constants and forbidden `InTransit` literals. Neither test captures reader results before migration and compares them with results after both the backfill and query change.

This leaves the highest-risk compatibility property—preserving every historical completed/in-transit/cancelled answer—untested.

**Required correction:** Add an exact behavioral regression method with completed, in-transit, and cancelled transfers, with and without lots. Capture and compare outputs from both `LocationStockQueryService` query sites, `StockMatrixQueryService`, and the WAC owned-quantity calculation before and after the migration/query transition. Name its first failure and exact PG/SQLite commands.

### MAJOR M4 — The entry/exit scope fix lacks the required non-blind output-preservation proof

**Slice:** S3  
**Plan:** §9.5, §9.8, §9.9 lines 4131–4132  
**Spec:** §5.0 row 11, §5.10, T17

The two T17 methods prove that a restricted user sees only L2 and that an explicitly forbidden location returns 403. They do not prove that an unrestricted, non-blind user continues receiving the same company-wide groups, ordering, line membership, and quantity strings after `LocationScopeResolver` is introduced.

That omission matters because the controller currently groups movement rows into notes. An incorrect scope join or requested-location interpretation could silently remove or regroup ordinary non-blind output while both planned restricted-user tests pass.

**Required correction:** Add a before/after regression fixture for an unrestricted non-blind actor covering at least two locations and a mixed-receiver note group. Assert identical groups, order, identity fields, and exact quantity strings, apart from the deliberately added false masking flag.

### MAJOR M5 — Several rows classified as red-first name assertions that pass at HEAD

**Slices:** S1, S3, S4  
**Plan:** §7.14 lines 2958–2959; §9.9 lines 4125–4132 and §9.9b; §10.10 lines 4470–4477

Concrete cases:

- `TransferReceiptGlBoundaryTest::test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm` names `assertEmpty('request')`. The buffer is already empty before receipt/close exists.
- `TransferCloseReplenishmentSettlementTest::test_settled_request_stays_fulfilled_after_both_close_dispositions` names the existing `fulfilled` status, which already passes unless a preceding close-response assertion is made explicit.
- `TransferMovementMaskingTest::test_second_company_masking_is_independent` names company B’s visible `'5.0000'`; current unmasked behavior already returns it, yet §9.9b classifies the method as RED.
- `TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note` scans for forbidden keys but has no stated positive notification-count/liveness assertion first; zero notifications makes the scan pass.
- The web “none of the three read permissions” case already redirects under the current single-permission guard, so `queryByRole(...).toBeNull()` is a control, not red-first evidence.

These violate the required “FIRST FAILING ASSERTION” contract even though rev 2 repaired several other r1 examples.

**Required correction:** Add explicit missing-route, positive-row, missing-meta, or positive-notification assertions before each negative/control assertion, or classify the methods as controls and provide separate genuinely red tests plus falsification evidence.

### MAJOR M6 — The source-push ledger is not an exact once-only file ledger

**Slices:** S1/S2  
**Plan:** §7.16 lines 2990 and 3071; §11.1 lines 4514–4524; §11.2 lines 4530–4538

Two independent ledger defects remain:

1. Push 2 contains both `Production add (7)` and `Production add (11)`. The second list repeats the seven entries while adding four receiver DTOs. The stated push total counts only eleven, so the ledger text and arithmetic disagree about whether the first seven are duplicated.
2. §7.16 orders an edit to `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` but deliberately excludes that file from every push list because the orchestrator would add it later. That is still a repository edit required by the plan, with no owning slice, commit, reviewer, or handback row.

The ledger also labels eleven enumerated S1 DTOs as “the ten DTOs,” although its numeric production-add total happens to use the correct overall count.

**Required correction:** Delete the obsolete Push-2 seven-file row; count and list each file exactly once. Either remove the optional historical-checklist cross-reference or assign it to S1 and include it in the file inventory, ledger, review, and totals.

### MINOR m1 — S1’s feature-lane raise note misstates its PG-only classes

**Slice:** S1  
**Plan:** §6.7.4 lines 617–629

The Inventory raise-note template says three of its twelve Inventory classes are PG-only and names `TransferReceiptSchemaRerunPostgresTest`. That class belongs to the separately raised `Migrations` group, not the twelve-class Inventory list.

Within Inventory, the two wholly PG-only classes are the receipt concurrency and pattern-query tests. `TransferLegacyCompletionBackfillTest` is cross-lane with one PG-only method.

**Required correction:** State the Inventory classification accurately and put the schema-rerun class in the Migrations raise note.

## Slice executability

| Requirement | S1 | S2 | S3 | S4 |
|---|---|---|---|---|
| Exact production/test files | **FAIL** — missing full transfer DTO files; orphan checklist edit | **FAIL** — reconciliation version files omitted; duplicate ledger row | Present | **FAIL** — imports DTOs no prior slice creates |
| Exact symbols/call graph | **FAIL** — receipt writer/complete DI incomplete | Mostly present | Present | Blocked by missing generated entity DTOs |
| Full migration text | Present | Present | N/A | N/A |
| Full DTO/enum/event text | **FAIL** — transfer DTOs absent; reconciliation DTO incomplete | **FAIL** — no reconciliation-version delta | N/A | Imports cannot be generated |
| Red-first assertion and command | **FAIL** — T13/T14 | Present for core S2 cases | **FAIL** — second-company masking misclassified | **FAIL** — empty notification scan and no-permission control |
| Manifest arithmetic | Correct: +14 → 1267 | Correct: +5 → 1272 | Correct: +3 → 1275 | Correct: +2 → 1277 |
| PG-only classes named | Present; note wording inaccurate | Present | None required | Present, including two-worker race |
| Convention-09 evidence | Writers covered, but T11 historical-reader proof missing | Present | Present; T17 preservation gap | Present |
| Required reviewers | Three named | Three named | Three named | Three named |
| Handback | Named | Named | Named | Named |
| PG database | `autoerp_test_u` | `autoerp_test_v` | `autoerp_test_x` | `autoerp_test_y` |
| Terminal state | `status: review` | `status: review` | `status: review` | `status: review` |

The manifest arithmetic is correct at HEAD:

- Starting global ceiling: **1253**
- Additions: **14 + 5 + 3 + 2 = 24**
- Final global ceiling: **1277**
- Final groups: Inventory **146**, Replenishment **9**, Migrations **15**, Compliance **26**, Notification **3**
- All five affected feature lanes are parked.
- All 24 classes are assigned to the live PostgreSQL allowlist.

The S4-mobile packet now has a separate sibling-repository pin, branch/worktree, file, reviewer, verification, commit, and handback. Round-1 M8 is closed.

## Spec fidelity matrix

### Schema, events, endpoints, and cross-layer rules

| Accepted-spec area | Plan location | Result |
|---|---|---|
| §3.1 transfer counters, close columns, freight residual | S1 §7.3 | PRESENT |
| §3.1 receiving-controls columns | S2 §8.3 | PRESENT |
| §3.1b decimal casts and strings | S1 §7.12, S2 §8.4 | **INCOMPLETE** — reconciliation version and full transfer DTOs |
| §3.2 receipt header/line/lot tables, keys and CHECKs | S1 §7.3 | PRESENT |
| §3.3 enums and reason applicability | S1 §7.12 | PRESENT |
| §3.4 seven-state machine | S1 §7.4–§7.5 | PRESENT |
| §3.5 canonical remainder | S1 §7.7 | PRESENT |
| §3.6 I1–I7 | S1 services/tests | PRESENT |
| §3.6 I8 | S2 settings writer/tests | PRESENT |
| §4.0 storage mechanism and receipt aggregate | S1 §7.9 | PRESENT |
| Plain `StockTransferReceiptPosted` | S1 event, S4 listener | PRESENT |
| §4.1 `StockTransferReceivedV1` and `StockTransferClosedV1` | S1 §7.9 | PRESENT |
| §4.2 `StockTransferReceiptLineRecordedV1` | S1 §7.9 | PRESENT |
| §4.3 replay, atomicity, immutable legacy rows | S1 T15 | PRESENT |
| §4.4 pattern query and attribution | S1 §7.10/T16 | PRESENT |
| §4.5 `ReceivingControlsChangedV1`, atomic versions | S2 §8.7/T19/T19b | PRESENT |
| §5 middleware set | Existing Inventory route group retained | PRESENT |
| §5.0 surfaces 1–6 | S1/S2 | PRESENT except downstream DTO defects |
| §5.0 surfaces 7–11 | S3 | PRESENT; T17 preservation proof incomplete |
| §5.0 surface 12 reconciliation | S1 | **ALTERED** — missing `visibility_version` |
| §5.0 surface 13 notifications | S4 | PRESENT |
| §5.0 surface 14 generated TS | S4 | **BLOCKED** — three source DTOs absent |
| §5.0 surface 15 mobile | S4-mobile | PRESENT and separately dispatchable |
| §5.0 surface 16 POS cache/version | S4 | PRESENT |
| §5.0 surfaces 17–18 replenishment | S3 | PRESENT |
| §5.0 R5 surfaces 19–25 | S3 zero-file rule and controls | PRESENT |
| §5.0b exact response shapes | S1–S4 | PRESENT except missing reconciliation version/generated full entities |
| §5.1 receive algorithm | S1 §7.5 | PRESENT subject to incomplete DI graph |
| §5.1 errors: `VALIDATION_ERROR`, `LINE_NOT_ON_TRANSFER`, `OVER_RECEIPT`, `LOT_REQUIRED`, `LOT_NOT_ALLOWED`, `UNKNOWN_LOT`, `LOT_OVER_RECEIPT`, `LOT_SUM_MISMATCH`, `LOT_TRACKING_MISMATCH`, `DISCREPANCY_REASON_REQUIRED`, `DISCREPANCY_REASON_INVALID`, `NOTHING_TO_RECEIVE`, `IDEMPOTENCY_KEY_REUSED`, `VALUATION_MODE_UNSUPPORTED` | S1 requests/service/enum | PRESENT |
| §5.2 close rules and `DISPOSITION_REQUIRED` | S1 §7.5 | PRESENT subject to incomplete authorization call graph |
| §5.3 full/receiver builders | S1/S2 | **INCOMPLETE** — generated full entity DTO source absent |
| §5.4 reconciliation | S1 | **ALTERED** — missing version |
| §5.5 settings update/reset | S2 | PRESENT; `REFUND_EXPOSURE_KEYS` correction is right |
| §5.6 complete delegation, `BLIND_REQUIRES_COUNTED_RECEIPT`, `INVALID_TRANSFER_STATE` | S1/S2 | Semantically present, **not executable** with stated constructors |
| §5.7 aggregate contracts | S3 | PRESENT |
| §5.8 permissions, any-of gate, 403/404 | S1/S2 | PRESENT |
| §5.9 replenishment masking | S3 | PRESENT |
| §5.10 movement masking/location scope | S3 | PRESENT; non-blind scope regression missing |
| §6.1 movements and identities | S1 | PRESENT subject to writer dependency fix |
| §6.2 GL-buffer boundary | S1 | PRESENT; named red assertion defective |
| §6.3 return-to-source/no GL | S1 | PRESENT |
| §6.4 freight pool/allocation/residual | S1 | PRESENT |
| §7 notification types, recipients, no quantity/note | S4 | PRESENT; empty-payload scan needs liveness |
| §7 queue idempotency, context, timestamps, ordering | S4 | PRESENT, including PG race |
| §8 web split, pages, permissions, cache invalidation | S4 | **BLOCKED** by missing generated full DTOs |
| §8b POS nullability/version handling | S4 | PRESENT |
| §9 mobile contract | S4-mobile | PRESENT |

Precision and architecture contracts are otherwise preserved:

- Quantities remain scale-4 strings through `QuantityScale`.
- Money/cost scales and explicit currency resolution are specified.
- Arithmetic uses bcmath; no float or `(float)` path is authorized.
- Existing event classes remain untouched; the receipt events are new classes.
- Notification recipients are resolved before queueing.
- Queued work runs without `CompanyContext`.
- All new Inventory routes inherit the rule-12 middleware group.
- The T-1 buffer-only GL, freight, terminal-before-capitalisation, movement-identity, no-settlement-replay, and shared return-restock seams are carried forward.

### §10 tests

| Row | Result |
|---|---|
| T1 | PRESENT |
| T2 | PRESENT |
| T3 / T3-S1 / T3-S4 | PRESENT with closing S4 rerun |
| T4 | PRESENT |
| T4b | PRESENT; repaired r1 assertion ordering |
| T5 | PRESENT |
| T6 | PRESENT |
| T7 | PRESENT, PG |
| T7b | PRESENT, PG |
| T7c | PRESENT, PG |
| T8 | PRESENT |
| T9 / T9-S3 / T9-S4 | PRESENT with whole-class closing rerun |
| T9b | PRESENT |
| T10 | PRESENT, including sequential and concurrent PG delivery; payload-absence method lacks liveness |
| T11 | **INCOMPLETE** — no historical reader-total comparison |
| T12 | PRESENT as a source ratchet, but does not cure T11 |
| T13 | PRESENT semantically; first-red assertion invalid |
| T14 | PRESENT semantically; first-red assertion invalid |
| T15 | PRESENT |
| T16 | PRESENT |
| T17 | **INCOMPLETE** — restricted behavior covered, unrestricted output preservation absent |
| T18 | PRESENT as an eight-actor executable matrix |
| T19 / T19-S2 / T19-S3 | PRESENT for named T19 surfaces; reconciliation remains separately incomplete |
| T19b | PRESENT, PG |
| T20 | PRESENT except one second-company case is misclassified as red-first |

### S-matrix

| Writer | Result |
|---|---|
| receive | PRESENT |
| close `write_off` | PRESENT |
| close `return_to_source` | PRESENT |
| complete delegate | PRESENT subject to M1 call-graph correction |
| settings update | PRESENT |
| settings reset | PRESENT |
| settings initialisation | PRESENT |
| backfill | PRESENT for company/location/rerun; T11 reader preservation still missing |
| migrations | PRESENT, including non-vacuous PG catalog census |

### §11 rollout

| Step | Result |
|---|---|
| 1a–1e migrations and backfill | PRESENT |
| 2 readers in the same merge as backfill | PRESENT; required preservation test missing |
| 3 default off, no feature flag | PRESENT |
| 4 permissions and durable checklist | PRESENT, but the unowned old-checklist cross-reference must be removed or assigned |
| 5 realignment log | PRESENT in S4 |
| 6 glossary, generated types, permission map, POS types | **INCOMPLETE** — full generated transfer DTO sources absent |

## Slicing deviations against spec §11

The plan actually numbers **five** deviations, not four.

1. **Readers and backfill moved into S1:** permitted and required by §11.2’s same-merge rule.
2. **Receipt endpoints land before the setting:** permitted because the setting does not exist yet, defaults off when introduced, and activation waits until the complete union.
3. **T18 owned by S2:** permitted; S2 is the first slice able to execute the setting-on matrix.
4. **Entry/exit location scope grouped with S3 masking:** permitted; both changes share the controller and land before activation.
5. **T3/T9/T19 split into named partial contracts:** permitted because each earlier partial is independently falsifiable and the closing slice reruns the whole row.

None changes the required S1→S2→S3→S4 production order. The defects above are execution-detail failures, not reasons to change that rollout order.

## Round-1 closure register

### Findings

| r1 item | r2 status |
|---|---|
| B1 manifest/live gate | **CLOSED** — arithmetic, per-slice raises, all 24 allowlist entries and final 1277 are specified; raise-note wording has minor defect m1 |
| M1 reset constant | **CLOSED** — `REFUND_EXPOSURE_KEYS` and preservation assertions are explicit |
| M2 receive/close DTO split | **CLOSED** |
| M3 service seams | **NOT CLOSED** — extraction exists, but the final DI/delegation graph remains incomplete |
| M4 full DTO/enum text | **NOT CLOSED** — receiver/reconciliation bodies were added, but reconciliation omits version and three required full-transfer DTOs do not exist |
| M5 test ownership | **CLOSED** — T3, T9 and T19 partials plus closing reruns are explicit |
| M6 tests/red-first | **NOT CLOSED** — race and authority coverage were added, but multiple named first assertions still pass |
| M7 promotion checklist | **CLOSED** for the durable new checklist; the optional cross-reference is a new ledger defect |
| M8 mobile dispatch | **CLOSED** |
| m1 dirty-tree count | **CLOSED** — the two pre-existing untracked files are stated accurately |

### Every non-OK r1 executability row

| r1 row | r2 status |
|---|---|
| Exact files — S1 | **NOT CLOSED** |
| Exact files — S2 | **NOT CLOSED** |
| Exact files — S4/mobile | **CLOSED** for mobile; **NOT CLOSED** for missing generated DTO sources |
| Exact symbols — S1 | **NOT CLOSED** |
| Exact symbols — S2 | CLOSED |
| Full DTO/enum/event text — S1 | **NOT CLOSED** |
| Full DTO/enum/event text — S2 | **NOT CLOSED** through the omitted reconciliation delta |
| Red-first — S1 | **NOT CLOSED** |
| Red-first — S2 | CLOSED |
| Red-first — S3 | **NOT CLOSED** |
| Red-first — S4 | **NOT CLOSED** |
| Manifest updates — S1/S2/S3/S4 | CLOSED |
| PG-only notification race | CLOSED |

### Every non-OK r1 fidelity row

| r1 row | r2 status |
|---|---|
| §3.1b DTO/string contract | **NOT CLOSED** for new reasons |
| §3.3 complete enum text | CLOSED |
| §3.6 I8/reset | CLOSED |
| §4.5 controls event/reset/carriage ownership | CLOSED |
| §5.0 surfaces 1–6 | CLOSED for receipt/close omission; generated full DTO issue remains |
| §5.0 surface 13/T9 ownership | CLOSED |
| §5.0 surface 14/generated types | **NOT CLOSED** |
| §5.0 surface 15/mobile | CLOSED |
| §5.0b exact shapes | **NOT CLOSED** through reconciliation/generated entity contracts |
| §5.3 builder DTOs | **NOT CLOSED** |
| §5.4 reconciliation DTO | **NOT CLOSED** |
| §5.5 reset | CLOSED |
| §5.8/T18 proof | CLOSED |
| §6.1 movement seam | **NOT CLOSED** because the writer graph remains incomplete |
| §6.3 T4b red proof | CLOSED |
| §7 queue race | CLOSED |
| §9 mobile mechanics | CLOSED |
| T3 ownership | CLOSED |
| T4b first-red proof | CLOSED |
| T9 ownership | CLOSED |
| T10 concurrency | CLOSED; separate payload-liveness defect remains |
| T18 executable matrix | CLOSED |
| T19 ownership | CLOSED |
| §11 step 4 checklist | CLOSED; optional cross-reference must be ledgered or removed |
| §11 step 6 artifacts | **NOT CLOSED** |

## Preserve

- All five verified §0 errata.
- Every T-2/T-3 owner ruling as closed: D1, D2, OQ-1–OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4, and R5.
- R5’s zero-file rule for rows 19–25 and its positive-control oracle.
- The seven T-1 stock↔GL seams.
- Additive tenant migrations, named constraints, transactional PG backfill, clean reruns, and forward-only recovery.
- Decimal strings at column scale, `QuantityScale`, bcmath, explicit currency, and the float/cast bans.
- Existing-event immutability and new receipt event classes.
- Recipient resolution before queued work and operation without `CompanyContext`.
- T3/T9/T19 partial ownership with closing-slice whole-row reruns.
- Per-slice PG databases `u/v/x/y`, three reviewers per ERP slice, named handbacks, and `status: review`.
- The separate S4-mobile repository packet.
- The S1→S2→S3→S4 order and activation only after the complete union.

## Owner decisions required

None.

VERDICT: **CHANGES-REQUIRED**