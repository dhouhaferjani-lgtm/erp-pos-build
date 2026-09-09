# Adversarial gate result

Audited plan: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-3.md`

Audited HEAD: `5bd152035a941e0eecd0142ac40db1c768bb3efa`

Pin status: current. `git diff --name-only 364b1668b..HEAD | grep -v '^docs/'` is empty; the only intervening paths are the plan and its documentation prompt. The two pre-existing untracked files match the plan’s dirty-tree census. The sibling mobile repository is exactly at its stated pin `51e3445c1aa794f2a76d58c4771ecbbdca2f3f84`.

Audit mode: read-only. No edits, tests, migrations, or git writes were performed.

## Round-2 closure table

| Round-2 item | Rev-3 status | Evidence |
|---|---|---|
| B1 — missing generated transfer DTO producers | CLOSED | S1 now creates and reproduces `StockTransferData`, `StockTransferLineData`, and `StockTransferLineBatchAllocationData`; S2 appends the visibility members; generation and drift gates are assigned. Plan §7.12b, §8.6, §11.1, §11.5–§11.6. |
| M1 — incomplete receipt-writer call graph | **NOT CLOSED / REGRESSED** | The supposedly final class still declares `receiveAllRemaining(StockTransfer …)` at plan `:1152`, while `StockTransferService` and §7.5 require `receiveAllRemaining(string …)` at `:1200-1208,2038`; S2 then calls it with a loaded transfer at `:4441`. See BLOCKER B1. |
| M2 — reconciliation DTO missing `visibility_version` | CLOSED | S2 reproduces the post-S2 DTO, changes `TransferReconciliationService`, and supplies API plus generated-type assertions. |
| M3 — T11 historical-reader preservation | CLOSED | The pre-change baseline covers all four reader sites, both companies, both locations, lot/no-lot cases, and compares the unchanged historical answers byte-for-byte. The separate partial-receipt assertion proves the intended remainder change. Current code confirms completed rows are excluded before and after the switch. |
| M4 — unrestricted non-blind entry/exit preservation | CLOSED | S3 captures the pre-change unrestricted body and compares it byte-for-byte after adding location scope, excluding only the newly added masking flag. |
| M5 — passing assertions presented as red-first | CLOSED for the specific round-2 rows | The S1, S3, and named S4 rows are reordered or classified as controls. A separate S4 race-capture defect remains; see MAJOR M3. |
| M6 — non-exact source ledger | CLOSED arithmetically | The once-only partition now produces 159 single-push files, 13 contention files, 30 contention touches, 189 push rows, and 172 distinct ERP source files. Literal S4 locale paths remain non-exact; see MAJOR M1. |
| m1 — PG-only manifest wording | CLOSED | Each raise note names only the PG-only classes belonging to its feature-lane group. |

Round-2 non-OK executability rows:

| Row | Rev-3 status |
|---|---|
| Exact files — S1, S2 | CLOSED |
| Exact files — S4 | **NOT CLOSED**: five French locale paths use `.../fr/...` aliases. |
| Exact symbols/call graph — S1, S2 | **NOT CLOSED**: contradictory `/complete` delegate signatures and caller ownership. |
| Exact symbols/call graph — S4 | CLOSED |
| Full migration/enum/event/DTO text — S1/S2/S4 | CLOSED |
| Red-first evidence — S1/S3 | CLOSED |
| Red-first evidence — S4 | **NOT CLOSED** for the same-id notification race. |
| PG-only classes and private DB letters | CLOSED |
| Convention-09 evidence — S1/S2/S3/S4 | CLOSED |
| Required reviewers, handbacks, terminal `status: review` | CLOSED |

Round-2 non-PRESENT fidelity rows:

| Spec rows | Rev-3 status |
|---|---|
| §3.1b precision/model contract | CLOSED |
| §5.0 rows 1–6, 7–11, 12, 14; §5.0b response shapes | CLOSED |
| §5.1–§5.4 | CLOSED |
| §5.6 `/complete` | **NOT CLOSED** because its final executable signature and caller are contradictory. |
| §5.10, §6.1, §6.2, §7, §8 | CLOSED at contract-coverage level |
| T10 | PRESENT, but its race test lacks executable first-red evidence; see MAJOR M3. |
| T11, T13, T14, T17, T19, T20 | CLOSED |
| S-matrix `complete` and `backfill` rows | CLOSED |
| §11 rollout steps 2, 4, 6 | CLOSED for content and ordering; the deployment-variable handoff still fails the manifest’s required table shape. |

## Citation audit

A read-only parser resolved every numeric repo-relative or absolute `path:line` occurrence against the ERP repository and the pinned mobile repository:

- Citation occurrences checked: **476**
- Distinct path/line anchors checked: **291**
- Missing paths: **0**
- Out-of-range lines or ranges: **0**
- Production drift from `364b1668b`: **0 paths**
- Citation-backed claim clusters whose cited material is overextended: **1**

The overextension is plan `:5324-5350`: `StockTransferCompleteConcurrencyPostgresTest.php` supports the proposed subprocess/committed-fixture harness, but it cannot support the claimed untouched-tree failure mode for `TransferNotificationRaceTest`. At the start of S4, none of the proposed transfer notification classes exists, so the race cannot reach the cited duplicate-key failure without an additional test-local notification fixture or an explicitly staged capture.

Two other defects below are internal contradictions rather than unresolved citations: the two `receiveAllRemaining` signatures, and “unsigned integer” at plan `:4226`.

The five §0 errata were checked against the accepted spec tables and are correct:

- E1: ten R5 surface families, table rows 19–25, and twelve R5 response-shape rows.
- E2: POS device types correspond to surface rows 8–9.
- E3: the R5 row range is 19–25.
- E4: the rev-11 changelog overstates one earlier closure.
- E5: rows 7–9 are mixed transfer/stock-position surfaces.

## BLOCKER

### B1 — `/complete` has two incompatible final signatures and two incompatible controller call graphs

**Plan**

- `StockTransferReceiptService` is reproduced as final with  
  `receiveAllRemaining(StockTransfer $transfer, string $userId, string $idempotencyKey)` at plan `:1125-1153`.
- The very next final forwarder calls it with a string transfer id, and the prose explicitly changes the final signature to  
  `receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey)` at `:1197-1208`.
- S1 rule 9 repeats the string signature at `:2038`.
- S2 instructs `StockTransferController::complete` to call  
  `StockTransferReceiptService::receiveAllRemaining($transfer, $user, …)` at `:4441`, contradicting both the string signature and the §6.7b ownership decision that `StockTransferService::complete()` is the published forwarder.
- The plan declares round-2 M1 closed at `:47`, but these mutually exclusive instructions remain.

**Source**

Accepted spec §5.6 keeps the non-blind `/complete` contract and requires it to delegate to the one receipt writer. Convention 11 requires one write path, not two competing controller/service paths. Plan §6.7b itself assigns transaction, header lock, advisory lock, and response ownership once.

**Failure scenario**

An S1 implementer copying the reproduced class receives a PHP `TypeError` when `StockTransferService::complete()` passes a string into the `StockTransfer` parameter. If the implementer instead follows the prose signature, the S2 instruction passes a model where a string is required and bypasses the declared `StockTransferService::complete()` forwarder. There is no single compilable final call graph for a core endpoint.

**Minimum correction**

Make every occurrence identical:

```php
public function receiveAllRemaining(
    string $transferId,
    string $userId,
    string $idempotencyKey,
): TransferReceiptResult
```

Then state one controller path only: after permission, destination-access, and blind checks, `StockTransferController::complete()` calls the existing published `StockTransferService::complete($transfer->id, $user->id)` forwarder; that forwarder invokes `receiveAllRemaining($transferId, …)` and returns its `StockTransfer`. Remove the direct receipt-service call at plan `:4441` and regenerate the S1/S2 call-graph tables and tests from that one decision.

**Slice**

S1 and S2.

## MAJOR

### M1 — S4’s “exact file” inventory contains five non-path ellipses

**Plan**

Plan `:5104-5108` lists:

- `apps/web/src/locales/en/stock-transfers.json`, `.../fr/stock-transfers.json`
- The same abbreviated French path for `inventory`, `notifications`, `compliance`, and `replenishment`.

The ledger later counts ten web locale files, but the five `.../fr/...` strings are not repo-relative paths.

**Source**

The dispatch gate requires exact new/modified files per slice. The plan’s own §11 says its ledger is an exact once-only file ledger.

**Failure scenario**

A literal dispatch inventory, file ownership checker, or handback comparison cannot resolve those five strings. The 172-file arithmetic can be reconstructed by human inference, but the source of truth is not machine-exact.

**Minimum correction**

Expand each French locale to its full path as its own bullet:

`apps/web/src/locales/fr/{stock-transfers,inventory,notifications,compliance,replenishment}.json`

Re-run the once-only parser against the literal inventories and ledger with no alias normalization.

**Slice**

S4.

### M2 — §12 is a combined lane table, not the manifest’s exact per-slice variable shape

**Plan**

Plan `:5608-5622` supplies one combined T-2/T-3 table. It:

- Replaces the required **Collapsed pushes** row with **Push count** at `:5619`.
- Adds **Mobile repository**, **Backups**, and **Rollback** inside the variables table.
- Combines all five migrations and all commands rather than supplying values per S1–S4.

**Source**

`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:291-308` says to supply exactly ten per-slice variables, including a row named **Collapsed pushes**.

**Failure scenario**

A dispatched S2, S3, or S4 handoff cannot instantiate the deployment manifest from its own table: the migration, command, census, and collapse decisions are mixed with other slices. A promotion operator must reinterpret the combined prose rather than substitute the manifest’s defined variables.

**Minimum correction**

Provide four tables, one each for S1–S4, with exactly:

`<slice>`, Migrations list, Flags, Commands, Censuses, Web changes, Device build, Queues, Collapsed pushes, Env path.

Put mobile repository, backup, and rollback notes outside those tables. Preserve the existing conclusion that activation is a per-company setting action after the complete union.

**Slice**

Cross-slice deployment handoff; principally §12.

### M3 — The S4 same-id notification race cannot produce its stated first failing assertion at the required capture point

**Plan**

- Plan `:5350` says the untouched pre-channel run reaches the second worker, hits a duplicate primary-key exception, and first fails  
  `self::assertSame(0, $contender->wait(), …)`.
- Plan `:5384` classifies that exact failure as RED-FIRST.
- Plan `:5411` requires all red assertions to be captured before adding the two notification classes, channel, or listeners.
- No `TransferInitiatedNotification`, discrepancy notification, close notification, or `IdempotentDatabaseChannel` exists at current HEAD.

**Source**

Spec T10 requires a real two-worker same-id race with one row and no exception. CLAUDE.md rule 2 and the dispatch gate require the exact first failure against the slice’s starting tree.

**Failure scenario**

The test must instantiate or deserialize a notification before the child can call the database channel. With the production notification class absent, execution fails during setup/autoload before the subprocess exit assertion. Consequently the promised duplicate-key stderr and first failing assertion cannot be captured in step 1, and the handback instruction requesting that stderr is not executable as written.

**Minimum correction**

Make the race test self-contained at first-red time: define a test-local deterministic-ID database notification fixture which uses Laravel’s existing `DatabaseChannel`, run the two committed-fixture processes against that fixture, and identify the exact contender-exit assertion that fails with `SQLSTATE[23505]`. After adding `IdempotentDatabaseChannel`, route both the test fixture and production notifications through it and retain a production-notification integration assertion.

An alternative is an explicitly documented two-stage red capture, but the first stage must name its actual autoload/setup failure and the second must be captured after adding the notification class but before adding the channel.

**Slice**

S4.

## MINOR

### m1 — `visibility_version` is incorrectly described as unsigned

**Plan**

Plan `:4226` says `visibility_version` matches an “unsigned integer” column.

**Source**

The accepted spec defines `integer NOT NULL DEFAULT 1` at spec `:105`. The plan’s reproduced migration correctly uses `$table->integer('visibility_version')->default(1)` at plan `:3835-3838`.

**Failure scenario**

An implementer following the prose could change the migration to an unsigned type, creating needless schema divergence between PostgreSQL and the accepted contract.

**Minimum correction**

Replace “unsigned integer” with “integer”; do not change the reproduced migration.

**Slice**

S2.

## Slice executability

| Slice | Result |
|---|---|
| S1 | Exact inventory, full migration/enum/event/DTO text, red-first commands, manifest groups, convention-09 matrix, `autoerp_test_u`, three reviewers, handback, and `status: review` are present. **Not dispatchable because the final receipt-service signature conflicts with its forwarder.** |
| S2 | Exact files, full DTO/event/migration text, T18 matrix, `autoerp_test_v`, three unique reviewers, handback, and terminal state are present. **Not dispatchable because its controller instruction restores the incompatible loaded-transfer call.** |
| S3 | Exact files and symbols, T9/T17/T20 red/control classification, both-lane commands, `autoerp_test_x`, convention-09 evidence, three reviewers, handback, and terminal state are present. Dispatch structure is otherwise executable. |
| S4 | Reviewer set, `autoerp_test_y`, web/POS commands, T3/T9 closing reruns, handback, and terminal state are present. **Not dispatchable because five locale paths are non-exact and the same-id race’s first-red capture is unreachable.** |
| S4-mobile | Separate repository, exact one-file scope, pin, reviewer, handback, verification, and `status: review` are present. It correctly does not pretend to implement the M-1 screen. |

Feature-lane groups are assigned coherently: S1 Inventory/Replenishment/Migrations, S2 Inventory/Compliance, S3 Inventory, S4 Notification, with the corresponding `gated_ceiling` increments and CI allowlist edits.

## Source-ledger verification

A read-only set parser verified the ledger after resolving the five intended locale aliases:

- Single-push source files: **159**
- Multi-push files: **13**
- Multi-push touches: **30**
- Total push rows: **189**
- Distinct ERP source files: **172**
- Duplicate membership between the single-push set and contention register: **0**
- Generated artifacts outside the source partition: **2**
- Sibling mobile files outside the ERP ledger: **1**

The thirteen contention files and their 30 touches are internally consistent. The generated-type drift gates cited at `scripts/preflight.sh:110-155` and `.github/workflows/ci.yml:2683-2714` exist. The arithmetic should be preserved; MAJOR M1 concerns literal path exactness, not the intended totals.

## Spec-fidelity table

### Schema, events, API, masking, movements, and clients

| Spec item | Plan implementation | Status |
|---|---|---|
| §3.1 line/allocation receipt counters and CHECKs | S1 §7.3, migration `2026_09_09_100000_add_receipt_counters_to_transfer_lines.php`; `StockTransferLine`, `StockTransferLineBatchAllocation` | PRESENT |
| §3.1 transfer close/freight columns | S1 §7.3, migration `…100200_add_close_columns_to_stock_transfers.php`; `StockTransfer` | PRESENT |
| §3.1 fraud settings columns | S2 §8.3, migration `…110000_add_receiving_controls_to_company_fraud_settings.php`; `CompanyFraudSettings` surfaces | PRESENT, with minor “unsigned” prose error |
| §3.1b decimal casts/string contract | S1 §7.4 and S2 §8.4; decimal casts, `QuantityScale`, bcmath, PHPStan/ESLint guards | PRESENT |
| §3.2 three receipt tables, keys, indexes, CHECKs | S1 §7.3, migration `…100100_create_stock_transfer_receipt_tables.php`; three receipt models | PRESENT |
| §3.3 enums and `TransferStatus` expansion | S1 §7.4; six new enum files plus `TransferStatus` | PRESENT |
| §3.4 state machine | S1 §7.5; `StockTransferReceiptService`, model helpers, status predicates | PRESENT except `/complete` executable signature |
| §3.5 remainder readers | S1 §7.7; `LocationStockQueryService`, `StockMatrixQueryService`, `WeightedAverageCostService`, `REMAINDER_SQL`, `CARRYING_STATUSES` | PRESENT |
| §3.6 I1–I7 | S1 migrations, writer, readers, freight, replay/reconciliation tests | PRESENT |
| §3.6 I8 | S2 §8.7; settings initialization, atomic bump, event/version tests | PRESENT |
| §4.0 persistence/aggregate anchor | S1 §7.9; `StoredEventRepository::persist(..., $receiptId)` and aggregate versions | PRESENT |
| §4.1 header events | S1 §7.9; `StockTransferReceivedV1`, `StockTransferClosedV1` reproduced in full | PRESENT |
| §4.2 line event | S1 §7.9; `StockTransferReceiptLineRecordedV1` reproduced in full | PRESENT |
| §4.3 replay and atomicity | S1 §7.9–§7.10; `TransferReceiptEventReplayTest` | PRESENT |
| §4.4 pattern-detection query/attribution | S1 §7.10; query text and `TransferReceiptPatternQueryTest` | PRESENT |
| §4.5 visibility event/version semantics | S2 §8.7; `ReceivingControlsChangedV1`, row lock and atomic `RETURNING` update | PRESENT |
| §5 preamble: middleware and typed errors | S1 §7.5–§7.6; routes remain inside the rule-12 Inventory group | PRESENT |
| §5.0 rows 1–6 | S2 §8.6; receiver/full builders, receive/complete/close/error response selection | PRESENT except row 4 `/complete` call graph |
| §5.0 rows 7–9 | S3 §9.3; stock matrix and the two POS incoming feeds | PRESENT |
| §5.0 rows 10–11 | S3 §9.5; stock movement and entry/exit masking plus location scope | PRESENT |
| §5.0 row 12 | S1 reconciliation service/DTO; S2 appends current visibility version | PRESENT |
| §5.0 row 13 | S4 §10.3; three notification types with quantity/note-free payloads | PRESENT |
| §5.0 row 14 | S1/S2 generated DTOs; S4 discriminated web union | PRESENT |
| §5.0 row 15 | S4-mobile exact contract packet | PRESENT as a contract commitment |
| §5.0 row 16 | S4 §10.7 POS cache/version changes | PRESENT |
| §5.0 rows 17–18 | S3 server masking; S4 POS device types/cache handling | PRESENT |
| §5.0 rows 19–25 | S3 §9.2 zero-file rule and T9 R5 presence controls | PRESENT and correctly unchanged |
| §5.0b all transfer-derived response shapes | S1/S2/S3/S4 DTO, builder, masking, notification and client sections | PRESENT |
| §5.0b twelve R5 shapes | S3 oracle steps 6p–6u; no production edits to stock-position emitters | PRESENT |
| §5.1 receive rules A1–A6/B1–B10 and error codes | S1 §7.5, requests, canonicalizer, service, T1–T8 | PRESENT |
| §5.2 close rules/authority/errors | S1 §7.5–§7.6, two chained permissions, location checks, close tests | PRESENT |
| §5.3 visibility/builders/receipt DTOs | S1 full DTOs and S2 visibility/builders | PRESENT |
| §5.4 reconciliation endpoint | S1 service/controller/DTO; S2 visibility version | PRESENT |
| §5.5 company setting | S2 migration/model/controller/service/event | PRESENT |
| §5.6 quantity-less complete | S1/S2 | **ALTERED/AMBIGUOUS** by BLOCKER B1 |
| §5.7 incoming aggregates | S3 query/service/controller changes | PRESENT |
| §5.8 permission/read gates | S1 routes and S2 T18; three-permission any-of and separate close permission | PRESENT |
| §5.9 replenishment masking | S3 resource/controller changes; web-meta/POS-top-level distinction | PRESENT |
| §5.10 movement masking | S3 two controllers, carrying-transfer linkage, terminal/non-transfer controls | PRESENT |
| §6.1 movements | S1 `StockTransferMovementSupport` and receipt writer through `StockAdjustmentService` | PRESENT |
| §6.2 GL bridge | S1 `InventoryGlPostingBuffer`, preflight and boundary tests | PRESENT |
| §6.3 return-to-source | S1 no-GL return flow and T4b | PRESENT |
| §6.4 freight | S1 landed-weight allocation and `freight_uncapitalized`; no freight journal | PRESENT |
| §7 notifications | S4 notifications, listeners, custom channel and provider wiring | PRESENT; race test execution evidence defective |
| §8 web | S4 generated types, receiver union, route guards, receive/close/reconciliation UI and cache gate | PRESENT |
| §8b POS | S4 nullable transfer-incoming types, normalization, cache clearing, masked rendering | PRESENT |
| §9 mobile | Separate S4-mobile one-file commitment packet | PRESENT; no screen is incorrectly claimed |

### Test matrix

| Spec row | Plan slice, file/class | Status |
|---|---|---|
| T1 | S1 `StockTransferReceiveTest` | PRESENT |
| T2 | S1 `StockTransferReceiveTest` | PRESENT |
| T3 | S1 `StockTransferReceiveDamageTest`; S4 `TransferNotificationTest`; whole-row rerun in S4 | PRESENT |
| T4 | S1 `StockTransferCloseTest` | PRESENT |
| T4b | S1 `StockTransferCloseTest` | PRESENT |
| T5 | S1 `StockTransferReceiveValidationTest` | PRESENT |
| T6 | S1 `StockTransferReceiveLotsTest` | PRESENT |
| T7 | S1 `StockTransferReceiveConcurrencyPostgresTest` | PRESENT |
| T7b | S1 same PG class, forced receive/close orderings | PRESENT |
| T7c | S1 same PG class, forced receive/complete orderings | PRESENT |
| T8 | S1 `StockTransferReceiveTest` plus precision gates | PRESENT |
| T9 | S3 `TransferBlindLeakOracleTest` steps through 6n/6p–6u; S4 adds 6o and reruns whole class | PRESENT |
| T9b | S2 `TransferReceiverPayloadBuilderKeyScanTest` | PRESENT |
| T10 | S4 `TransferNotificationTest`, `TransferNotificationRaceTest`, web notification test | PRESENT contract; race first-red capture NOT EXECUTABLE |
| T11 | S1 `TransferLegacyCompletionBackfillTest` plus committed two-lane baseline | PRESENT |
| T12 | S1 `TransferInTransitReadersUseRemainderTest` | PRESENT |
| T13 | S1 GL boundary/leak tests | PRESENT |
| T14 | S1 replenishment settlement regression | PRESENT |
| T15 | S1 receipt event persistence/replay tests | PRESENT |
| T16 | S1 pattern-query test | PRESENT |
| T17 | S3 `EntryExitNoteLocationScopeTest` with pre-change baseline | PRESENT |
| T18 | S2 `TransferAuthorityMatrixTest` | PRESENT |
| T19 | S2 visibility/version tests; S3 carriage assertions and whole-row reruns | PRESENT |
| T19b | S2 `ReceivingControlsConcurrencyPostgresTest` | PRESENT |
| T20 | S3 `TransferMovementMaskingTest` | PRESENT |
| S-matrix receive/close/complete/backfill/migrations | S1 §7.13 | PRESENT |
| S-matrix settings | S2 convention-09 section and `ReceivingControlsSecondOfEverythingTest` | PRESENT |
| S-matrix incoming/replenishment/movement/scope | S3 §9.8 | PRESENT |
| S4 second-company/location/idempotency evidence | Notification recipient/race, cache-key, mixed-row, and POS tests | PRESENT |

### Rollout order and stated deviations

| Item | Judgment against spec §11 |
|---|---|
| Deviation 1 — readers and backfill moved into S1 | VALID and required: spec §11.2 requires them in the same merge, and the receipt writer introduces `partially_received` in S1. |
| Deviation 2 — S1 ships setting-off behavior before S2 visibility | VALID: `blind_receiving` defaults off, S1 uses the final full-builder behavior for that state, and activation waits for the complete union. |
| Deviation 3 — whole T18 assigned to S2 | VALID: S1 carries a local route-gate test; the accepted T18 matrix needs the S2 setting and receiver builder. |
| Deviation 4 — entry/exit scope fix and masking co-located in S3 | VALID: both touch the same controller and no earlier rollout step depends on the scope fix. |
| “Deviation 5” in the plan | This is a test-ownership split, not a fifth rollout relocation. T3, T9, and T19 keep named partial contracts and are closed by whole-row reruns in their final dependency slice. VALID. |
| §11.1 migrations | 1a–1c/1e S1; 1d S2. PRESENT and activation-safe. |
| §11.2 readers with backfill | Both S1. PRESENT. |
| §11.3 default-off/no environment flag | PRESENT. |
| §11.4 permission seeding/checklist | S1. PRESENT. |
| §11.5 realignment log | One consolidated S4 edit. PRESENT. |
| §11.6 glossary/generated/permission/POS artifacts | Distributed to the slices that change their inputs, with final activation after S4. PRESENT; generated artifacts correctly follow their CI drift gates. |

## Rejected false positives

- The prompt refers to four slicing deviations because only deviations 1–4 relocate rollout work. The plan’s separately numbered deviation 5 splits test ownership and does not reorder production.
- T11’s completed-transfer baseline is valid. All four current reader sites exclude completed rows, and `CARRYING_STATUSES` will continue to exclude them; the partial-receipt fixture separately proves the intended answer changes from sent quantity to remainder.
- R5 on-hand, available, rebalance, counting-reconciliation, and lot-stock visibility is not a leak defect. Rows 19–25 correctly contribute zero production files and are guarded by positive controls.
- The mobile screen is not missing from this plan. Spec §9 is a contract commitment; M-1 owns the screen.
- PO blind receiving and a PO receiver-view endpoint remain correctly deferred to T-3b.
- The S1/S4 response-building split does not require the receipt service to emit wire DTOs. The controller is correctly designated as the sole response owner; only the contradictory `/complete` calls must be fixed.
- Existing transfer events remain untouched. The new stored receipt events and the plain after-commit notification event satisfy rule 8.
- Default-queue notification listeners need no Horizon queue addition. Recipient resolution is performed before queueing, and the worker contract explicitly clears `CompanyContext`.
- The 172-file ledger total is not arithmetically wrong. The defect is the five literal locale aliases, not the intended membership or contention counts.
- The T-1 freight ticket does not authorize adding a GL leg here. This plan correctly preserves freight capitalization without a freight journal and posts GL only for damage/write-off.

## Preserve

- All five verified §0 errata.
- All ruled owner decisions: D1, D2, OQ-1 through OQ-4, pattern detection, freight, receiver/closer attribution, OD-1, close authority, OD-4, and R5.
- The seven T-1 seams: zero-only GL buffer boundary, no freight GL, terminal status before cost capitalization, transit outside stock/GL ledgers, movement identity/event distinctions, initiate-only replenishment settlement, and reuse of the source-restock path.
- Additive, guarded tenant migrations; named constraints/indexes; PostgreSQL transactional recovery; SQLite clean-rerun scope; forward-only post-commit recovery.
- Decimal strings at column scale, `QuantityScale`, bcmath, explicit currency for scale resolution, no float arithmetic, no `(float)` casts, and no `parseFloat`/`Number(...)` on quantity or money.
- Existing-event immutability and the three new stored receipt-event classes.
- One receipt writer and one controller response owner once BLOCKER B1 is repaired.
- Receiver projection omission by construction, static quantity-free errors, and `details: []`.
- R5 zero-file production rule plus executable presence controls.
- T3/T9/T19 partial contracts with whole-row closing reruns.
- Per-slice PostgreSQL databases `u`, `v`, `x`, `y`; named reviewer sets; handbacks; and terminal `status: review`.
- Separate S4-mobile repository packet and one-file ledger.
- S1 → S2 → S3 → S4 merge order, with blind activation only after the complete union.
- The 159/13/30/189/172 ledger arithmetic and both generated-artifact drift gates.

## Owner decisions required

None. No T-2/T-3 owner ruling is open.

VERDICT: CHANGES-REQUIRED