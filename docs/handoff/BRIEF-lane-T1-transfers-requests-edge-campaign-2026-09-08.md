# Lane brief — T-1: stock transfers + replenishment requests edge-case campaign (2026-09-08)

Parent: `docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md` (benchmark table §0, survey §1, owner defaults Q1–Q5 confirmed 2026-09-08). Scope: **tests and evidence only** — no feature code. Any defect found is fixed only if the fix is ≤ 20 lines and inside the pinned seam; otherwise it is ticketed under `docs/superpowers/tickets/2026-09-08-t1-<short>.md` with the red test kept `markTestSkipped('ticket …')` until its lane lands.

Worktree `apps/erp/.worktrees/t1-transfers` off local `dev` (`d80d421f3`+). PG DB `autoerp_test_t`. Run tests by file only. Gates: `inventory-costing-reviewer` (+ `stock-gl-interaction-reviewer` if any cost/GL assertion is added).

## Cases to pin (red-first where the code is wrong; green pins otherwise, recorded as "green on first run")

Transfers (`apps/api/tests/Feature/Inventory/StockTransferEdgeCasesTest.php`, PG-named variants where the behaviour depends on locks):
1. `complete()` twice: second call is a typed 422 / no-op, exactly one `transfer_in` movement per line (idempotent completion).
2. `complete()` concurrent (two processes, PG, `FOR UPDATE` on the transfer row): one wins, one refused, stock delta counted once. Mirror `StockTransferIdempotencyCollisionPostgresTest` harness.
3. `cancel` after `completed` and `complete` after `cancelled`: refused via `TransferStatus::canBe*` (`TransferStatus.php:26-40`), no movement written.
4. Batch recalled/expired **after** initiate, before complete: what happens at complete (allocation already made)? Pin the current behaviour and benchmark it (Odoo: reserved move lines stay; ERPNext: transit stock unaffected). Ticket if a recalled lot silently lands at destination.
5. In-transit derived quantity: while `in_transit`, location matrix / location stock / WAC each show the same in-transit figure (`LocationStockQueryService.php:144,228`, `StockMatrixQueryService.php:402`, `WeightedAverageCostService.php:124`); after cancel it returns to source; after complete it is at destination. One test per reader, same fixture.
6. Cost capitalization ordering (`StockTransferService.php:387-391`): status persisted before capitalization — pin with a PG test that reads WAC inside the same transaction.
7. Negative stock on the transfer path: initiate refused when available < requested including reservations (not only on-hand); variant-grain and batch-grain both.
8. Location-restricted user: can complete an incoming transfer to their location, cannot complete an outgoing one; cannot see a transfer between two other locations (extend `StockTransferLocationScopeTest` only if a gap is found).
9. Second company: a transfer in company B is invisible to A on index/show/complete/cancel (404, not 403), same tenant.
10. Idempotency key reuse with a *different* payload: refused, not silently replayed (check `:106-190`).

Replenishment (`apps/api/tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php`):
11. Bump while a transfer created from the request is `in_transit`: settlement must not double-settle nor reopen (`SettleRequestsOnTransferInitiated`).
12. Cancel the transfer → request reopens → a second open row for the same grain now exists (from a new POS capture) → reopen must **merge**, never violate the partial unique (`replenishment_open_non_variant`); PG only.
13. Request → PO append: a draft PO for the same supplier exists in **another company** → must not append (new PO in the right company).
14. Request cancel by requester after processor started (`in_progress`): refused or allowed? Pin current behaviour, benchmark (Odoo: cancel allowed until done; ERPNext: Material Request can be stopped). Ticket if inconsistent with the status enum's `isOpen()`.
15. Location-restricted processor creates a transfer whose source they cannot see: 422 (`StockTransferLocationAccessRuleTest` precedent), request stays open.

Web/API probes (Playwright optional, API `curl` evidence mandatory) against the local stack for cases 1, 3, 5, 12.

## Deliverables
- Test classes laned in `apps/api/tests/feature-lane-manifest.json` (Inventory + Replenishment groups; Inventory is parked → allowlist entry in `.github/workflows/ci.yml` `backend-test-pgsql` + ceiling raise with a truthful note, same precedent as PR #221).
- Evidence `docs/superpowers/reviews/2026-09-08-t1-transfers-requests-edge-evidence.md`: per case → test name, first-run colour, benchmark line, ticket link if red.
- Handback `docs/handoff/HANDBACK-T1-2026-09-08.md`.
