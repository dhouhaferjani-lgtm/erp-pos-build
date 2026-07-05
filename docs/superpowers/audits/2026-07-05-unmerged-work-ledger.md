# Unmerged / Unfinished Work — Consolidated Ledger (2026-07-05)

> Handover I2 deliverable. Baseline: `origin/dev` = `6b356be6d`. Compiled from three
> read-only scouts (branch sweep of 177 refs, worktree/uncommitted inventory,
> memory-claim re-verification). Ordered most-severe / most-load-bearing first.
> No mutations were made; all recommendations pending owner/orchestrator action.

## 🔴 Severity 1 — work that can be LOST (rescue before anything else)

| Item | Where | State | Recommendation |
|---|---|---|---|
| **Entire erp-mobile app history** | `/Users/houssamr/Projects/syneriva/erp-mobile` — separate repo | `origin/main` has ONLY the initial extraction commit. Local `main` 36 ahead, `design-system` 44 ahead, `feat/mobile-expense-logging` @ `6f341cf` 45 ahead — all unpushed, laptop-only. Working trees clean (fully committed). | **Push all three branches to origin immediately.** Zero cost, catastrophic exposure (laptop loss = whole app gone). Decide later which becomes the new `main`. |
| **Live-sales dashboard feature (complete, tested, committed NOWHERE)** | worktree `apps/erp.dashboard-demo`, branch `feat/owner-dashboard-demo` @ `c3229903f` (183 behind dev, not diverged) | 11 dirty entries: new `LiveSalesReportService.php` (121 ln), `DemoPosSalesSeeder.php` (478 ln) + seeder test, edits to `OwnerSalesSummaryService`/`SalesReportService`/`ReportsController`/routes + expanded `OwnerReportingTest` (+300 ln). All 4 spec tasks implemented (hour granularity, same-weekday-last-week baseline, `GET /reports/sales/live`, seeder w/ idempotency guard). No TODOs; precision contract respected. Looks complete, not mid-edit. | **Commit to the branch and push now**, then rebase onto current `origin/dev` before review (routes/ReportsController churned elsewhere in those 183 commits). |

## 🟠 Severity 2 — finished real code awaiting a merge decision

| Branch | Ahead/behind dev | Content | Recommendation |
|---|---|---|---|
| `post-demo` = `origin/post-demo` (`b3e81a13e`) | 14 / 49 | **Procurement/P2P v1 COMPLETE** — goods-receipt ledger, matcher+posting, PPV/received-price capture, multi-PO supplier invoices, presets. 117 files, +12,585. Subsumes `feat/procurement-wave3`. | **Merge to dev** once the owner makes the stability call (deploy checklist in memory `project_procurement_completeness_v1`). Highest-value pending merge. OWNED by the scan-flow session — coordinate, don't act unilaterally. |
| `feat/db-per-tenant-deploy` | 10 / 1317 | Real infra absent from dev: `DirectPostgreSQLDatabaseManager`, pgbouncer config, staging/prod compose db-per-tenant defaults, `TENANCY_DB_PREFIX`, 1 test. | **Rebase-then-merge.** Moderate conflict risk (compose/.env.example/database.php churn) but a real deployment gap. |

## 🟡 Severity 3 — needs a human look, low urgency

| Item | State | Recommendation |
|---|---|---|
| `origin/erp/platform-integration-bundle` (4 ahead / 2962 behind) | One genuinely-unmerged cleanup (drop dead `ProductSubmissionService::bulkSubmit` — still present on dev); other 3 commits unverified. | Owner quick-look; cherry-pick the dead-code drop if wanted. |
| `origin/fix/pos-custom-dialog-focus-traps` (2 ahead) | References PR #97/#101; likely squash-merged but unconfirmed (component name absent from tree). | Verify via `gh pr view 97 101`, then discard. |
| `origin/khalil-pos` (1 ahead / 3617 behind) | Personal scratch ("settings crash + Change Terminal button"). | Ask Khalil or discard. |
| `feat/p2p-entry-points` (17 / 49) | Docs/spec-only on top of post-demo. Other session owns it. | No action from this session. |
| `feat/supplier-invoice-ocr` (5 ahead) | Code layer SUPERSEDED by receipts-ledger architecture; design docs remain valid input. | Scan-flow session's call; don't merge code. |

## 🟢 Superseded / discard candidates (content verified already on dev)

