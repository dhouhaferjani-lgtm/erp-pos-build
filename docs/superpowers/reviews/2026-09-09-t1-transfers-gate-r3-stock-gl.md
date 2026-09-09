# Gate r3 — lane T-1 (stock-gl-interaction-reviewer, 2026-09-09)

**Audited HEAD:** `af6e83028` (branch `lane/t1-transfers-edge`, worktree `.worktrees/t1-transfers`). **Delta reviewed:** `a2fd11174..af6e83028` (5 commits: `e769e768d`, `58fe90cde`, `568f3d38f`, `758fabeb1`, `af6e83028`) — 13 files, +167/−53, all tests and docs. **Production delta this round is empty**; whole-lane numstat is still `+26/−2` on `apps/api/app/Modules/Replenishment/Presentation/Requests/CreateTransferFromRequestsRequest.php` (ratified). The superseded rev-1 spec is byte-identical to the lane base.

**Runs executed (SQLite by file only; no PostgreSQL run, per instruction):** `StockTransferEdgeCasesTest` OK 17 tests / 89 assertions / 2 skips; `ReplenishmentEdgeCasesTest` OK 9 / 58 / 3 skips; `StockTransferCompleteConcurrencyPostgresTest` 2 PG-only skips, 0 assertions (the `markTestSkipped` at `:54-56` fires before any fixture write); `php tools/feature-lane-manifest-check.php` exit 0.

## Round-2 closure table

| Item | Status | Evidence at HEAD |
|---|---|---|
| **MAJOR-1** zero-`journal_entries` pins + ticket, no GL added | **CLOSED** | `StockTransferCompleteConcurrencyPostgresTest.php:138-139` (before), `:142` (inside the completing transaction), `:152` (after the outer `DB::transaction` returns — post-commit, so a future `InventoryGlPostingBuffer::flushIfOutermost()` posting could not hide behind the buffer); `StockTransferEdgeCasesTest.php:202-203,207,211` around both `recordCostAdjustment` calls and across the terminal action. Ticket `docs/superpowers/tickets/2026-09-09-t1-freight-capitalization-no-gl.md:5,7,11` states the document-per-action gap, cites `StockTransferService.php:631-709` (exact at HEAD), the missing freight-clearing/expense counterpart, the COGS double-count risk, and requires any future posting to go through `InventoryGlPostingBuffer` and replace the zero-journal pins with balanced exactly-once assertions. No GL posting added. |
| **MAJOR-2** source-side `BatchStock` = `'6.0000'` + equality with source `stock_levels.quantity` | **CLOSED** | `StockTransferEdgeCasesTest.php:125-127` (recall) and `:139-141` (expiry): `where('batch_id', $batch->id)->where('location_id', $this->source->id)` — source, not destination (destination pin is `:124` / `:138`); the batch is the one FEFO-allocated (`:288`, `:291-296`). Seeded 10 (`:282`) − 4 shipped ⇒ 6.0000 on both ledgers. |
| **m1** raced transfer carries `10.000` freight; loser refused ⇒ `cost_price '6.000000'` + exactly one `Adjustment` | **CLOSED (by code reading; PG not re-run this round)** | `:91` (`$this->transfer('10.000')`), assertions `:131-132`. `StockTransferService.php:391-394` persists `Completed` before `:400-401` capitalises; `:696-707` calls `recordCostAdjustment` once per cost-bearing line with `referenceId: $transfer->id` (`:706`); `WeightedAverageCostService.php:812-835` creates exactly one `Adjustment` row and writes `5 + 10/10 = 6.000000` (`:119-127`). A double capitalisation would produce two rows and 7.000000; the loser exits at `canBeCompleted` (`StockTransferService.php:322-323`), asserted at `:127`. |
| **m2** production-delta framing | **CLOSED (ratified)** | evidence `:5` states "whole-lane total +26/−2, explicitly ratified by the orchestrator". |
| **m3** evidence wording | **CLOSED** | evidence `:288`: same 403 envelope only for the hidden-location case; cross-company remains the controller's 422; no `ScopedExists`; `ValidLocationAccess` checks neither existence nor company for an unrestricted membership. |
| **m4** movement snapshot on the case-15 denial | **CLOSED** | `ReplenishmentEdgeCasesTest.php:211` (snapshot) / `:219` (re-assert). |
| **m5** settlement-replay ticket | **CLOSED** | `2026-09-09-t1-settlement-replay.md:23`: demand only, no stock/journal/cash, no compensating stock or GL writes; swallowed per-line failures at `SettleRequestsOnTransferInitiated.php:33-40`. |
| **m6** recalled-in-transit valuation arm | **CLOSED** | `2026-09-09-t1-recalled-in-transit.md:19`: scrapping an accepted recalled lot requires a write-off movement plus GL posting through `InventoryGlPostingBuffer`; quarantine cannot be a flag alone. |
| **m7** exclusive-database contract / no schema bomb | **CLOSED** | docblock `:29-33`; `DatabaseMigrations`, `runDatabaseMigrations()` and `db:wipe` gone; cleanup `:78-84` = six tenant-scoped deletes plus company/user/location/tenant by id with `assertSame(1, … tenants … delete())` as positive control; `stock_transfer_line_batch_allocations` has `tenant_id` (`2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:22`); `ProductFactory` creates no side rows. |
| **m8** reserved-unit distinction | **CLOSED** | `StockTransferEdgeCasesTest.php:172-179`; per `LocationStockQueryService.php:129-135,176-180`: in transit → 6−1 = 5.0000; after cancel → 10−1 = 9.0000; after complete → (6−1)+(4−0) = 9.0000; without the reserved subtraction the values would be 6/10, so the pin distinguishes available from physical while `assertTerminalStock` (`:300-302`) keeps physical pinned. |

