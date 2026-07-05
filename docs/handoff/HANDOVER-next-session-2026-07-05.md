# HANDOVER — Next Session (2026-07-05) + Dark-Factory Work Split

> Boot order: read this → `docs/factory/WORKFLOW.md` → memory `MEMORY.md` → this session's specific task.
> Orchestrator gates ALL merges to dev; clean fast-forward promotions only; commit & push as separate Bash calls; never force-push dev.

## State pin (verified 2026-07-05)
- `origin/dev` = local `dev` = **`6b356be6d`** (Phase-0 factory rails + RoleController privesc fix + margin-hierarchy backend + finance-summary/income error-state all promoted; staging redeployed & verified).
- Staging (erp.otospex.dev / api.erp.otospex.dev) is CURRENT and healthy — auth login → token → `/user/companies` → `/reports/finance-summary` all 200 (verified live). Staging web `autoDeploy=false`: every promotion needs an explicit Dokploy web deploy (app id `mY6P_PHb4pw-2LdG1Y7Ml`).
- Owner decision gates still open: `docs/handoff/OWNER-BRIEF-2026-07-04-decision-gates.md` (CI model A/B, PHP 8.4 pin, autoDeploy flip, GL A1, procurement OQ3, margin FE UX).

## Ownership split (owner decision, 2026-07-05)
- **Procurement + P2P → the SCAN-FLOW / OCR-invoice-&-delivery-notes session.** That session already owns the goods-receipt / supplier-invoice / delivery-note domain (`docs/handoff/PROMPT-supplier-scan-invoice-delivery-note.md`). Per the memory files (which parallel sessions keep current — trust them over any snapshot here): **procurement completeness v1 is COMPLETE on `origin/post-demo` `b3e81a13e`** — all 9 waves (RFQ groups, receipt ledger + PPV, receipt-line matcher, SI creation UI, multi-PO, presets) — **awaiting the owner's stability call to promote to dev** (deploy checklist in `project_procurement_completeness_v1.md`). Separately, the owner pivoted the scan handoff into a **flow-model-first P2P effort** (`project_p2p_entry_points.md`, branch `feat/p2p-entry-points`, worktree `apps/erp.p2p-flow`): receipt-first via auto-PO, invoice-first delivered/parked fork, draft receipts with GRN-at-post, entry/exit-note projection, policy toggles — dual adversarial design review done, awaiting owner spec review → plan → Codex waves. **Scan-to-Document is now Spec B, gated on P2P entry points.** THIS session must NOT touch `apps/erp.procurement-v2`, `apps/erp.p2p-flow`, or procurement/P2P files — coordinate through that session and the orchestrator only.
- **THIS session** owns: POS/loyalty bugs below, the unmerged-work scout, the dark-factory work-split doc, and non-procurement features. Do NOT touch `apps/erp.procurement-v2` or procurement files — that's the scan-flow session.

## Bugs to investigate (found during owner review)

