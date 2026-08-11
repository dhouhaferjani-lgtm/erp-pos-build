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