## BLOCKER

None. No stock/GL/projection writer added or made reachable; no `journal_entries` row created anywhere on this path; no append-only ledger row rewritten; no float on the seam (every new literal is a decimal string at column scale; no `assertEquals`, `(float)`, `floatval`, `round(`, `number_format` in the three touched test files).

## MAJOR

None.

## MINOR

**[n1] `docs/superpowers/reviews/2026-09-09-t1-transfers-requests-edge-evidence.md:31` and `:292` are now false at HEAD** — both still describe the reverted harness (`DatabaseMigrations`, whole-schema `db:wipe`, `RefreshDatabaseState` reset, "0 public base tables"). At HEAD the class uses neither (`:34-35` no trait; `:78-84` tenant-scoped clean). Only the handback's "Fix round 2" table records the reversal; T-2 will open the evidence review. *Fix:* two sentences, or a "superseded in fix round 2, see HANDBACK §Fix round 2" marker at `:31` and `:292`.

**[n2] `StockTransferCompleteConcurrencyPostgresTest.php:74-85` — the cleanup guard is `isset($this->tenant)` but the body dereferences `$this->company`, `$this->user`, `$this->source`.** If `setUp` throws after `:61` and before `:67`, `tearDown` raises a typed-property error that masks the real failure and the committed tenant/company rows survive in the exclusive DB. Robustness, not correctness. *Fix:* guard per group, or `try { … } finally { parent::tearDown(); }`.

*(Noted, not a finding: `assertSame(0, $journalsBefore)` at `:139` is a global, unscoped count in a committing class — can only produce a false red, never a false green.)*

## Verified fine

1. Both sides of the seam pinned for every closed item: stock side (`stock_levels`, `BatchStock`, `TransferIn`/`Adjustment` counts at `StockTransferEdgeCasesTest.php:124-127,138-141,300-302`; `…ConcurrencyPostgresTest.php:128-132`); GL side verified absent and pinned absent in both value-bearing paths. Absence is structural (no GL collaborator in `StockTransferService`, no listener on the three transfer events, no GL subscriber on `StockMovementRecorded`).
2. MAJOR-2 pins are not SQLite-masked (single-row `sole()->quantity` string comparisons, exercised on SQLite: 89 assertions vs 79 at r2).
3. Tests assert data meaning, not status codes; case-15 denial asserts refusal and absence of stock movement.
4. Document-per-action defect recorded, in-lane remediation forbidden — correct posture for a pinning lane.
5. "Seams handed to T-2" (r2 register §Seams 1–7) all still true at HEAD; re-derived anchors: `capitalizeTransferCost` `:631-709`; ordering `:386-394`/`:400-401`; `markMovementAsTransfer` `:745-752`; WAC denominator `WeightedAverageCostService.php:119-127`. Seams 2 and 4 now additionally backed by the freight ticket and the source-side lot pins.
6. Manifest/CI wiring unchanged this round; checker passes at HEAD.

**One line to fix before merge (non-blocking):** correct the two stale db:wipe sentences in the evidence review (`:31,292`); the `tearDown` guard is optional hardening. MAJOR-1/m1 PostgreSQL execution is asserted by the handback (2 tests / 20 assertions) and verified here only through code derivation.

VERDICT: MERGE
