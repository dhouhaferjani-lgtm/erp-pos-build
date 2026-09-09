# Codex Desktop dispatch — lane T-1 (paste as a NEW thread named `T-1 transfers/requests edge campaign`)

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Create your worktree first: `git worktree add .worktrees/t1-transfers -b lane/t1-transfers-edge dev` (base = local `dev`, currently `0cd80eb2d` or later). Work ONLY inside that worktree. PostgreSQL test DB for this lane: `autoerp_test_t` (set `DB_DATABASE` and `DB_CENTRAL_DATABASE` to it for PG legs; port 5433 container, see `docs/handoff/BRIEF-lane-T1-transfers-requests-edge-campaign-2026-09-08.md` §recipe and `scripts/run-feature-lane-local.sh --dry-run` for the env the CI lane builds).

Brief (authority): `docs/handoff/BRIEF-lane-T1-transfers-requests-edge-campaign-2026-09-08.md` — 15 cases, tests and evidence only. Parent context: `docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md` §0–§1.

Rules: `CLAUDE.md` rules 2 (TDD), 3, 4 (no scope creep: a defect is fixed in-lane only if ≤ 20 lines inside the pinned seam, else ticket + `markTestSkipped('ticket …')`), 9, 13, 19 (quantities as decimal strings, never floats), 20; `docs/conventions/09-SECOND-OF-EVERYTHING.md`. Never run the full PHPUnit or Vitest suites — by file only. Do NOT edit `StockTransferService.php` beyond a ≤ 20-line in-seam fix (lane T-2 owns its lifecycle). Do NOT touch inventory-counting files.

Deliverables, in this order, one commit each:
1. `apps/api/tests/Feature/Inventory/StockTransferEdgeCasesTest.php` (cases 1, 3, 4, 5, 7, 8, 9, 10) and `StockTransferCompleteConcurrencyPostgresTest.php` (cases 2, 6; PG-only, mirror `StockTransferIdempotencyCollisionPostgresTest`'s harness).
2. `apps/api/tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php` (cases 11–15; case 12 PG-only).
3. Lane wiring: `apps/api/tests/feature-lane-manifest.json` (Inventory group is PARKED → deliberate ceiling raise with a truthful note; Replenishment group per its current state) + `.github/workflows/ci.yml` `backend-test-pgsql` allowlist entries for the PG classes with the standard comment block (precedent: `CountingMovementReferenceTest`, PR #221). Run `php tools/feature-lane-manifest-check.php`.
4. Tickets for every red that is not fixed in-lane: `docs/superpowers/tickets/2026-09-09-t1-<short>.md` (symptom, `path:line`, benchmark line from the brief, proposed fix, acceptance).
5. Evidence `docs/superpowers/reviews/2026-09-09-t1-transfers-requests-edge-evidence.md`: one row per case → test method, first-run colour (RED/GREEN), what it pins, ticket link; plus the `curl` transcripts for cases 1, 3, 5, 12 against a local API (`php artisan serve --port=8011` from the worktree; demo tenant per `reference_local_db_per_tenant_demo_launch`).
6. Handback `docs/handoff/HANDBACK-T1-2026-09-09.md` (what changed, what is owed, resume recipe). Do NOT merge into `dev`, do NOT push to origin. Stop with `status: review` — the orchestrator session gates with `inventory-costing-reviewer` and merges.
