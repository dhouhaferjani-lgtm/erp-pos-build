# Adversarial gate result

Audited plan: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-6.md`

Audited HEAD: `98f794f0349f88122b88f1ca9d0d9aecd3686234`

Pin status: current. `git diff --name-only f9f6f4480..HEAD | grep -v '^docs/'` is empty; the two intervening files are the rev-6 plan and its documentation prompt. The two unrelated untracked files match the plan’s census.

Audit mode: read-only. No files were edited, no tests were run, and no git writes were performed.

Citation audit:

- Absolute/repo-relative `path:line` occurrences checked: **505**
- Missing paths: **0**
- Out-of-range lines/ranges: **0**
- Citation-content mismatches: **0**
- Production drift from `f9f6f4480`: **0 paths**
- Rev 5 and rev 6 contain the exact same 505-citation multiset.

The five §0 errata remain correct against the accepted spec’s tables: ten R5 families, seven dedicated surface rows 19–25, twelve R5 response-shape rows; POS device types correspond to rows 8–9; every R5 range extends through row 25; the rev-11 change-log claim is overstated; and the mixed matrix/POS surfaces are rows 7–9.

## Round-5 closure table

| Round-5 item | Rev-6 status | Evidence |
|---|---|---|
| M1 — embedded promotion checklist names obsolete authority | **CLOSED** | The generated checklist names rev 6 at plan `:3938`. |
| M1 — ERP dispatch packets name obsolete authority | **CLOSED** | S1 declares revs 1–5 superseded and rev 6 authoritative at `:6319`; S2–S4 inherit §17.1 at `:6323,6327,6331`. |
| M1 — mobile packet names obsolete authority | **CLOSED** | The absolute authority path names rev 6 at `:6357`. |
| m1 — second “23” class-count statement | **CLOSED** | Plan `:793,814-840` consistently derives 24 Feature classes and explains “24 and not 26.” |
| m2 — provenance attributed per gate | **PARTIALLY CLOSED** | The operative rev-6 pin is correct, but four historical provenance statements remain inconsistent. See MINOR m1. |
| m3 — old checklist ownership contradiction | **CLOSED** | Plan `:3929` now says the old checklist receives only the S1 successor pointer and appears exactly once in the S1 inventory and Push-1 ledger. |
| Round-4 race closure survives dispatch | **CLOSED** | Every packet reaches the corrected rev-6 body; the child explicitly requires the test file before constructing `RaceProbeNotification` at `:5619`, and the reachable first failing assertion is specified at `:5651`. |
| Rounds 1–4 closures and gate-r5 Preserve list | **NO CONTRACT REGRESSION** | Rev 6 otherwise preserves the schema, DTO, event, reader, masking, GL, rollout, test, ledger and dispatch contracts. |

The requested superseded-plan-path sweep is clean: `plan-rev-[1-5].md` occurs only in the header supersedes sentence and the historical §0C change log. No operative packet, embedded checklist `Plan:` line, or authority path names a superseded plan.

## BLOCKER

None.

## MAJOR

None.

## MINOR

### m1 — four provenance statements still mix the rev-4, rev-5 and rev-6 pins

**Plan**

- The §0A closure row at `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-6.md:55` attributes `86ab1dc58..HEAD` both to gate r5 and this revision’s recheck.
- Section §1 at `:363` instead says gate r5 audited at the later `f9f6f4480` pin and ran `f9f6f4480..HEAD`.
- The S4 inventory note at `:5406` calls `f9f6f4480` the “rev-4 pin.”
- The final verification checklist at `:6446` calls `f9f6f4480` the “Rev-4 planning SHA” but pairs it with `e734ee872..HEAD`.

**Source**

Gate r3 audited HEAD `5bd1520` using `364b1668b..HEAD` at `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r3.md:3-7`. Gate r4 audited HEAD `c1878add` using `e734ee872..HEAD` at `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r4.md:3-7`. Gate r5 audited HEAD `6906b4f9` using the rev-5 planning pin `86ab1dc58..HEAD` at `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r5.md:3-7`. Rev 6 alone uses planning pin `f9f6f4480`.

**Failure scenario**

The live dispatch comparison at plan `:391` is correct, so implementation is not blocked. A later documentation audit following the historical prose, however, cannot reproduce which gate checked which ancestry range, and the final checklist reports a nonexistent “Rev-4 planning SHA” association.

**Minimum correction**

- At `:55`, attribute `86ab1dc58..HEAD` to gate r5 and `f9f6f4480..HEAD` to rev 6’s recheck.
- At `:363`, state that gate r5 audited HEAD `6906b4f9` against planning pin `86ab1dc58`; keep the rev-6 `f9f6f4480` check separate.
- At `:5406`, say “rev-6 pin `f9f6f4480`.”
- At `:6446`, say “Rev-6 planning SHA `f9f6f4480…`” and use `f9f6f4480..HEAD`.

**Slice**

Cross-slice, documentation/checklist only.

## Spec-fidelity table

### Schema, events, API, masking, movement/GL and clients

| Spec item | Plan anchor | Result |
|---|---|---|
| §3.1 line/allocation counters and CHECKs | S1 §7.3, migrations `2026_09_09_100000…` | OK |
| §3.1 transfer close/freight columns | S1 §7.3, migration `…100200_add_close_columns_to_stock_transfers.php` | OK |
| §3.1 fraud-setting columns | S2 §8.3, migration `…110000_add_receiving_controls_to_company_fraud_settings.php` | OK |
| §3.1 migration guards/recovery | S1 §7.3–§7.4; S2 §8.3; PG rollback/retry and clean-rerun cases | OK |
| §3.1b decimal casts/string contract | S1 §7.4/§7.12; S2 §8.4; `QuantityScale`, bcmath and static guards | OK |
| §3.2 receipt header, line and lot tables | S1 §7.3; complete migration bodies, constraints and indexes | OK |
| §3.3 six new enums and expanded `TransferStatus` | S1 §7.12 | OK |
| §3.4 state machine | S1 §6.7b and §7.5 | OK |
| §3.5 three remainder readers/four query sites | S1 §7.7; `REMAINDER_SQL` and `CARRYING_STATUSES` | OK |
| §3.6 I1–I7 | S1 schema, writer, movement/GL, freight and replay tasks | OK |
| §3.6 I8 | S2 §8.7; initialization and atomic version bump | OK |
| §4.0 receipt-id aggregate anchor | S1 §7.9; `persist($event, $receiptId)` | OK |
| §4.1 header events | S1 §7.9; `StockTransferReceivedV1`, `StockTransferClosedV1` | OK |
| §4.2 per-line event | S1 §7.9; `StockTransferReceiptLineRecordedV1` | OK |
| §4.3 replay, atomicity, immutable existing events | S1 §6.4/§7.9 and T15 | OK |
| §4.4 pattern query and attribution | S1 §7.10 and T16 | OK |
| §4.5 settings event/version | S2 §8.7 and T19/T19b | OK |
| §5 preamble middleware/errors | S1 §7.5–§7.6; every route remains inside the rule-12 Inventory middleware group | OK |
| §5.0 rows 1–6 | S1/S2 controller builders, receive/complete/close responses and typed errors | OK |
| §5.0 rows 7–9 | S3 §9.3 matrix and POS feeds | OK |
| §5.0 rows 10–11 / §5.10 | S3 §9.5 movement and entry/exit masking plus location scope | OK |
| §5.0 row 12 | S1 reconciliation; S2 appends `visibility_version` | OK |
| §5.0 row 13 / §7 | S4 §10.3/§10.10 notifications and race | OK |
| §5.0 row 14 | S1/S2 generated DTOs; S4 discriminated union | OK |
| §5.0 row 15 / §9 | S4-mobile §17.5 | OK |
| §5.0 row 16 | S4 §10.7 POS version/cache contract | OK |
| §5.0 rows 17–18 / §5.9 | S3 §9.4 replenishment masking; S4 clients | OK |
| §5.0 rows 19–25 | S3 §9.2 zero-file rule and R5 controls | OK |
| Twelve §5.0b R5 shapes | S3 §9.6–§9.7 | OK |
| §5.1 A1–A6, B1–B10 and named codes | S1 §7.5; canonicalizer, FormRequests, service validation and exact error bodies | OK |
| §5.2 close authority/dispositions/errors | S1 §7.5–§7.6; both permissions and source-location checks | OK |
| §5.3 visibility/builders/receipt DTOs | S1 full builder; S2 receiver builder and visibility services | OK |
| §5.4 reconciliation | S1 service/controller/DTO; S2 version delta | OK |
| §5.5 company setting | S2 migration/model/controller/service/event | OK |
| §5.6 `/complete` delegation | S1 single forwarder; S2 blind gate | OK |
| §5.7 incoming aggregates | S3 §9.3 | OK |
| §5.8 permission/route matrix | S1 §7.6; S2 T18 | OK |
| §6.1 movements | S1 §7.8 through `StockAdjustmentService` | OK |
| §6.2 sole GL bridge | S1 §7.8; `InventoryGlPostingBuffer`, T13 and PHPStan boundary | OK |
| §6.3 return to source | S1 shared source-restock path and T4b | OK |
| §6.4 freight | S1 §7.11; landed weighting, persisted residual and no freight journal | OK |
| §7 notification payloads, recipients, idempotency and race | S4 §10.3/§10.10; explicit no-`CompanyContext` worker and two-process PG race | OK |
| §8 web | S4 §10.4–§10.6 | OK |
| §8b POS | S4 §10.7 | OK |
| §9 mobile | S4-mobile contract packet; screen remains M-1 | OK |

### Test matrix

| Spec row | Plan anchor | Result |
|---|---|---|
| T1 | S1 `StockTransferReceiveTest` | OK |
| T2 | S1 `StockTransferReceiveTest` | OK |
| T3 | S1 movement/GL half; S4 notification half and closing rerun | OK |
| T4 | S1 `StockTransferCloseTest` | OK |
| T4b | S1 return-to-source case | OK |
| T5 | S1 `StockTransferReceiveValidationTest` | OK |
| T6 | S1 `StockTransferReceiveLotsTest` | OK |
| T7 | S1 PG concurrency class | OK |
| T7b | Same S1 PG class, receive/close orderings | OK |
| T7c | Same S1 PG class, receive/complete orderings | OK |
| T8 | S1 precision/wire case and static guards | OK |
| T9 | S3 oracle; S4 step 6o and whole-class rerun | OK |
| T9b | S2 receiver-payload key scan and liveness case | OK |
| T10 | S4 notification, two-worker race and web tests | OK |
| T11 | S1 completed-row backfill and committed reader baseline | OK |
| T12 | S1 remainder-reader architecture ratchet with liveness fixture | OK |
| T13 | S1 GL boundary/leak tests | OK |
| T14 | S1 replenishment-settlement regression | OK |
| T15 | S1 event persistence/replay | OK |
| T16 | S1 pattern query | OK |
| T17 | S3 scope test and unrestricted non-blind byte-preservation baseline | OK |
| T18 | S2 eight-actor/six-route authority matrix | OK |
| T19 | S2 version tests; S3 carriage assertions and closing reruns | OK |
| T19b | S2 concurrent settings PG test | OK |
| T20 | S3 movement masking and liveness halves | OK |
| S-matrix: receive, both closes, complete, backfill, migrations | S1 §7.13 | OK |
| S-matrix: settings update/reset/initialization | S2 §8.10 | OK |
| Additional convention-09 reader/client evidence | S3 §9.8; S4 §10.8 | OK |

### Rollout and slicing

| Spec §11 item | Plan anchor | Result |
|---|---|---|
| 1 — migrations 1a–1e | S1 carries 1a–1c/1e; S2 carries 1d | OK |
| 2 — readers and backfill in same merge | Both in S1 | OK |
| 3 — default off, no environment flag | S1 setting-off behavior; S2 default-false company setting | OK |
| 4 — permission seed/cache/export/checklist | S1 §7.6/§7.16/§12.1 | OK |
| 5 — consolidated realignment log | S4 §11.4 | OK |
| 6 — glossary/generated types/permission map/POS artifacts | Assigned to the producing slices with live drift gates | OK |
| Deviation 1 — readers/backfill moved to S1 | Required to satisfy §11.2 and prevent `partially_received` reader loss | OK |
| Deviation 2 — setting-off API precedes S2 visibility service | Independently deployable and byte-compatible with the final default-off behavior | OK |
| Deviation 3 — full T18 assigned to S2 | Valid because its defining cases require the S2 setting and receiver route | OK |
| Deviation 4 — entry/exit scope fix co-located with masking | Same controller; no rollout-order conflict | OK |
| Named T3/T9/T19 partial contracts | Test ownership only; every row is closed and wholly rerun by the final dependent slice | OK |

## Rejected false positives

- R5 on-hand, available, rebalance, counting-reconciliation and lot-stock visibility is owner-accepted. It is correctly retained and positively asserted.
- The mobile receipt screen is not missing. M-1 owns the screen; this lane owns only the type/comment contract packet.
- PO blind receiving remains correctly deferred to T-3b.
- The four production slicing deviations comply with spec §11. The T3/T9/T19 split is test ownership, not a fifth production-order deviation.
- All operative authority paths name rev 6. Historical superseded filenames in the header and change logs are not dispatch authority; the stale SHA labels in MINOR m1 are provenance defects only.
- The explicit child `require` is executable: Composer’s dev autoloader resolves `Tests\TestCase` and PHPUnit before the child constructs the test-local notification.
- T11 protects all four quantity-reader query sites across completed, cancelled and untouched in-transit transfers, both companies, both locations and lot/no-lot fixtures; historical answers are pinned byte-for-byte.
- T17 preserves unrestricted non-blind entry/exit output byte-for-byte while separately proving the restricted-user scope correction.
- Both generated artifacts are legitimately outside the exactly-once source partition. Their preflight and CI drift gates exist at `scripts/preflight.sh:110-155` and `.github/workflows/ci.yml:2683-2714`.
- The ledger arithmetic remains coherent: 159 single-push files plus 13 contention files; 30 contention touches; 189 push rows; 172 distinct ERP files.
- The §12 tables retain the staging manifest’s per-slice row set and contain a `Push count` answer for each collapsed-push shape.
- S1’s `0c` label follows `0b` textually, but its explicit “before 0b/before production edits” dependency removes execution ambiguity.
- Non-race PG rows that still show the gitignored helper are covered by the plan’s mandatory per-session database substitution; the notification race itself uses the self-contained `phpunit-pgsql.xml` command.

## Preserve

- All five verified §0 errata.
- Every ruled owner decision: D1, D2, OQ-1 through OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4 and R5.
- All seven T-1 seams: zero-only GL boundary, no freight GL, terminal-before-capitalization ordering, transit outside both ledgers, movement-row/event-label distinction, initiate-only demand settlement, and reuse of source restocking.
- Additive guarded tenant migrations, named constraints/indexes, PostgreSQL transactional recovery, SQLite clean-rerun scope and forward-only post-commit repair.
- Decimal strings at column scale, `QuantityScale`, bcmath, explicit currency for scale resolution, and the float/cast/parser bans.
- Existing transfer events untouched; new stored-event classes only.
- One receipt writer, one `/complete` delegate and one controller response owner.
- `InventoryGlPostingBuffer` as the only GL path and its PHPStan guard.
- Rule-12 middleware on every new route and independently grantable close/reconcile permissions.
- Queued listeners with no `CompanyContext`; notification recipients resolved before queueing.
- Receiver omission by construction, quantity-free errors and exact `details: []`.
- Convention-09 company/location/idempotency evidence and the catalogue-key company scope/PG ratchet.
- Per-slice databases `autoerp_test_u`, `_v`, `_x`, `_y`; exact feature-lane groups, reviewer sets, handbacks and terminal `status: review`.
- S1 → S2 → S3 → S4 order, default-off staging safety, additive deployment and no dependency on a later push.
- The exact source partition, generated-artifact drift gates and separate one-file S4-mobile ledger.
- The corrected notification-race child script and its reachable first-red ordering.
- The 24-class manifest arithmetic and matching PG allowlist additions.
- Round-5 M1, m1 and m3 closures.

## Owner decisions required

VERDICT: DISPATCH-READY