# Codex DPA Wave 3C/3D execution report

## Run identity

- Dispatch: `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md`
- Harness: `docs/handoff/SELF-REVIEW-HARNESS.md`
- Base SHA: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18`
- 3C branch: `codex/dpa-wave3-3c`
- PostgreSQL: local port 5432; tests by path only

## M0 — preflight

Files touched:

- `scripts/wave3-citation-inventory.php`
- `docs/handoff/reviews/wave3-3c-3d/M0-citation-inventory.csv`
- `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`
- `docs/handoff/progress/wave3-3c-3d.progress.yaml`
- this report

Evidence and actual outputs are recorded in `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`. The run started with `HEAD == BASE_SHA`; after adversarial fix round 1, `N_extracted=256`, `N_mapped=256`, `unresolved=0`, including 13 extensionless citations and zero file-scope fallbacks. The regression test's red state was `Missing extensionless citation: SalesOrderToInvoiceConverter:334`; its green state is `wave3 citation inventory regression: PASS (256 rows)`. R-11's local result is `0` on a zero-denominator sample and is not treated as deploy evidence; both Workshop tickets are present; D-19 reconciles to 18 rows; GR movements use `Document` / purchase-order id and receipt-line identity is carried by unique `movement_id` / `free_movement_id` links.

Decision: POS refund re-entry will use the original POS sale movement cost at the same product grain (R-1 option a).

Fix-round revert/replay: revert `3fabdaa34` reduced the inventory to 243 citations and the covering check exited 1 for the missing `SalesOrderToInvoiceConverter:334` citation; restore `d2b5765e0` returned the regression to 256 passing rows.

Adversarial round 2 found four comment/punctuation mappings and the unrecorded bare R3-2 continuation. The round-2 test was red at the stale `DeliveredQuantityResolver:399-402` comment mapping. That statement citation now records its executable guard, while genuine rationale-block citations retain `anchor_kind=comment`; the report records current POS ordering through closure line 477 and all remaining bare-continuation coverage.

Round-2 revert/replay: revert `2d6723f9d` made the semantic covering probe exit 1 for the stale comment-backed resolver mapping; restore `78933f51e` returned the executable relocation and 256-row regression to green.

Adversarial round 3 exposed that the executable-only rule had displaced a legitimate inv-I1 comment target and that nearby-line assertions did not update `new_line`. The inventory now reports the actual assertion address, resolves docblocks to the method they document, emits its own second metrics line, and pins every manual override. Current result: `N_extracted=256 N_mapped=256 relocated=6 unresolved=0`; 34 explicit comment anchors are retained and classified.

Round-3 revert/replay: revert `ac89b8c88` made the V10 comment-target probe exit 1; restore `850b62b46` returned the inv-I1 block at `217-226` and the full corpus to green.

Adversarial round 4 found cross-file construct moves that line-diff mapping could not detect. The inventory now fails closed on reference/current semantic drift and explicitly relocates the deleted delivery-compliance cluster, D-19 twin B, and five other integrated semantic successors. Both V10 comment citation forms map to `217-226`, and the write-off `source_id` precedent maps to line 4708. Current result: `N_extracted=256 N_mapped=256 relocated=23 unresolved=0`, with 16 fully pinned relocation keys.

Round-4 revert/replay: revert `b48e88974` made the deleted-delivery-predicate successor probe exit 1; restore `06ebab27d` returned the cross-file relocation and semantic drift gate to green.

Adversarial round 5 found ten unextracted timestamp/lowercase citations, the T18 seeder comment annotation, and stale-line exposure in manual relocations. The inventory now contains 266 rows, consumes required comment/docblock annotations, records the ambiguous treasury migration rule, and validates each relocation by symbol and semantic text. Current result: `N_extracted=266 N_mapped=266 relocated=24 unresolved=0`.

Round-5 revert/replay: revert `e45248fdf` dropped the corpus to 256 and made the timestamp-citation probe exit 1; restore `f0a74d332` returned all 266 citations to green.

M0 gate: round 6 independently reproduced the 266-row corpus, exercised relocation-pin mutations, re-derived C-2/C-3 depths, and returned `ACCEPT`. M0 is passed; M1 begins from the accepted evidence register.

Deviation discovered and resolved in the execution model: `RefundService` currently calls `ReturnNoteService::confirmWithin()` at transaction depth 1, while D-28 states depth 2. M2 will add the implied inner savepoint at that call before the writer-tail flush and retain C-2's root-tail flush. This aligns runtime depth with the settled architecture without changing the domain transition or lock set.

## M1 — stopped on architecture contradiction

Partial implementation is preserved at `abb3018efd9b582ad788f7162271c940192a0f62`. It contains the movement-keyed GL DTO/buffer/service, scoped lifecycle and rollback reset, `absoluteDeltaForRow`, source-type constant and partial unique migration, explicit journal mappings, static buffer-only/I-2 rules, original-exit return-cost resolver and payload recording, and V-10's location carry plus typed FEFO refusal. This is not represented as a passed milestone.

T16c audit result: **negative branch inapplicable**. The interactive return loop routes `Scrap` to `applyScrapPair` (`ReceiptReturnService.php:435-449`). That method opens one savepoint and calls `restoreStock` followed by `ReturnScrapWriteOffService::writeOff` (`:1394-1422`), with a shared catch that rethrows retryable concurrency faults and contains other failures (`:1423-1441`). The symmetric pair is therefore live. M2 must apply D-23/T16d buffering to this interactive pair as well as the projection pair.

Verification run immediately before the stop:

- PostgreSQL `InventoryGlPostingSeamTest`: `3 passed (19 assertions)`; covers one-rounding arithmetic (`3 × 1.6666666 = 5.000` at TND scale), Posted/balanced/idempotent entry, persisted partial-index predicate, `23505`, root rollback reset, and a named `connectionsToTransact(): []` leak-alarm mechanism.
- `StandaloneInvoiceGuidedDeliveryTest`: `11 passed (43 assertions)`, including source-line location preservation and `FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH` with no draft DN or movement left behind.
- `ReturnCostBasisResolverTest` + `ReturnNoteConfirmSealAndPeriodTest`: `11 passed (37 assertions)`; FIFO weighted exit basis, stable movement ids, honest current-cost fallback, and existing RN period/seal behavior.
- inventory unit paths: `7 passed (15 assertions)`.
- PHPStan level 8 on touched seam/accounting/document files: `[OK] No errors`.
- Pint on all touched PHP files: completed successfully.
- Deterministic two-connection PostgreSQL sensitivity probe: `session_a_sqlstate=40P01`, `session_b_sqlstate=00000`, proving the reversed-lock instrument detects the required failure class rather than green-by-vacuum.

### Harness STOP C

The brief makes T11c (all ten pairs) part of M1 and says pairs 7/8 become green through T16d and pairs 9/10 through T16e; if any pair cannot be green, 3C must stop (`CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:381-390`). The authoritative plan says the same (`plan-wave3.md:2724-2738`). But the brief simultaneously requires T16d and T16e to remain in M2's single indivisible cutover commit (`CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:399-406`), and the plan says T16d/T16e ship in that cutover and may not be separately deployable (`plan-wave3.md:2910-2929`, `:2947-2977`, `:2979-2983`).

There is no compliant M1 state: moving T16d/T16e earlier violates the atomic cutover invariant; leaving them in M2 makes M1's required ten-pair green gate impossible. Per `SELF-REVIEW-HARNESS.md` STOP C, no adversarial M1 register was invoked because the milestone could not reach the implement-green-review boundary. The required owner/orchestrator action is to re-sequence the gate, most naturally by accepting pairs 7-10's red-before evidence in M1 and requiring their green-after evidence as part of M2.
