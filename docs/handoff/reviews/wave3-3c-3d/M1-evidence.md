# M1 evidence — inventory GL seam and amended T11c gate

Authority for the split exit condition:
`docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md`.
The ruling requires pairs 1–6 green in M1, pairs 7–10 red for the missing T16d/T16e
change with their output captured here, and all ten green as a hard M2 gate.

## T11c production traces and sensitivity control

The acceptance evidence has two independent halves:

1. Real writers are traced through `DB::listen`. The trace identifies the company GL advisory by
   both its SQL and its bound company id, and identifies the last `stock_levels` / `stock_movements`
   statement in the complete writer loop. The assertion is `first company advisory > last inventory
   statement`, which flips without editing the test when T16d buffers scrap posting or T16e moves
   voucher redemption after stock projection.
2. `InventoryGlLockOrderContentionTest` uses two independent PostgreSQL connections to prove that
   the observed advisory-first shape is capable of producing `40P01`, while the terminal-advisory
   target does not. This is a sensitivity control, not the source of a production order claim.

Pairs 1–3 cannot yet be production-traced for inventory GL because their T14/T16 posting wiring is
the indivisible M2 cutover. Their M1 rows are explicitly target-order sensitivity controls, not
claims that those writers already call the seam. Pairs 4/5 have independent production GR ordering
evidence below. Pair 6 has buffer-level D-28 composition evidence below. M2's all-ten-green gate must
run the post-cutover production writer tests; the permanently-green sensitivity controls are not a
substitute for that gate.

- Target order: both roots take inventory before company GL. The second root waits on inventory,
  then takes GL after the first commits. Both statements return SQLSTATE `00000`.
- Broken order: one root takes inventory then requests GL while the other takes GL then requests
  inventory. Both blocking queries are sent before either result is collected; PostgreSQL must
  resolve the AB-BA cycle and returns `40P01` on one connection.
- The pair labels are derived from the M0 D-28 caller sweep and ten-pair register, not reconstructed
  from the plan's original five lanes.

## Pairs 1–6 — required GREEN

Command (real PostgreSQL, local port 5432):

```text
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php
```

Actual result:

```text
PASS  Tests\Feature\Inventory\InventoryGlLockOrderContentionTest
✓ pair 1 — DN confirm x DN confirm
✓ pair 2 — DN confirm x RN confirm
✓ pair 3 — DN confirm x POS projection
✓ pair 4 — counting listener x GR (PO is Received before GL)
✓ pair 5 — POS projection x GR (PO is Received before GL)
✓ pair 6 — multi-DN composite x invoice post
✓ pair 7 sensitivity control reproduces 40P01
✓ pair 8 sensitivity control reproduces 40P01
✓ pair 9 sensitivity control reproduces 40P01
✓ pair 10 sensitivity control reproduces 40P01
✓ advisory acquired inside an aborted subtransaction is released
Tests: 11 passed (126 assertions)
```

The GR `Received` arm is independently production-driven by
`GoodsReceiptGlPostingOrderTest::test_goods_received_fires_after_the_purchase_order_reaches_received`:
two real receipt lines dispatch two real `GoodsReceived` events, and each listener observes the PO
already at `DocumentStatus::Received`. Actual result: `1 passed (3 assertions)`.

Pair 6's D-28 composition arm is independently pinned by
`InventoryGlPostingSeamTest::test_one_root_flush_posts_both_dn_contexts_without_cross_contamination`:
two DN-attributed contexts produce two distinct `inventory_exit` rows through the test's single root
`flushIfOutermost()` call, and leave the scoped buffer empty. Actual result: `1 passed (3 assertions)`.

## Pairs 7–10 — required RED in M1

Command:

```text
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/POS/PosReturnScrapWriteOffTest.php --filter=t11c_scrap

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=*** \
php artisan test tests/Feature/Inventory/InventoryGlVoucherLockOrderTraceTest.php
```

Actual output, reproduced on the ruled M1 tree:

```text
FAIL  Tests\Feature\POS\PosReturnScrapWriteOffTest

pair 7 pos-refund-scrap_x_dn-confirm:
  T16d missing; first_company_advisory=53, last_inventory=79
pair 8 pos-refund-scrap_x_pos-sale:
  T16d missing; first_company_advisory=53, last_inventory=79

Tests: 2 failed (6 assertions)

FAIL  Tests\Feature\Inventory\InventoryGlVoucherLockOrderTraceTest

pair 9 voucher-pos-sale_x_dn-confirm:
  T16e missing; first_company_advisory=13, last_inventory=34
pair 10 voucher-pos-sale_x_pos-sale:
  T16e missing; first_company_advisory=13, last_inventory=34

Tests: 2 failed (6 assertions)
```

These are cause-specific production reds. Pairs 7/8 process a real two-line interactive scrap
return: line one reaches inline movement-keyed GL at query 53, then line two continues inventory
persistence through query 79. Pairs 9/10 project a real voucher-funded POS sale: voucher GL takes
the company advisory at query 13, then stock projection continues through query 34. The four
two-connection controls separately return one `40P01` apiece for the corresponding reversed order.
After M2, the same writer tests must turn green without edits; the separate sensitivity controls
remain green by continuing to prove that a deliberately reversed order is detected.

The two labels in each task arm are intentionally the same per-writer terminality trace crossed with
two counterpart labels; the counterparty is not executed twice. I-1 is a writer-local terminality
property, so once the scrap/voucher writer has no inventory statement after company GL, its cross
product with either terminal writer is safe. The labels express the D-28 matrix rows, not four
distinct fixture bodies.

## Round-1 remediation and revert/replay

- All four `MovementGlKind` dispatch arms are now pinned, including inbound/outbound count
  correction account direction and batch write-off argument/idempotency behavior.
- `enqueue` executes zero queries; a real savepoint rollback discards only its frame; a root rollback
  posts nothing and the next root posts only its own context; a non-default-connection rollback does
  not clear the tenant buffer.
- A two-line return confirmation persists both `return_cost_basis` records and uses each exact cost
  on its resulting stock movement.
- Existing Draft movement entries are repaired on synchronous replay. Both `batch_write_off` and
  `batch_write_off_reversal` are idempotent under their new blocking unique-index predicate.
- The partial-index migration has the pgsql house guard. The local duplicate deploy probe returned
  integer `0` across all five indexed source types.
- I-2 is now an AST rule with positive and comment-decoy/disjoint fixtures, not a whole-file grep.

