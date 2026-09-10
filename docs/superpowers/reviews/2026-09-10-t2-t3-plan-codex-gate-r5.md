# Adversarial gate result

Audited plan: `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-5.md`

Audited HEAD: `6906b4f9e6c650e36027e3bd5bf6a48701ad85af`

Pin status: current. `git diff --name-only 86ab1dc58..HEAD | grep -v '^docs/'` is empty; the only intervening files are the rev-5 plan and its documentation prompt. The two unrelated untracked files match the plan’s census.

Audit mode: read-only. No files were edited, no tests were run, and no git writes were performed.

Citation audit:

- Absolute/repo-relative `path:line` occurrences checked: **505**
- Missing paths: **0**
- Out-of-range lines/ranges: **0**
- Production drift from `86ab1dc58`: **0 paths**
- Citation-content mismatches: **0**
- The two citations added since rev 4 are both `apps/api/composer.json:60-65`; they correctly show the `Tests\` → `tests/` development PSR-4 mapping.

The five §0 errata remain correct against the accepted spec’s tables: ten R5 families, seven surface rows 19–25, twelve R5 response-shape rows; the POS device types correspond to rows 8–9; every R5 range extends through row 25; the rev-11 change-log claim is overstated; and the matrix/POS mixed surfaces are rows 7–9.

## Round-4 closure table

| Round-4 item | Rev-5 status | Evidence |
|---|---|---|
| M1 — child cannot load `RaceProbeNotification` | **CLOSED in the plan body** | The child explicitly requires `tests/Feature/Notification/TransferNotificationRaceTest.php` after `vendor/autoload.php` at plan `:5594`; stage 1 and the first-red order are correctly restated at `:5600-5608,5626`. |
| M1 — closure survives actual Desktop dispatch | **REGRESSED** | Every ERP packet inherits the rev-4 authority at `:6294`; S4-mobile also names rev 4 at `:6332`. See MAJOR M1. |
| m1 — “23” versus 24 Feature classes | **NOT CLOSED completely** | The introduction now says 24 at `:768`, but the classification explanation still says “23 and not 25” at `:789`. See MINOR m1. |
| r3-M3 unreachable notification-race first red | **CLOSED in body / REGRESSED at dispatch** | The explicit require makes the red reachable, but the packet points the implementer to rev 4. |
| r2-M5 qualified closure — residual S4 race defect | **CLOSED in body / REGRESSED at dispatch** | Same evidence. |
| S4 red-first race evidence | **CLOSED in body / REGRESSED at dispatch** | Same evidence. |
| §7/T10 race executability | **CLOSED in body / REGRESSED at dispatch** | Same evidence. |
| Pin repinned | CLOSED | `86ab1dc58` is the parent of the documentation-only rev-5 commit. |
| Rounds 1–3 closures and gate-r4 Preserve list | No production-contract regression found | Schema, DTOs, events, reader baselines, masking, GL boundary, rollout order and ledger membership remain intact. The stale checklist sentence noted in MINOR m3 is an internal prose contradiction, not a missing ledger row. |

The child-script correction itself is sound. `vendor/autoload.php` in the test environment includes the dev PSR-4 mapping (`apps/api/composer.json:60-65`; generated loader at `apps/api/vendor/composer/autoload_psr4.php:23`), `Tests\TestCase` exists at `apps/api/tests/TestCase.php:3-10`, and PHPUnit is installed through that Composer loader. Requiring the test file compiles its class declarations, including the later `RaceProbeNotification`, before the child constructs it. The precedent’s working directory is `base_path()` and its child environment includes `QUEUE_CONNECTION=sync` (`apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:98-111,163-170`). CI installs Composer dependencies without `--no-dev` (`.github/workflows/ci.yml:641`).

## BLOCKER

None.

## MAJOR

### M1 — the executable dispatch packets still designate superseded rev 4 as their authority

**Plan**

- Rev 5 declares itself the only implementation authority at `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-5.md:5`.
- The generated promotion checklist names rev 4 at `:3913`.
- S1’s paste-ready packet names rev 4 as “Plan (authority)” at `:6294`; S2, S3 and S4 inherit that statement through “Plan and spec as in §17.1” at `:6298,6302,6306`.
- S4-mobile names the absolute rev-4 path at `:6332`.

**Source**

Spec T10 requires the two-worker race to reach the database and leave one notification without an exception: `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:579`. Gate r4 records that rev 4 cannot do so because its child lacks the explicit require: `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r4.md:49-76`.

**Failure scenario**

Pasting the S4 packet directs the implementer to rev 4, where the child exits with `RaceProbeNotification` not found before attempting an INSERT. The parent fails at `$blocked`, so the required duplicate-key first red is never captured. The same obsolete authority propagates through every ERP packet and the mobile packet; the promotion checklist later sends operators back to the same superseded document.

**Minimum correction**

Change all three operative authority copies to rev 5:

1. The embedded checklist at `:3913`.
2. S1’s authority sentence at `:6294`, also stating that revs 1–4 are superseded.
3. S4-mobile’s absolute authority path at `:6332`.

S2–S4 may continue inheriting §17.1 after that correction.

**Slice**

S1–S4 and S4-mobile; the observable test failure is S4.

## MINOR

### m1 — the round-4 class-count correction left a second “23” statement behind

**Plan**

Plan `:52` says round-4 m1 is closed. Plan `:768,801,818` correctly derives 24 Feature classes, but `:789` still says the two non-Feature artifacts explain “why the totals below are 23 and not 25.”

**Source**

The exhaustive group table at plan `:791-801` totals `12 + 1 + 1 + 2 + 3 + 3 + 2 = 24`, and the per-slice arithmetic at `:809-818` independently gives `14 + 5 + 3 + 2 = 24`.

**Failure scenario**

A dispatcher or reviewer using the explanatory paragraph can report a 23-class lane even though the manifest updates and allowlist correctly cover 24. The mechanical gates should catch a wrong ceiling, so this remains editorial.

**Minimum correction**

State that the manifest total is **24 Feature classes**. If comparing files, clarify that the two non-Feature artifacts make 26 test/support files overall—25 classes plus one trait—not “23 and not 25.”

**Slice**

Cross-slice, S1–S4.

### m2 — the repin provenance labels and historical check are internally stale

**Plan**

- Plan `:338` says gate r3 ran `e734ee872..HEAD`.
- Plan `:694` and `:6537` call `86ab1dc58` the “rev-4 planning HEAD.”
- Plan `:9` combines the gate-r4 and gate-r3 paths inside one malformed code span and then describes gate-r3 findings.

**Source**

Gate r3 actually audited at `5bd1520` using `364b1668b..HEAD`: `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r3.md:3-7`. Gate r4 audited the `e734ee872` pin: `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r4.md:3-7`.

**Failure scenario**

The current pin remains valid, but a future citation audit cannot reproduce which gate verified which ancestry range from the stated provenance.

**Minimum correction**

Attribute `364b1668b..HEAD` to gate r3 and `e734ee872..HEAD` to gate r4; call `86ab1dc58` the rev-5 planning HEAD at `:694,6537`; repair the gate-history sentence at `:9`.

**Slice**

Cross-slice.

### m3 — one S1 checklist paragraph contradicts the exact file inventory and ledger

**Plan**

Plan `:3904` says the existing `PROMOTION-CHECKLIST-2026-08-26.md` “does not appear in any push’s file list.” The same plan assigns its one-line successor pointer to S1 at `:1594,3985-3991`, lists it in Push 1 at `:5726`, and requires it in the S1 packet at `:6294`.

**Source**

Spec rollout step 4 requires a durable `PROMOTION-CHECKLIST-*` update: `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:688`.

**Failure scenario**

The actual inventory, ledger and dispatch packet correctly own the edit, but a literal reader of `:3904` can treat the pointer as outside every push or reject the ledger as contradictory.

**Minimum correction**

Replace the final sentence at `:3904` with: the existing checklist is modified only by the one-line S1 successor pointer and appears exactly once in S1’s file inventory and Push-1 ledger.

**Slice**

S1.

## Spec-fidelity table

### Schema, events, API, masking and clients

| Spec item | Plan anchor | Result |
|---|---|---|
| §3.1 line/allocation counters and CHECKs | S1 §7.3; migration `2026_09_09_100000_add_receipt_counters_to_transfer_lines.php` | OK |
| §3.1 transfer close/freight columns | S1 §7.3; migration `…100200_add_close_columns_to_stock_transfers.php` | OK |
| §3.1 fraud-setting columns | S2 §8.3; migration `…110000_add_receiving_controls_to_company_fraud_settings.php` | OK |
| §3.1 guarded migrations and recovery | S1 §7.3–§7.4; S2 §8.3; PG interruption and clean-rerun cases | OK |
| §3.1b decimal casts/string contract | S1 §7.4/§7.12; S2 §8.4; `QuantityScale`, bcmath and static guards | OK |
| §3.2 receipt header, line and lot tables | S1 §7.3; all migration bodies reproduced in full | OK |
| §3.3 six new enums and expanded `TransferStatus` | S1 §7.12 | OK |
| §3.4 state machine | S1 §6.7b and §7.5 | OK |
| §3.5 three remainder readers | S1 §7.7; two location-query sites, matrix and WAC | OK |
| §3.6 I1–I7 | S1 migrations, writer, readers, movements, freight and replay tests | OK |
| §3.6 I8 | S2 §8.7; initialization, atomic bump and version tests | OK |
| §4.0 receipt-id aggregate anchor | S1 §7.9; `persist($event, $receiptId)` | OK |
| §4.1 header events | S1 §7.9; `StockTransferReceivedV1`, `StockTransferClosedV1` | OK |
| §4.2 per-line event | S1 §7.9; `StockTransferReceiptLineRecordedV1` | OK |
| §4.3 replay, atomicity, existing-event immutability | S1 §7.9 and T15 | OK |
| §4.4 pattern query and attribution | S1 §7.10 and T16 | OK |
| §4.5 visibility event/version | S2 §8.7 and T19/T19b | OK |
| §5 preamble middleware/errors | S1 §7.5–§7.6; routes inherit the rule-12 Inventory group | OK |
| §5.0 rows 1–6 | S1/S2 controller builders, receive/complete/close responses and typed errors | OK |
| §5.0 rows 7–9 | S3 §9.3 matrix and POS feeds | OK |
| §5.0 rows 10–11 / §5.10 | S3 §9.5 movement/entry-exit masking and location scope | OK |
| §5.0 row 12 | S1 reconciliation; S2 appends `visibility_version` | OK |
| §5.0 row 13 / §7 notifications | S4 §10.3 and §10.10 | **ALTERED AT DISPATCH — M1 points the packet to rev 4** |
| §5.0 row 14 | S1/S2 generated DTOs; S4 discriminated union | OK |
| §5.0 row 15 / §9 mobile | S4-mobile §17.5 | OK in body; authority pointer requires M1 correction |
| §5.0 row 16 | S4 §10.7 POS version/cache changes | OK |
| §5.0 rows 17–18 / §5.9 | S3 replenishment masking; S4 clients | OK |
| §5.0 rows 19–25 | S3 §9.2 zero-file rule and R5 controls | OK |
| Twelve §5.0b R5 shapes | S3 §9.6/§9.7 | OK |
| §5.1 A1–A6, B1–B10 and named errors | S1 §7.5; FormRequests, canonicalizer and validation tests | OK |
| §5.2 close authority/dispositions/errors | S1 §7.5–§7.6; chained permissions and source check | OK |
| §5.3 visibility/builders/receipt DTOs | S1 full builder; S2 receiver builder | OK |
| §5.4 reconciliation | S1 service/controller/DTO; S2 version delta | OK |
| §5.5 company setting | S2 migration/model/controller/service/event | OK |
| §5.6 `/complete` delegation | S1 single delegate; S2 blind gate | OK |
| §5.7 incoming aggregates | S3 §9.3 | OK |
| §5.8 permissions/route gates | S1 §7.6; S2 T18 | OK |
| §6.1 movements | S1 §7.8 through `StockAdjustmentService` | OK |
| §6.2 GL bridge | S1 §7.8; `InventoryGlPostingBuffer` only, guarded by T13/PHPStan | OK |
| §6.3 return-to-source | S1 source-restock path and T4b | OK |
| §6.4 freight | S1 §7.11; landed weighting, residual, no freight journal | OK |
| §8 web | S4 §10.4–§10.6 | OK |
| §8b POS | S4 §10.7 | OK |
| §9 screen ownership | Contract packet only; screen remains M-1 | OK |

### Test matrix

| Spec row | Plan anchor | Result |
|---|---|---|
| T1 | S1 `StockTransferReceiveTest` | OK |
| T2 | S1 `StockTransferReceiveTest` | OK |
| T3 | S1 damage/GL half; S4 notification half and closing rerun | OK |
| T4 | S1 `StockTransferCloseTest` | OK |
| T4b | S1 return-to-source case | OK |
| T5 | S1 `StockTransferReceiveValidationTest` | OK |
| T6 | S1 `StockTransferReceiveLotsTest` | OK |
| T7 | S1 `StockTransferReceiveConcurrencyPostgresTest` | OK |
| T7b | Same S1 PG class, receive/close orderings | OK |
| T7c | Same S1 PG class, receive/complete orderings | OK |
| T8 | S1 precision case and static guards | OK |
| T9 | S3 oracle; S4 step 6o and whole-class rerun | OK |
| T9b | S2 receiver-payload key scan | OK |
| T10 | S4 notification, race and web tests | **ALTERED AT DISPATCH — corrected body is bypassed by M1** |
| T11 | S1 backfill and committed two-lane reader baseline | OK |
| T12 | S1 remainder-reader architecture test | OK |
| T13 | S1 GL boundary/leak tests | OK |
| T14 | S1 replenishment-settlement regression | OK |
| T15 | S1 event persistence/replay | OK |
| T16 | S1 pattern query | OK |
| T17 | S3 scope test and unrestricted byte-preservation baseline | OK |
| T18 | S2 authority matrix | OK |
| T19 | S2 version tests; S3 carriage assertions and closing reruns | OK |
| T19b | S2 concurrent settings PG test | OK |
| T20 | S3 movement masking | OK |
| S-matrix: receive, both closes, complete, backfill, migrations | S1 §7.13 | OK |
| S-matrix: settings update/reset/initialization | S2 §8.10 | OK |
| Additional reader/client convention-09 evidence | S3 §9.8; S4 §10.8 | OK |

### Rollout

| Spec §11 item | Plan anchor | Result |
|---|---|---|
| 1 — migrations 1a–1e | S1 carries 1a–1c/1e; S2 carries 1d | OK |
| 2 — readers and backfill in same merge | Both in S1 | OK |
| 3 — default off, no environment flag | S1 setting-off behavior; S2 default-false company setting | OK |
| 4 — permission seed/cache/export/checklist | S1 §7.6/§7.16/§12.1 | OK, with stale sentence m3 |
| 5 — consolidated realignment log | S4 §11.4 | OK |
| 6 — glossary/generated types/permission map/POS artifacts | Assigned to producing slices with live drift gates | OK |
| Deviation 1 — readers/backfill moved into S1 | Required by §11.2 | OK |
| Deviation 2 — S1 ships setting-off behavior before S2 | Default-off compatible and independently deployable | OK |
| Deviation 3 — full T18 assigned to S2 | Valid because defining cases require S2 visibility | OK |
| Deviation 4 — entry/exit scope co-located with masking | Valid; same controller and no rollout-order conflict | OK |
| “Deviation 5” | Test-row ownership split only; production order unchanged | OK |

## Rejected false positives

- The explicit test-file require is valid. Composer’s development autoloader resolves `Tests\TestCase` and PHPUnit; requiring a PHPUnit test file from a plain `php -r` child does not execute its test methods.
- R5 on-hand, available, rebalance, counting-reconciliation and lot-stock visibility is owner-accepted and correctly remains present.
- The mobile receipt screen is not missing; M-1 owns it. This lane owns only the contract packet.
- PO blind receiving remains correctly deferred to T-3b.
- The four production slicing deviations comply with spec §11. “Deviation 5” is test ownership, not a fifth production-order deviation.
- T11 protects historical answers: all four quantity-reader sites are compared byte-for-byte for completed, cancelled and untouched in-transit transfers across both companies, both locations and lot/no-lot fixtures.
- T17 protects unrestricted non-blind output byte-for-byte while separately proving the intended restricted-user location scope.
- The 159/13/30/189/172 ledger arithmetic remains correct.
- Both generated artifacts have live drift gates at the cited preflight and CI lines.
- The §12 `Push count` rows contain the manifest’s collapsed-push answer and match the accepted A-1a table shape.
- Arabic notification files are not missing; the current resource composition falls back to the English notification/stock-transfer bundles.
- S1’s `0c` baseline numbering is awkward, but its explicit “before 0b/before production edits” dependency makes the required order unambiguous.
- The residual use of the gitignored `docs/sessions/t1/pg-test.sh` in non-race PG rows is covered by the plan’s global substitution instruction; the race itself uses the self-contained per-session database command.

## Preserve

- All five verified §0 errata.
- Every ruled owner decision: D1, D2, OQ-1 through OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4 and R5.
- All seven T-1 seams: zero-only GL buffer boundary, no freight GL, terminal-before-capitalization ordering, transit outside both ledgers, movement identity/event distinctions, initiate-only demand settlement, and reuse of source restocking.
- Additive guarded tenant migrations, named constraints/indexes, PostgreSQL transactional recovery, SQLite clean-rerun scope and forward-only post-commit repair.
- Decimal strings at column scale, `QuantityScale`, bcmath, explicit currency for scale resolution, and all float/cast/parser bans.
- Existing transfer events untouched; new stored-event classes only.
- One receipt writer, one `/complete` delegate and one controller response owner.
- `InventoryGlPostingBuffer` as the sole GL path and its PHPStan guard.
- Receiver omission by construction, static quantity-free errors and exact `details: []`.
- Per-slice databases `autoerp_test_u`, `_v`, `_x`, `_y`; exact feature-lane groups, reviewer sets, handbacks and terminal `status: review`.
- S1 → S2 → S3 → S4 order, default-off activation, additive staging deployment and no dependency on a later push.
- The exact source partition, generated-artifact drift gates and separate one-file S4-mobile ledger.
- The corrected rev-5 child script and its first-red ordering.

## Owner decisions required

VERDICT: CHANGES-REQUIRED