- **Verified 0-diff or subsumed:** `feat/procurement-wave3` (inside post-demo), `origin/feat/cash-counting-cluster`, `origin/feat/document-line-designation-override` (PR #39), `origin/feat/margin-category-override` (pre-rebase original of merged `factory/margin-hier-rebased`), `origin/prereq/hash-format-v2-and-parsefloat-cleanup`, `origin/feat/types-pipeline-overhaul`, `origin/feat/payment-tolerance-v2` family (6 branches), `origin/feat/pos-performance`, `origin/fix/dashboard-stats-correctness`, `origin/refactor/shift-window-posted-at-canonical`, `feat/demo-pharmacy-account` (both real fixes on dev), `factory/quality-gates`, `origin/main` (3 `.agents/` commits already on dev), `origin/fix/parapharmacy-seeder-pg-min-uuid`, `origin/verify/coffeeshop-e2e`, `origin/backup/t11-worktree-wip-2026-06-08`.
- **`triage/pg-suite-98-failures`** (7 ahead): core fiscal/PG fixes + all 3 migrations verified 0-diff vs dev (landed via another path). Residual: `ReturnNoteService` + ~30 test files diverged. **Discard**; optionally inspect the `ReturnNoteService` diff first.
- **Docs-only ahead branches:** `origin/audit/pos-production-readiness`, `origin/claude/create-structure-overview-OYDZR`, `origin/feat/pos-return-disposition`, `feat/bank-reference-verification`, `feat/accounting-gl-go-live` (11 ahead, docs — GL roadmap content; keep branch until GL work resumes or commit docs to dev).
- **~110 origin/* + 19 local branches at ahead=0** — confirmed ancestors of dev, safe bulk-prune. Notable: `fix/pos-variant-stock-decrement` **IS merged** (memory said not).
- **1-ahead pattern-consistent unverified** (spot-check only if something's missed): `origin/chore/pos-hardening-bundle`, `origin/chore/pos-receipt-currency-aware-display-scale`, `origin/chore/remove-deprecated-checkTolerance-companyId`, `origin/fix/feature-suite-*` (4), `origin/fix/fraud-settings-cash-controls-test`, `origin/fix/phpstan-prod-autoload`, `origin/fix/post-save-fast-follows`, `origin/fix/quote-controller-vehicle-context-test`, `origin/refactor/payment-allocation-tolerance-contract`, `origin/refactor/payment-tolerance-checker-contract`, `origin/feat/pos-stabilization-performance`.

## Worktree debris (docs/artifacts only — fold into repo-cleanup pass)

| Worktree | Branch merged? | Untracked content |
|---|---|---|
| `erp.procurement` | yes | 80 historical `docs/superpowers/*` docs (Apr–Jun) — commit keepers to dev, then prune worktree |
| `erp.treasury-be/-c67/-c7/-fe` | yes | 2-3 handoff/audit docs each (mostly duplicates of main-worktree untracked) — dedupe, commit once, prune |
| `erp.pos-stock` | yes (merged!) | 1 review doc — commit, prune worktree |
| `erp.demo-pharmacy` | no (superseded) | 1 review doc — salvage doc, then discard branch+worktree |
| `erp.unified-imports`, `.claude/worktrees/*` (bulk-import, pg-triage) | yes/n-a | runtime storage/`node_modules`/phpunit-config artifacts — discard |
| `erp.accounting-gl`, `erp.banks`, `erp.ocr-docs` | no | clean trees, no rescue needed |
| Main worktree | — | ~60 root screenshots, 22 handoff + 42 superpowers docs, tenant storage dirs, `pnpm-workspace.yaml` placeholder edit (`allowBuilds: better-sqlite3: set this to true or false` — looks like an unfinished stub; fix or revert) |

## Memory corrections required (stale claims found)

| Memory entry | Reality | Fix |
|---|---|---|
| `project_loyalty_earn_demo_cutoff` "LOCAL dev only" | `28de62f90` IS on origin/dev | Retag shipped; archive |
| `project_loyalty_pos_offline` "LOCAL dev, not origin" | `e57212b6e` + `faeab5976` on origin/dev | Retag shipped; archive |
| MEMORY.md line for `pos-variant-stock-decrement` "not merged" | `74c1c8668` on origin/dev; branch 0 ahead | Fix line; prune worktree |
| `project_pos_refund_disposition_umbrella` "`bf394748d` NOT merged" | IS on origin/dev | Correct |
| `project_t11_impl_specs` "PR #132 open" | MERGED 2026-05-24 | Remove from Active Work |
| `project_accounting_gl_roadmap` cite `53d743641` | Branch rebased; content now at `62356eb1d` | Update SHA |
| `project_pos_caisse_redesign` "don't resume stale branch" | Branch deleted entirely | Note branch gone |

Verified-accurate claims (no change): margin backend `4e8593179` on dev; procurement `b3e81a13e` on post-demo only; p2p spec `e8bd2af17` WIP; erp-mobile `6f341cf` unpushed; GL branch not on dev.