Actual revert/replay: commit `2a4c67c4b` reverted the production hardening while leaving the tests
installed. The focused run failed three ways: duplicate `batch_write_off` hit `23505`, a replayed
movement remained Draft, and a central-connection rollback erased the tenant buffer. Commit
`a8c797222` reapplied the implementation; after resetting only the dedicated `autoerp_test` public
schema (the first replay run exhausted PostgreSQL's test-schema DDL lock budget), the same run passed
`3 tests (19 assertions)`.

## Per-task red mutation / replay evidence

Each M1 behavioral task now has a committed, test-sensitive mutation followed by a committed revert
and a green rerun:

| Task | Red mutation | Red consequence | Replay | Green result |
|---|---|---|---|---|
| T11 | `6ab4cb163` | Entry dispatch produced `inventory_exit` | `ea0658233` | entry dispatch `1 passed (6)` |
| T11e | `7bfc5ae61` | a direct `postForExit` fixture produced no diagnostic | `b55614da0` | buffer-only rule `2 passed (2)` |
| T12 | `2ad32b56f` | `inventory_entry` mapped to Cash, not Misc | `059e003fd` | mapping `1 passed (6)` |
| T13 | `7ffda98ff` | duplicate reached raw `23505`, losing named precheck | `f129c5879` | precheck `1 passed (2)` |
| T15a | `d0cce02c0` | attributable exits mislabeled `current_cost` | `c150cfc0d` | resolver/payload `2 passed (19)` |
| V-10 | `005aa9434` | stable FEFO `error.reason` missing in en/fr | `d7ac34af4` | refusal tests `2 passed (10)` |

T11c's red-before half is the ruled production failure above; T16c is an audit with no production
change to revert.

## Round-2 merge-gate isolation

The ruled-red voucher trace now lives in
`tests/Feature/Inventory/InventoryGlVoucherLockOrderTraceTest.php`, outside the shared PG gate's
class-name allowlist. The allowlisted
`PosCoreReceiptProjectionRefundDispositionStockTest` contains only green regressions and passes
`7 tests (34 assertions)`. The trace retains the same real writer and cause-specific red output.
The seam class requires real root commits (`connectionsToTransact(): []`), so in-memory SQLite now
loudly skips all 21 PostgreSQL seam methods instead of losing its schema between application
refreshes. Voucher scaling is byte-for-byte restored
to the pre-M1 CompanyContext mechanism. The reversal idempotency guard retains the shipped inline
`postEntry` mechanism; its owning BatchExpiry suite plus voucher actor/refund suites pass `15 tests
(48 assertions)`.

The workflow's exact PostgreSQL class allowlist was also run from `.github/workflows/ci.yml`. Its
M1-owned `PosCoreReceiptProjectionRefundDispositionStockTest` remained green (`7 tests`, `34
assertions`), and the ruled-red dedicated voucher trace was not selected. The broad allowlist ended
with `1004 passed (4159 assertions), 3 skipped, 5 failed`; none of the five failures is in an M1
changed file or an M1-owned test. They were the pre-existing chokepoint manifest's single-line anchor
against a multiline counting call, a one-hour PHP/PostgreSQL timezone mismatch, two existing
refund-chain arithmetic assertions, and a support-access test configured for the absent
`iziposcentral` database. This broad run is diagnostic only: the harness requires PostgreSQL tests
by path, and the M1-owned by-path evidence above is green except for the four red rows required by the
sequencing ruling.

## Aborting subtransaction cannot-verify

The same test acquires the company advisory after a savepoint, proves a second connection cannot
take it, rolls back to that savepoint, then proves the second connection can take it while the root
transaction remains open. PostgreSQL therefore releases a transaction-scoped advisory acquired
inside an aborted subtransaction. The buffer design does not rely on that behavior: its explicit
`mark()` / `rollbackTo()` still discards savepoint-local contexts.

## Round-4 final-fix evidence

- V-10 is now scoped to the guided invoice endpoint by an explicit
  `requireCompleteFefoAllocation` factory option. The existing SO-to-invoice caller retains its
  pre-M1 unbatched fallback and completes atomically; its new HTTP regression passes `1 test (6
  assertions)`. The full converter suite passes `8 tests (51 assertions)`, while the guided suite
  still passes `12 tests (48 assertions)` including en/fr typed refusals and zero residue.
- `ReturnCostBasisResolver` and its DTO moved from Inventory Application to Inventory Domain, so the
  M1-added `ReturnNoteService -> ReturnCostBasisResolver` Domain-to-Application violation is gone.
  The deptrac aggregate improved from the review's 117 to 116 violations; the command still fails on
  pre-existing baseline drift, and the only remaining violation in an M1-touched consumer is the
  already-shipped `ReturnNoteService -> WeightedAverageCostService` dependency. No M1 resolver
  dependency appears in the JSON report.
- `InventoryGlPostingViaBufferOnlyTest` now has direct-call and enqueue-only fixtures and runs both
  locally and in CI. Mutation `7bfc5ae61` bypassed every `postFor*` call and made the diagnostic test
  fail; restore `b55614da0` returned it to `2 passed (2 assertions)`. The complete PHPStan-rule
  directory passes `10 tests (10 assertions)` and `.github/workflows/ci.yml` now executes that
  directory in both the regular backend job and manual full-backend partition.
- Both ruled-red production trace methods loudly skip their two cases on SQLite with a `[PG]`
  reason, then reproduce their four cause-specific failures on PostgreSQL. The stale contention
  docblock now names the dedicated voucher trace class.
- The root-rollback listener first checks whether the scoped buffer was resolved, avoiding eager GL
  graph construction for unrelated rollbacks. The new negative test proves an unrelated root rollback
  leaves the buffer unresolved; the complete PostgreSQL seam is `22 passed (91 assertions)`.
- Return-basis payload rows are keyed by `line_id` and written once after the loop. A seeded stale row
  for one of two lines is replaced instead of appended; the resolver/confirm suite passes `3 tests
  (24 assertions)`. New quantity normalization uses `QuantityScale`.
- The touched-file PHPStan run is clean. The declared `tests/Unit/Inventory` directory still exposes
  two base-tree errors in `GoodsReceiptDataTest` (`GoodsReceiptData.php:73`, null relation name); neither
  file is changed in this range. Full-tree PHPStan likewise retains two untouched hard-coded scale
  findings. Both are recorded rather than expanded into M1.
