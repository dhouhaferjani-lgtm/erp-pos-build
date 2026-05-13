# Codex review prompt — non-TanStack tenant-isolation sweep, final cumulative review

**PR:** [#93](https://github.com/otospexsolutions/erp/pull/93) — title "Close non-TanStack tenant-isolation sweep"
**Base:** `dev` (tip `70ea2bcc` "docs(pos): Opus final audit of go-live PRs #1-#4 (ACCEPT)")
**Head:** `feat/tenant-isolation-sweep-execution` (tip `f68ed8c4` "fix(workshop): drop orphan V1 WorkOrderCompleted import after listener cleanup")
**Cumulative scope:** 177 commits, 337 files, +53520 / -6655 lines
**Diff anchor command:** `git diff origin/dev...origin/feat/tenant-isolation-sweep-execution`

## Purpose

You have already reviewed this work piece-by-piece — every task in the closure plan has at least one Codex round of review, most have two, and Opus has signed off on both the plan adversary pass and the final closure at `1d63d664`. This is a **cumulative review pass**: read the work as a single delta vs. `dev` and look for things that emerge only at the seam between tasks, or that the per-task scopes did not surface.

You are NOT being asked to re-litigate per-task or per-cluster decisions that already carry an APPROVE verdict — see the review trail in the PR body. Treat those as load-bearing precedent. Focus on **cross-cutting integrity** of the final delta.

## Scope categories (so you can budget attention)

| Category | Volume | Review priority |
|---|---|---|
| Production PHP (`apps/api/app/`) | 60 files, +1064 / -184 | **High** — substantive logic |
| Test PHP (`apps/api/tests/`) | 29 files, +1300 / -51 | **Medium** — coverage gaps + assertion strength |
| Frontend (`apps/web/`) | 154 files, +13441 / -691 | **Low / spot-check** — mechanical `tenantScopedKey()` wraps, each batch already APPROVE'd individually |
| Inventory YAML (`docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`) | 1 file, +32744 / -4746 | **Spot-check** — auto-generated callsite records; rely on `verify-history` (already green) |
| Audit/plan/review docs (`docs/superpowers/`) | 94 files, +37715 / -5729 | **None** — these are review artifacts, not under review themselves |

## Gates already verified at HEAD `f68ed8c4`

| Gate | Result |
|---|---|
| `php artisan sweep:inventory:verify-history` | 6337 events / 1208 callsites / 0 problems |
| `php artisan sweep:inventory:status --drift` | yaml_says_fixed_code_unsafe=0, code_safe_yaml_pending=0, unmapped_in_scanner_output=0 |
| `vendor/bin/phpunit` closure matrix (Sweep + Architecture + Workshop + Refund residual + Loyalty + Taxation) | 388 tests / 1319 assertions OK |
| `vendor/bin/phpstan analyse` closure slice | 0 errors |
| `vendor/bin/phpunit tests/Feature/POS` (post-merge of `dev`) | 616 tests / 2120 assertions OK |
| CI on `f68ed8c4` | Pint + PHPStan + ESLint + TypeScript + Types-Drift all SUCCESS (test jobs intentionally gated to `main` PRs) |

You do not need to re-run these. If you doubt a number, run the command yourself — don't take the table on trust.

## Closure-plan tasks already separately reviewed

Per the PR body and `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md`:

1. **Task 1/2** — Prune fixed manual stub rows. (Reviewed; APPROVE.)
2. **Task 3 Step 1** — Remove `tax_configurations` + `tax_rates` from `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`. (Reviewed; APPROVE. Audit anchor: `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`.)
3. **Task 3 Step 2** — `ExistsRuleVisitor` walks into closure callbacks + nested subqueries; conjunctive positions only. (Reviewed.)
4. **Task 3 Step 3** — `FindCallVisitor` recognizes `Model::where('tenant_id', $tid)->find*` chains with a strict value-source allowlist. (Reviewed.)
5. **VoucherController refactor** — local `$tenantId = $user->tenant_id` extraction. (Reviewed.)
6. **Task 4 (Workshop)** — Additive scoped repository methods; V1 → V2 `WorkOrderCompleted` event migration; full HTTP cross-tenant 404/422 matrix on 14 WorkOrder + 8 Bundle endpoints. (Reviewed; APPROVE — see `docs/superpowers/reviews/2026-05-12-api-workshop-task4-closure-claude-review.md`.)
7. **Task 5** — `sweep:inventory:recheck-clear` command; 14 `needs_recheck` rows cleared; Loyalty stamp-card validation refactored to repository pattern. (Reviewed.)
8. **Cleanup** — `EventServiceProvider.php`: dead V1 listener mapping removed at `6bd8f173`; orphan V1 import removed at `f68ed8c4`. (`f68ed8c4` is post-Opus-closure-review; it is in-scope here.)

## What this cumulative review must check

This is the gap. The per-task reviews did NOT cover:

### A. Cross-task interactions

1. **Task 3 scanner changes vs. Task 4 Workshop find-call patterns.** The new `FindCallVisitor` allowlist for `where('tenant_id', $tid)->find*` chains was tightened. Verify Workshop's new scoped repository methods (`findByIdForScope` etc.) either fit the allowlist OR are correctly out-of-scope of the visitor (e.g., they're scoped repository calls, not direct `Model::where` chains). If they fit, confirm the visitor accepts them; if they don't, confirm there's coverage by another path (architecture test, integration test).