### B1 — POS desktop "add customer" broken (HIGH; blocks parapharmacy onboarding)
Reported by owner on the Tauri POS app:
- Adding a customer via the **cart button** (customer modal): customer was NOT added; then searching for that customer returned nothing.
- Adding a customer via the **Customers tab** (`CustomersPage`): did not appear to persist either.
Relevant files (locate, don't assume): `apps/pos/src/pages/CustomersPage.tsx`, `apps/pos/src/components/customers/*`, `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx`, `apps/pos/src/stores/__tests__/paymentStore.customerAttach.test.ts`, plus the POS↔server customer sync/search path. Suspect areas: offline SQLite write vs server create, search index/query (SQLite TEXT timestamp/`toSqliteUtc` class of bug per CLAUDE.md rule 20?), and the create mutation's response handling. **Tauri build/run is macOS-bound → laptop track.** Reproduce on the demo stack (memory `reference_local_db_per_tenant_demo_launch`) with computer-use or playwright-core against the running app; systematic-debugging skill first.

### B2 — Auth 401 infinite-loop after session expiry (MEDIUM; recover-from-logout trap) — DIAGNOSED, fix not yet dispatched
Root cause CONFIRMED live: a stale token in `localStorage` survives hard-refresh; the axios auth interceptor (a) keeps retrying `/user/companies` with the rejected token instead of stopping, and (b) never clears the bad token or redirects to login → thousands of identical 401s, only escapable via a private window. NOT a regression from any recent deploy (auth path untouched; server healthy). Durable fix: on 401, clear stored token/session, stop retrying, redirect to login. Small, self-contained; TDD + tenancy-authz-reviewer gate. Files: `apps/web/src/lib/api.ts` / query-client interceptor (grep `Unauthorized request`).

## Investigations

### I1 — Loyalty program: enrollment path status
The CODE is on `origin/dev` (verified: `28de62f90` earn-on-purchase + `e57212b6e` POS Phase-1 balance are both ancestors of `6b356be6d`). Memories: `project_loyalty_earn_demo_cutoff`, `project_loyalty_pos_offline`. UNVERIFIED: how a customer actually gets ENROLLED (web + POS), whether enrollment is wired end-to-end and reachable in the UI, and whether pay-with-points (deferred Ph2) matters for launch. Trace the enrollment flow web→API→POS and report what's live vs stubbed vs missing; don't assume "built = delivered."

### I2 — Unmerged / unfinished-work scout (dispatch a fleet)
Dispatch parallel read-only scout agents (Explore/general-purpose, sonnet/opus — NEVER Fable for research) to find: (a) work FINISHED but never merged to dev, (b) work ONGOING but unfinished. Known starting points to verify, not trust:
- `apps/erp.dashboard-demo` worktree: UNCOMMITTED live-sales code (`LiveSalesReportService.php`, `DemoPosSalesSeeder.php`, `OwnerSalesSummaryService`/`SalesReportService`/`ReportsController` edits) — committed nowhere.
- `erp-mobile` repo: `feat/mobile-expense-logging` @ `6f341cf`, unpushed, needs device sign-off (owner brief §B7).
- Owner-decision-pending branches (see hygiene report — 29 branches + 5 worktrees already pruned 2026-07-04): `feat/db-per-tenant-deploy` (10 ahead, real infra), `feat/accounting-gl-go-live` (11, docs), `feat/supplier-invoice-ocr` (5 — overlaps scan-flow), `feat/bank-reference-verification` (1, design), `triage/pg-suite-98-failures` (7, PG-suite burn-down = factory asset), plus worktrees `erp.procurement`/`erp.treasury-*`/`erp.unified-imports` carrying untracked docs.
- The `MEMORY.md` "Active Work" list has ~30 project files — many say "merged to LOCAL dev, not origin"; RE-VERIFY each against `git merge-base --is-ancestor <sha> origin/dev` because dev has moved a lot; several are now actually on origin/dev.
Output: one consolidated ledger (branch/worktree → ahead/behind → unique content → merge/rebase/discard recommendation), most-severe/most-load-bearing first.

## Dark-Factory work-split plan (the deliverable the owner wants)
Goal: **stable system to onboard the first parapharmacy (customer "Bill the Standard" — CONFIRM exact spelling) within 2–3 days**, working in parallel across three tracks. Produce a living doc (e.g. `docs/factory/LAUNCH-PLAN-2026-07.md`) that splits every remaining item into:
- **VPS-autonomous track** — test/Playwright-verifiable, Linux-portable: dispatched to the VPS factory, runs while the owner works elsewhere (full test suites are safe there — `PREFLIGHT_SCOPE=full`). Feed from the I2 scout ledger + owner-brief backlog.
- **Laptop / owner-in-the-loop track** — macOS-bound (all Tauri POS builds/signing, B1), visual sign-offs, live-tenant ops, owner decision gates.
- **Mobile track (parallel)** — `erp-mobile` (Expo): expense logging device sign-off + next mobile features; owner wants this pushed hard because "it makes a lot of things way easier." Backend is mobile-ready (memory `HANDOVER-mobile-expense-logging`).
Each item: owner? / VPS-safe? / dependencies / reviewer(s) / done-criteria. The factory workflow (`docs/factory/WORKFLOW.md`) and the audit-agent bench (`.claude/agents/`: treasury-, fiscal-pos-, inventory-costing-, tenancy-authz-, imports-reviewer) are the execution machinery — reference them, don't rebuild them.

## Process rules (inherited — respect)
Orchestrator gates all dev merges; clean ff only; commit+push separate calls; dev-push-guard hook. Laptop: tests BY PATH only (`PREFLIGHT_SCOPE=paths`), never full suites. NEVER `git add -A` in the main worktree (it's filthy — screenshots/storage/scratch); stage explicit paths. Isolated worktree per feature; verify the worktree base = intended dev tip before working (harness sometimes bases on stale fork `5cf61a6fc`). Adversarial review of spec+plan BEFORE dispatching implementation. Codex: reviews to file not inline; can't git-commit in sandboxed worktrees → task-log protocol; never Fable for research subagents. Published API / DB-shape changes → parent-repo `../../docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`.
