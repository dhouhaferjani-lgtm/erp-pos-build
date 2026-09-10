# Adversarial gate result

Audited plan: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-4.md`

Audited HEAD: `c1878add615d83f031b34e848dc8c124b109445f`

Pin status: current. `git diff --name-only e734ee872..HEAD | grep -v '^docs/'` is empty; the only intervening files are the rev-4 plan and its documentation prompt. The two unrelated untracked files match the plan’s census. The sibling mobile repository remains at `51e3445c1aa794f2a76d58c4771ecbbdca2f3f84`.

Audit mode: read-only. No files were edited, no tests were run, and no git writes were performed.

Citation audit:

- Citation occurrences checked: **503**
- Distinct path/range triples checked: **304**
- Missing paths: **0**
- Out-of-range lines/ranges: **0**
- Production drift from `e734ee872`: **0 paths**
- Claim clusters not supported by the cited/current code: **1** — the S4 subprocess cannot autoload its test-local notification fixture; see MAJOR M1.

The five §0 errata remain correct against the accepted spec’s tables: ten R5 families, seven surface rows 19–25, twelve R5 response-shape rows; POS device types correspond to rows 8–9; all R5 range references extend through row 25; the rev-11 change-log claim is overstated; and matrix/POS mixed surfaces are rows 7–9.

## Round-3 closure table

| Round-3 item | Rev-4 status | Evidence |
|---|---|---|
| B1 — incompatible `/complete` signatures and callers | CLOSED | The declaration, forwarder, controller body and rule 9 consistently use `receiveAllRemaining(string $transferId, …)` and make `StockTransferService::complete()` its only caller. Plan `:1256`, `:1305-1418`, `:2258`, `:4663-4665`. |
| M1 — five abbreviated French locale paths | CLOSED | All twelve locale files are literal paths, one bullet each. Plan `:5331-5349`, ledger reconciliation `:5357`. |
| M2 — combined deployment-variable table | CLOSED | Four self-contained fourteen-row tables now exist at `:5872-5946`; each `Push count` row answers the manifest’s collapsed-push question. |
| M3 — unreachable notification-race first red | **NOT CLOSED** | The production notification dependency was removed, but the child process still cannot load the new test-local `RaceProbeNotification`; see MAJOR M1. |
| m1 — `visibility_version` called unsigned | CLOSED | Migration, prose, DTO and generated-type descriptions consistently use PostgreSQL `integer` / PHP `int` / TypeScript `number`. Plan `:4079`, `:4448`, `:4811`. |
| r2-M1 — incomplete receipt-writer call graph | CLOSED | Same evidence as B1. |
| r2-M5 qualified closure — residual S4 race defect | **NOT CLOSED** | The residual remains through a different setup failure. |
| r2-M6 qualified closure — literal locale aliases | CLOSED | Exact paths and ledger arithmetic now agree without alias normalization. |
| S1 non-dispatchable call graph | CLOSED | One compilable writer/delegate path is specified. |
| S2 non-dispatchable direct receipt-service call | CLOSED | S2 adds only the visibility gate and retains `$this->service->complete(...)`. |
| S4 exact-file defect | CLOSED | Forty literal modify paths are present. |
| S4 red-first race evidence | **NOT CLOSED** | The child fails before reaching the contested insert. |
| Source-ledger alias issue | CLOSED | Totals remain 159 single-push files, 13 contention files, 30 contention touches, 189 push rows and 172 distinct ERP files. |
| §3.1/§3.4/§5.0/§5.6 defects tied to B1/m1 | CLOSED | Correct migration type and single `/complete` contract. |
| §7/T10 race executability | **NOT CLOSED** | See MAJOR M1. |
| Rounds 1–2 closures and gate-r3 Preserve list | No regression found | Schema, events, DTOs, historical baselines, masking, GL boundary, rollout order and ledger membership remain intact. The stale “23 classes” sentence is a new editorial inconsistency, not a regression of the correct arithmetic; see MINOR m1. |

## BLOCKER

None.

## MAJOR

### M1 — the S4 subprocess cannot autoload the test-local race notification

**Plan**

`docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-4.md:5563-5584` places `RaceProbeNotification` below `TransferNotificationRaceTest` in the same file. The child script at `:5570` only loads `vendor/autoload.php`, boots Laravel, and then constructs `RaceProbeNotification`. Plan `:5580` and `:5602` consequently claim the child reaches the duplicate insert and that `assertSame(0, $contender->wait(), …)` is the first failure.

**Source**

- Spec T10 requires a real two-worker same-id race that reaches the database and produces one row without an exception: `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:579`.
- Test classes are PSR-4 mapped from `Tests\` to `tests/`: `apps/api/composer.json:60-65`. A class named `Tests\Feature\Notification\RaceProbeNotification` is therefore looked up as `tests/Feature/Notification/RaceProbeNotification.php`, not inside `TransferNotificationRaceTest.php`.
- The cited precedent’s child bootstrap loads only Composer and then invokes an existing production class that Composer can autoload: `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:98-104`. It does not demonstrate loading a second class hidden in the test file.

**Failure scenario**

PHPUnit loads `TransferNotificationRaceTest.php` only in the parent process. The independent `php -r` child inherits neither the parent’s class table nor PHPUnit’s loaded test file. It exits with `Class "Tests\Feature\Notification\RaceProbeNotification" not found` before issuing an INSERT. The parent cannot observe a PostgreSQL lock, so `assertTrue($blocked, …)` fails first. Stage 1 never produces the promised `SQLSTATE[23505]`, stage 2 remains broken for the same reason, and the distinct-id control also cannot write two rows.

**Minimum correction**

Make the child load the fixture explicitly before constructing it, for example:

```php
require 'vendor/autoload.php';
require 'tests/Feature/Notification/TransferNotificationRaceTest.php';
```

Then instantiate the fully qualified `Tests\Feature\Notification\RaceProbeNotification`.

Alternatively, move the probe into an exact PSR-4-matching test-support file and update the S4 inventory and ledger. In either case, restate the exact child script and preserve the required first-red order: blocked assertion passes, parent commits, child exit assertion fails with the duplicate-key stderr.

**Slice**

S4.

## MINOR

### m1 — the manifest introduction still says 23 Feature classes while the executable arithmetic says 24

**Plan**

`docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-4.md:744` says the lane adds **23 PHP Feature test classes**.

**Source**

The exhaustive group table totals 24 at plan `:767-777`; the per-slice arithmetic is `14 + 5 + 3 + 2 = 24` at `:785-794`; and the allowlist decision explicitly covers all 24 at `:873-890`.

**Failure scenario**

A dispatcher reading the §6.7 introduction can report or validate a 23-class ceiling delta even though every exact group value, allowlist entry and final `gated_ceiling` correctly accounts for 24. The mechanical gates should still catch the mismatch, so this is editorial rather than a dispatch blocker.

**Minimum correction**

Change “23 PHP Feature test classes” at `:744` to “24 PHP Feature test classes.” Preserve all group values and the final `gated_ceiling 1277`.

**Slice**

Cross-slice, S1–S4.

## Spec-fidelity table

### Schema, events, API, masking and clients

| Spec item | Plan anchor | Result |
|---|---|---|
| §3.1 line/allocation counters and CHECKs | S1 §7.3, migration `2026_09_09_100000_add_receipt_counters_to_transfer_lines.php` | OK |
| §3.1 transfer close/freight columns | S1 §7.3, migration `…100200_add_close_columns_to_stock_transfers.php` | OK |
| §3.1 fraud-setting columns | S2 §8.3, migration `…110000_add_receiving_controls_to_company_fraud_settings.php` | OK |
| §3.1 guarded migration/recovery recipe | S1 §7.3–§7.4, S2 §8.3, schema-rerun and PG interruption cases | OK |
| §3.1b decimal casts/string contract | S1 §7.4/§7.12, S2 §8.4; `QuantityScale`, bcmath and static guards | OK |
| §3.2 receipt header/line/lot tables, keys, indexes and CHECKs | S1 §7.3; all three migration bodies reproduced in full | OK |
| §3.3 six new enums and expanded `TransferStatus` | S1 §7.12 | OK |
| §3.4 state machine | S1 §6.7b and §7.5 | OK |
| §3.5 three remainder readers | S1 §7.7; two `LocationStockQueryService` sites plus matrix and WAC | OK |
| §3.6 I1–I7 | S1 migrations, writer, readers, movements, freight and replay tests | OK |
| §3.6 I8 | S2 §8.7; initialization, atomic bump and event/version tests | OK |
| §4.0 receipt-id aggregate anchoring | S1 §7.9; `StoredEventRepository::persist($event, $receiptId)` | OK |
| §4.1 header events | S1 §7.9; `StockTransferReceivedV1`, `StockTransferClosedV1` | OK |
| §4.2 per-line event | S1 §7.9; `StockTransferReceiptLineRecordedV1` | OK |
| §4.3 replay, atomicity and existing-event immutability | S1 §7.9 and T15 | OK |
| §4.4 pattern query and attribution | S1 §7.10 and T16 | OK |
| §4.5 visibility event/version semantics | S2 §8.7 and T19/T19b | OK |
| §5 preamble middleware and typed errors | S1 §7.5–§7.6; all routes remain in the rule-12 Inventory group | OK |
| §5.0 rows 1–6 | S1/S2 controller builders, receive/complete/close responses and errors | OK |
| §5.0 rows 7–9 | S3 §9.3 matrix and POS incoming feeds | OK |
| §5.0 rows 10–11 / §5.10 | S3 §9.5 movement and entry/exit masking plus location scope | OK |
| §5.0 row 12 | S1 reconciliation service/DTO; S2 adds `visibility_version` | OK |
| §5.0 row 13 / §7 notifications | S4 §10.3 production contract | **ALTERED — the required race evidence is not executable (M1)** |
| §5.0 row 14 | S1/S2 generated DTOs and S4 discriminated union | OK |
| §5.0 row 15 / §9 mobile contract | S4-mobile packet §17.5 | OK |
| §5.0 row 16 | S4 §10.7 POS version/cache changes | OK |
| §5.0 rows 17–18 / §5.9 | S3 replenishment masking; S4 device consumers | OK |
| §5.0 rows 19–25 and twelve §5.0b R5 shapes | S3 §9.2/§9.6 zero-file rule and positive controls | OK |
| §5.1 A1–A6 and B1–B10, including every named error | S1 §7.5, both FormRequests, canonicalizer and validation tests | OK |
| §5.2 close authority, dispositions and errors | S1 §7.5–§7.6; two chained permissions and source check | OK |
| §5.3 visibility/builders/receipt DTOs | S1 full builder/receipt families; S2 receiver builder | OK |
| §5.4 reconciliation endpoint | S1 service/controller/DTO; S2 version member | OK |
| §5.5 company setting | S2 migration, model/controller/service/event | OK |
| §5.6 quantity-less complete delegation | S1 §6.7b/§7.5; S2 blind gate | OK |
| §5.7 incoming aggregates | S3 §9.3 | OK |
| §5.8 permission and route gates | S1 §7.6; S2 T18 | OK |
| §6.1 movements | S1 §7.8 through `StockAdjustmentService` | OK |
| §6.2 GL bridge | S1 §7.8; `InventoryGlPostingBuffer` only, guarded by T13/PHPStan | OK |
| §6.3 return-to-source | S1 shared restock path and T4b | OK |
| §6.4 freight | S1 §7.11; landed weighting, residual, no freight journal | OK |
| §8 web | S4 §10.4–§10.6 | OK |
| §8b POS | S4 §10.7 | OK |
| §9 mobile screen ownership | S4-mobile contract only; screen remains M-1 | OK |

### Test matrix

| Spec row | Plan anchor | Result |
|---|---|---|
| T1 | S1 `StockTransferReceiveTest` | OK |
| T2 | S1 `StockTransferReceiveTest` | OK |
| T3 | S1 `StockTransferReceiveDamageTest`; S4 notification half and full-row rerun | OK |
| T4 | S1 `StockTransferCloseTest` | OK |
| T4b | S1 `StockTransferCloseTest` return path | OK |
| T5 | S1 `StockTransferReceiveValidationTest` | OK |
| T6 | S1 `StockTransferReceiveLotsTest` | OK |
| T7 | S1 `StockTransferReceiveConcurrencyPostgresTest` | OK |
| T7b | Same S1 PG class, receive/close forced orderings | OK |
| T7c | Same S1 PG class, receive/complete forced orderings | OK |
| T8 | S1 precision case plus static guards | OK |
| T9 | S3 `TransferBlindLeakOracleTest`; S4 adds 6o and reruns whole class | OK |
| T9b | S2 `TransferReceiverPayloadBuilderKeyScanTest` | OK |
| T10 | S4 `TransferNotificationTest`, race test and web notification test | **ALTERED — race cannot reach its stated first assertion (M1)** |
| T11 | S1 `TransferLegacyCompletionBackfillTest` and committed two-lane baseline | OK |
| T12 | S1 `TransferInTransitReadersUseRemainderTest` | OK |
| T13 | S1 GL boundary/leak tests | OK |
| T14 | S1 replenishment settlement regression | OK |
| T15 | S1 event persistence/replay tests | OK |
| T16 | S1 pattern-query test | OK |
| T17 | S3 entry/exit scope and byte-preservation baseline | OK |
| T18 | S2 complete authority matrix | OK |
| T19 | S2 visibility/version tests; S3 closing reruns | OK |
| T19b | S2 `ReceivingControlsConcurrencyPostgresTest` | OK |
| T20 | S3 `TransferMovementMaskingTest` | OK |
| S-matrix: receive, both close dispositions, complete, backfill, migrations | S1 §7.13 | OK |
| S-matrix: settings update/reset/initialization | S2 §8.10 | OK |
| Additional convention-09 reader/client evidence | S3 §9.8, S4 §10.8 | OK |

### Rollout

| Spec §11 item | Plan anchor | Result |
|---|---|---|
| 1 — migrations 1a–1e | S1 carries 1a–1c/1e; S2 carries 1d | OK |
| 2 — reader switch and backfill in same merge | Both are S1 | OK |
| 3 — default off, no environment flag | S1 setting-off behavior; S2 adds default-false company setting | OK |
| 4 — permission seed/cache/export/checklist | S1 §7.6/§7.16/§12.1 | OK |
| 5 — consolidated realignment log | S4 §11.4 | OK |
| 6 — glossary, generated types, permission map and POS artifacts | Assigned to their producing slices with CI drift gates | OK |
| Deviation 1 — readers/backfill moved into S1 | Required by §11.2 and safe | OK |
| Deviation 2 — S1 ships full behavior before S2 setting | Default-off compatible; no later dependency | OK |
| Deviation 3 — full T18 assigned to S2 | Valid because its defining cases require S2 visibility | OK |
| Deviation 4 — entry/exit scope and masking co-located in S3 | Valid; same controller and no rollout-order conflict | OK |

## Rejected false positives

- R5 on-hand, available, rebalance, counting-reconciliation and lot-stock visibility is owner-accepted and correctly remains present.
- The mobile receipt screen is not missing; M-1 owns it. This lane owns only the contract packet.
- PO blind receiving remains correctly deferred to T-3b.
- The four rollout deviations comply with spec §11. The separately described T3/T9/T19 split is test ownership, not a fifth production-order deviation.
- The §12 row name `Push count` matches A-1a rev 9 and every row explicitly answers the manifest’s collapsed-push question.
- Arabic notification files are not missing. The current `i18n.ts` composition uses the English stock-transfer bundle and merges English notification keys under Arabic.
- The new `phpunit-pgsql.xml` commands correctly replace the gitignored helper and set both per-session database variables. The defect is the missing child-process fixture load, not the command itself.
- T11 protects historical answers: all four query sites are compared byte-for-byte for completed, cancelled and untouched in-transit transfers across both companies, both locations and lot/no-lot fixtures.
- T17 protects unrestricted non-blind output byte-for-byte while separately proving the intended restricted-user location scope.
- The 159/13/30/189/172 source-ledger arithmetic remains correct, and both generated artifacts have live drift gates.
- The S1 dispatch packet’s `0c` clause explicitly requires baseline capture before `0b`; its numbering is awkward but the execution dependency is unambiguous.

## Preserve

- All five verified §0 errata.
- Every ruled owner decision: D1, D2, OQ-1 through OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4 and R5.
- All seven T-1 seams, including terminal-before-capitalization ordering, transit outside both ledgers, movement-row rewriting, demand-only settlement, no freight GL and reuse of source restocking.
- Additive guarded tenant migrations, named constraints/indexes, PostgreSQL transaction recovery and forward-only post-commit repair.
- Decimal strings at column scale, `QuantityScale`, bcmath, explicit currency in scale resolution, and all no-float/no-cast client and server guards.
- Existing transfer events untouched; new event classes only.
- One receipt writer, one `/complete` delegate and one controller response owner.
- `InventoryGlPostingBuffer` as the sole GL path.
- Receiver omission by construction, static quantity-free errors and `details: []`.
- Per-slice databases `autoerp_test_u`, `_v`, `_x`, `_y`; exact feature-lane groups, reviewer sets, handbacks and terminal `status: review`.
- S1 → S2 → S3 → S4 order, default-off activation, additive staging deployment and no dependency on a later push.
- The exact file partition, generated-artifact drift gates and separate one-file S4-mobile ledger.

## Owner decisions required

VERDICT: CHANGES-REQUIRED