2. **Task 5 `recheck-clear` vs. Task 4 Workshop atomic-mutation precedent.** Task 4 introduced atomic-mutation precedent for needs_recheck transitions. Task 5 introduced a CLI command for the same surface. Verify they don't disagree on `coordination_history` event shape — the verify-history invariant cares about this. Specifically: events emitted by `recheck-clear` must be consistent with events emitted by atomic-mutation paths (event kind, anchor callsite, hash chaining).

3. **Workshop event V1 → V2 migration.** The V1 `WorkOrderCompleted` event class is retained for queue-serialization compatibility (immutable-events rule); only the listener mapping was moved to V2. Verify:
   - All Workshop code that DISPATCHES `WorkOrderCompleted` was migrated to `WorkOrderCompletedV2` (grep `event(new WorkOrderCompleted(` and `WorkOrderCompleted::dispatch(`).
   - No remaining listener references V1 (the cleanup commit removed the orphan mapping + the orphan import).
   - The queue-drain note in the PR body is sound: any V1 event queued pre-Task-4 deploy will silently drop its `CloseTimeEntryOn…` + `MirrorAppointmentOn…` side-effects once the cleanup deploys. Confirm this is the only deploy ordering caveat — no other event class has the same V1/V2 split.

### B. Cross-cluster tenant-isolation integrity

4. **Cross-tenant exfiltration paths.** Inspect any new query that returns a model collection or single-model lookup added in `apps/api/app/` in this delta. For each, confirm it is either:
   - Constrained by a `tenant_id` or `company_id` WHERE clause (direct or via repository), OR
   - Scoped via a Spatie team boundary (`SetPermissionsTeam` middleware), OR
   - Operating on a structurally country-scoped or system-scoped table (e.g., `country_tax_rates`, `tax_configurations`).

   If you find any unconstrained query that returns tenant-bearing rows: list it as a BLOCKER finding. The accepted residual `api.identity-company.001` is the only documented exception (country-scoped `tax_configurations`).

5. **Listener payload contracts.** When a V1 listener was moved to a V2 event, the V2 event may carry different payload fields. Verify all listeners moved to V2 (currently `CloseTimeEntryOnWorkOrderCompleted`, `WriteMileageReadingFromWorkOrderCompleted`, `MirrorAppointmentOnWorkOrderCompleted` per `EventServiceProvider.php:89-93`) can construct their work units from V2's payload without falling back to a database lookup that itself relies on tenant context the queue worker may not have. The classic failure mode is "listener loaded by queue worker without tenant context, performs `Model::find($id)` instead of a scoped query".

6. **Scanner false-positive surface.** Task 3 removed `tax_configurations` + `tax_rates` from `DEFAULT_GUARDED_TABLES` because they're country-scoped. Verify no other PHP `exists:<table>,<col>` rule in the delta would now be over-flagged or under-flagged. Specifically check Workshop request classes (Task 4 added technician body-validation hardening): every new `exists:` rule must be either (a) for a tenant-scoped table — in which case the visitor should approve it via `ScopedExists::tenantAndCompany`, or (b) for a structurally-isolated table — in which case it must be in the guarded list. Anything in between is a finding.

### C. Test coverage gaps

