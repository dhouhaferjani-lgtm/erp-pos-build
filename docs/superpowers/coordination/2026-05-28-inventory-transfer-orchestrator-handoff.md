# Orchestrator Handoff — Inventory Transfer remediation vs T6 DB-per-tenant flip

**Date:** 2026-05-28
**From:** Inventory Transfer session (`feat/inventory-transfer`, PR #147)
**To:** Orchestrator

---

## What you need to decide

PR #147 (Inventory Transfer, Scenario A intra-company) is in adversarial-review. The remediation plan at `apps/erp/docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md` has 10 batches. Two of them touch ground that overlaps with the in-flight T6 DB-per-tenant flip, and I need you to assign ownership before I execute.

**Specifically:**

1. **Batch G — relocate `2026_05_28_120000_create_stock_transfers_table.php` from `apps/api/database/migrations/` to `apps/api/database/migrations/tenant/`.** The new `stock_transfers` and `stock_transfer_lines` tables are tenant-scoped and `apps/api/config/tenancy.php:197` tells `tenants:migrate` to read only the `tenant/` folder. After the flip lands, the migration must be in `tenant/` or tenant-DBs will be missing the tables. This single-file move could live in this PR or in the T6 session's coordinated push.

2. **Batch H — flip the master docs from "row-level multi-tenancy" to "DB-per-tenant".** Touches:
   - `CLAUDE.md` (repo root)
   - `claude/architecture.md`, `claude/database-topology.md`, `claude/shared-patterns.md`
   - `apps/erp/CLAUDE.md`
   - `apps/erp/.claude/context/architecture.md`
   - `apps/erp/docs/architecture/database.md`
   - Memory file `project_tenancy_model_truth.md`

These docs describe a model that no longer matches the code as the T6 flip ships. The owner who lands them needs to coordinate with the T6 work so the new wording matches the actual final topology.

---

## Background you need

### What PR #147 ships

- T1 spec Phase 2 (intracompany scope): a multi-line stock-transfer document with lifecycle `draft → in_transit → completed | cancelled`.
- New `stock_transfers` + `stock_transfer_lines` tables, three domain events, idempotency_key column.
- `StockTransferService::initiate / complete / cancel` plus a new `WeightedAverageCostService::recordCostAdjustment` seam for capitalizing additional costs into the company-wide WAC.
- Frontend feature at `apps/web/src/features/stock-transfers/` (List, Create, Detail).
- 12 backend tests + 1 frontend smoke test, all green; PHPStan level 8 + Pint clean; full Inventory suite (133 tests) still green.

### Spec + design note

- Spec: `apps/erp/docs/superpowers/specs/2026-05-24-t1-stock-transfer.md`
- Design note: `apps/erp/docs/superpowers/coordination/2026-05-28-inventory-transfer.md`
- Remediation plan (this session): `apps/erp/docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md`

### Adversarial reviews

- **Codex** (REQUEST-CHANGES, high confidence): `apps/erp/docs/superpowers/reviews/2026-05-28-inventory-transfer-codex-review.md` — 2 BLOCKERs, 4 P1, 8 P2, 3 P3. Codex caught two findings Opus missed: WAC capitalization is order-dependent under concurrent in-transit transfers (financial-correctness issue), and `StockMovementRecorded` events emit `issue`/`receipt` after the persisted row is re-labeled to `transfer_out`/`transfer_in` (event consumers see wrong type).
- **Opus** (APPROVE-WITH-MINOR-EDITS, high confidence): `apps/erp/docs/superpowers/reviews/2026-05-28-inventory-transfer-opus-review.md` — 0 BLOCKER, 2 P1, 7 P2, 7 P3. Big findings: missing `idempotency_key` on `complete`/`cancel`, frontend never sends `idempotency_key` on create, list page has no pagination controls, create-page swallows server-error details.

### Status of the T6 flip

Parallel worktrees I can see:

- `apps/erp.t6-phase0b` on branch `feat/...` — last commit ~8h ago, focus: DB-mode register fixes (stdout pollution, connection-leak in central-table validation, e2e signup/auth blockers).
- `apps/erp.a4-preflip-ops` on branch `feat/t6-preflip-ops` — most recent commit 3 minutes ago, focus: per-tenant backup/restore service + tenant:restore CLI, PgBouncer service, post-flip ops runbook.

Both suggest the flip is **actively landing** — moving past raw migrations into operational tooling. Migrations in their `tenant/` folder go up to `2026_05_27_*`.

`apps/api/config/tenancy.php:197` confirms `tenants:migrate` reads only `database/migrations/tenant`.

### Memory of the row-level reality

User memory `project_tenancy_model_truth.md` says: "Multi-tenancy is ROW-LEVEL (`tenant_id`/`company_id` columns + query scoping in one shared PostgreSQL DB) — NOT schema-per-tenant, despite the Stancl schema-manager config. Target = DB-per-tenant (greenfield, no data)."

The "Target = DB-per-tenant" is the in-flight T6 work. The memory is technically out of date — DB-per-tenant is no longer a future target, it's mid-flight reality.

---

## What the inventory-transfer session is asking

Two narrow questions:

1. **Should Batch G (the single `git mv`) land in PR #147, or should the T6 session own it in a coordinated commit alongside the flip?**
2. **Should Batch H (the doc flip) land in PR #147, or be owned by a T6-session PR or a dedicated doc-flip PR?**

The third question this handoff doesn't ask but you might want to weigh in on:

3. **Is there a coordination doc that names the canonical owner for "rewriting CLAUDE.md when the flip lands"?** If yes, point me at it; the inventory-transfer session will write a forward-pointer instead of doing the rewrite.

---

## Recommended answer

If you want the cleanest sequencing:

- **Batch G** → land in PR #147. It's one `git mv` plus a sanity-check that the test bootstrap still picks up the table. The cost of waiting for the T6 session is higher than the cost of moving it now and having T6 inherit a slightly-different table-placement state.
- **Batch H** → land in PR #147 *if* the inventory-transfer session is the first to touch CLAUDE.md after the flip becomes the durable reality. If the T6 session is already drafting its own doc rewrite, hand the inventory-transfer session a forward-pointer and drop Batch H from the plan.

If you want the T6 session to own both, that's a single answer the inventory-transfer session will respect. The cost on the inventory-transfer side is a forward-pointing comment in the design note.

---

## What to reply to the inventory-transfer session

Tell me one of:

- **"land both G and H in PR #147"** — I execute as planned.
- **"land G, defer H to T6"** — I execute G, drop H, add a forward-pointer comment in the design note linking to the T6 coordination doc you name.
- **"defer both"** — I drop G and H, add forward-pointer comments, and document the coordination dependency in the design note.

Then I proceed to execute Batches A through F + I + J (which are entirely inside the inventory module + frontend + verification — no overlap with T6).

If you want me to make the call myself based on the facts above, say so and I'll pick.