7. **Cross-tenant HTTP 404/422 matrix completeness.** Task 4 added the matrix for 14 WorkOrder + 8 Bundle endpoints. Verify no other Workshop endpoint (or any new endpoint added in this delta) is missing from the matrix. If you find one, list as a P2 (not a BLOCKER, since the structural scoping likely holds — but the matrix is the explicit defense-in-depth gate).

8. **Loyalty repository refactor coverage.** `RewardRepositoryInterface::existsForProgramInTenant` + `existsInTenant` are new. Verify both have at least one negative-path test (cross-tenant: same id + different tenant returns false). Same axis for any new repository method added under `app/Modules/Loyalty/`.

### D. Known accepted residuals (do NOT re-litigate)

- **`api.identity-company.001`** — `UpdateCompanyRequest.php:43` `exists:tax_configurations,id`. Country-scoped table; cross-tenant exfiltration structurally impossible. Audit anchor: `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`. Revisit by 2026-08-01.
- **Zero-callsite clusters** — `web.form-selectors`, `web.stores-localstorage` (no scanner built yet); `api.unmapped` (cluster-only-mutation §16 structural gap); `tauri.sqlite-cache`, `tauri.sync-envelope` (POS orchestrator-owned). These are tracked as scanner-build follow-ups, NOT as missing closure work.
- **Line-number drift in inventory YAML** — `sweep:inventory:generate --dry-run` at HEAD reports `32 new / 0 unchanged / 33 needs_recheck / 66 stale_orphan`. This is line-shift accumulation during the PR; stabilization runs post-merge on `dev`. Per the deferred-follow-ups list in the PR body. Do NOT call this a BLOCKER.

## Inputs

- **Diff:** `git diff origin/dev...origin/feat/tenant-isolation-sweep-execution`
- **PR description:** PR #93 body (most current closure framing — supersedes the original §17 audit-coordination body).
- **Closure plan:** `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md`
- **Master plan:** `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md`
- **Inventory state:** `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` (at HEAD)
- **Per-task reviews (read for context, not re-review):**
  - Opus final closure review: `docs/superpowers/reviews/2026-05-12-non-tanstack-sweep-final-closure-claude-review.md`
  - Opus Task 4 closure review: `docs/superpowers/reviews/2026-05-12-api-workshop-task4-closure-claude-review.md`
  - Codex adversary review of closure plan: `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-adversary-review.md`
  - Codex final-approve on closure plan: `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-final-approve.md`
  - Per-cluster Opus reviews: `docs/superpowers/reviews/2026-05-12-*-cluster-claude-review.md` (20 clusters)
- **Deploy note context:** PR body `## Deployment note` — queue-drain between commits `01574841` and `6bd8f173`.

## Verdict file

Save your review at:

```
docs/superpowers/reviews/2026-05-12-non-tanstack-sweep-final-cumulative-codex-review.md
```

Create the directory if it does not exist. Do not return the review inline — only confirm the file path in your final message.

**Required format:**

- First non-empty line: `Commit reviewed: f68ed8c4` (single SHA, no parentheses, no annotations).
- Verdict line (anywhere in the file, exact match): one of
  - `Verdict: APPROVE`
  - `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED`
  - `Verdict: REQUEST-CHANGES`
- If `REQUEST-CHANGES`: enumerate findings as `F1`, `F2`, `F3`, …, each with category (BLOCKER / P1 / P2 / P3), file:line references, and a concrete remediation suggestion. BLOCKER = correctness or security regression that must be fixed pre-merge. P1 = should-fix pre-merge but not strictly blocking. P2 = follow-up after merge. P3 = nice-to-have.
- If `APPROVE-WITH-MINOR-EDITS-APPLIED`: list the minor edits you'd suggest as `M1`, `M2`, … with the same severity scale. The expectation is they are non-blocking; merge proceeds without them.
- If `APPROVE`: nothing else required, but a short paragraph summarizing what you cross-checked (so the audit trail is non-empty) is appreciated.

## Tight pattern check

The expected verdict for a cumulative review at this stage (every per-task piece already APPROVE'd, all gates green, only seam-level checks remaining) is `APPROVE` or `APPROVE-WITH-MINOR-EDITS-APPLIED`. A `REQUEST-CHANGES` verdict at this stage requires you to point at a specific defect not surfaced by any prior review — the burden of proof is on the finding, not on the assumption that "something must be wrong in 177 commits".
