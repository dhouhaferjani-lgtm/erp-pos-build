# HANDOVER — Delivery-Factory Orchestrator (start 2026-07-04)

You are the orchestrator for the post-demo delivery phase. The goal is twofold: (1) deliver everything outstanding, and (2) evolve the process toward autonomous continuous delivery ("dark factory") — work runs on a remote VPS (Claude Code + Codex CLI + Playwright) with the owner's laptop reserved for what genuinely needs him. Dispatch checkups and implementation through subagents (Opus/Sonnet); orchestrator gates all git promotion.

**State at handover:** `origin/dev` = `8cb507faa` (post-demo fully merged + promoted 2026-07-04; unified imports ph 0-2, procurement RFQ Waves 1-2, treasury C1-C7, enrichment H-A/B/C, owner dashboard, all demo fixes). Staging (erp.otospex.dev) redeploy of all services from that commit was in flight at handover — VERIFY it completed before assuming staging is current (web service has `autoDeploy=false`; API/worker/scheduler auto-deploy on push). Staging tenant DBs self-migrate at API boot (`tenants:migrate-rolling` in entrypoint since `9a56149cf`). Rollback pin: tag `demo-stable-2026-07-03`.

---

## Phase 0 — Factory rails (do FIRST, before feature work)

The rails must exist before the factory runs. All four items are VPS-compatible.

### 0.1 Product Bible refresh
`docs/PRODUCT-BIBLE.md` (canonical copy = main worktree on dev; worktree copies are stale mirrors). Status DRAFT, last verified 2026-06-30 — **~5 days of heavy shipping stale**:
- `Income` module (created 2026-07-03) has ZERO mentions anywhere (bible + `docs/autonomous-product-dev-setup/discovery-module-inventory.md`).
- Module count drift (bible says 44, `apps/api/app/Modules/` has 43 top-level).
- §3 Module Status Map / §4 Business Flows / §8 Tech Debt Register predate: unified imports, RFQ waves, treasury C1-C7, enrichment H-A/B/C, owner dashboard, brand mapping.
- §6-§10 cells marked pending in the bible's own header.
Task: dispatch a re-verification pass against current dev; update the bible + companion discovery docs; keep TD/OQ/Decision logs current. The bible is the factory's map — everything downstream reads it.

### 0.2 Audit-agent bench (currently: ONE agent)
Only `.claude/agents/treasury-reviewer.md` exists (opus, read-only tools, domain contract + known traps baked into the prompt, gates merges). It's the proven template. Create siblings for the other spine domains, same structure:
- **fiscal/POS-projection reviewer** — hash chains, device-authored events, projection idempotency, SQLite↔server contracts (CLAUDE.md rule 20 traps).
- **inventory/costing reviewer** — WAC, stock movements, batch/FEFO, opening balances, exactly-once decrement.
- **tenancy/authz reviewer** — db-per-tenant boundaries, permission catalog/seeder sync, module gating both layers, queue-context (no CompanyContext in workers).
- **precision reviewer** — money/quantity contract (rule 19), or fold into each domain agent's contract.
- **imports reviewer** — unified-imports invariants (sign quadrants, upsert key precedence, opening-balance batches).
Each must cite file:line, never auto-merge, and be wired into the review step of the workflow below.

### 0.3 Quality gates — close these holes
- **CI does NOT gate direct pushes to `dev`** (`ci.yml` triggers: push to `main`, PRs to `main`/`dev` only). The whole team pushes straight to dev. Factory fix: either add `push: branches: [dev]` to ci.yml, or move the VPS workflow to PR-based merges into dev (preferred — PRs give the audit agents a review anchor).
- **PHP version split:** CI pins 8.3, production Dockerfile builds 8.4. Standardize (decide with owner; likely bump CI to 8.4 to match what ships).
- **`scripts/preflight.sh` runs the FULL PHPUnit suite** — forbidden on the laptop (crashes it), fine and *desirable* on the VPS. Parameterize (e.g. `PREFLIGHT_SCOPE=paths|full`) so laptop and VPS share one script.
- **SQLite masks Postgres bugs** — proven twice this week (`latestOfMany` → `MAX(uuid)` 500'd every product detail in prod while the sqlite suite was green). CI has a `backend-test-pgsql` job and there's a `triage/pg-suite-98-failures` branch (7 ahead, locked worktree) — burning that down and making PG the primary test target for DB-touching modules is high-leverage factory work.
- **Known pre-existing failures to triage into the backlog** (so green means green): `RecordCustomerDepositTest` (CustomerAdvance GL line missing — real dev bug found 2026-07-04), 11 document tenantScope + 1 inventory key-shape, 9 finance whole-dir pollution, PHPStan baseline drift (25 errors in unchanged files, `ProductController:741` / `EnrichedProductData`).
- Existing assets to keep wired: deptrac + ratchet (`apps/api/deptrac.yaml`, baseline 2026-05-14), `audit-tanstack-keys.mjs`, `audit-pos-local-cache.mjs`, dev-push-guard hook, react-doctor workflow, smoke-test.yml (manual Playwright vs staging).

### 0.4 Factory workflow definition
Codify the loop the demo week already used informally: spec → adversarial plan review (BEFORE dispatch — standing rule) → TDD implementation in an isolated worktree (Claude subagent or Codex CLI; Codex can't commit in worktrees → task-log protocol, see memory `feedback_codex_worktree_commit_sandbox`) → domain audit agent review (0.2) → gates (0.3) → orchestrator-gated merge to dev → staging auto-deploy + Playwright smoke → bible/REALIGNMENT-LOG updates. Write it down as a doc the VPS session boots from every time.

---

## Track A — VPS queue (autonomous; Linux-portable, test/Playwright-verifiable)

**Environment setup first** (one-time): Ubuntu VPS; PHP per 0.3 decision + extensions `dom curl libxml mbstring zip pcntl pdo pdo_pgsql redis`; pnpm 9.14.2 / Node ≥20; `docker compose up -d` for infra (postgres/pgbouncer/redis/meilisearch/minio — all Linux-native); app runs native (`php artisan serve`, `pnpm dev`) — no devcontainer exists, replicate the local recipe (memory `reference_local_db_per_tenant_demo_launch`: empty SANCTUM_STATEFUL_DOMAINS → token auth, multi-queue worker incl. `images`/`imports`/`enrichment`); `pnpm exec playwright install --with-deps chromium`; Codex CLI + auth; git clone with worktree discipline.

**Feature queue (rough priority):**
1. **Procurement Waves 3-6** (receipt ledger) — plan `docs/superpowers/plans/2026-07-03-procurement-completeness-wave3-6-receipt-ledger-plan.md` has owner resolutions A1-A7 baked in (A6/OQ3 non-blocking). Waves 1-2 shipped; this is the natural continuation.
2. **Margin hierarchy** — `feat/margin-category-override` (23 ahead, worktree `apps/erp.margin-hier`): BE built, reviewed, preflight-green, NOT merged. Rebase-review → merge → then FE tasks 15-19 (needs BE types). Memory: `project_margin_override_hierarchy`.
3. **Unified imports phases 3+** — spec FINAL v3 (`2026-07-02-unified-imports-design.md` + v4 addendum); phases 0-2 are on dev.
4. **Accounting GL go-live** — `feat/accounting-gl-go-live` (11 ahead): evaluate against the GL roadmap (memory `project_accounting_gl_roadmap`); bible TD-001 (POS COGS→GL), TD-002 (money-movement spine), TD-003 (hierarchy balance) are its core. A1 gated on owner/expert-comptable → laptop track for the decision, VPS for the build.
5. **Mobile expense logging** — `HANDOVER-mobile-expense-logging.md`: backend ready, mobile app feature unbuilt, zero backend changes. Cleanly delegable.
6. **Supplier-invoice web creation UI** — E2E-confirmed gap: invoices can be listed/viewed/posted but not CREATED in the browser (`useCreateSupplierInvoice` unused).
7. **Bug backlog:** RecordCustomerDepositTest GL bug; `/income` list stuck on "Chargement…"; `/reports/finance-summary` never existed (Trésorerie card shows 0,00 EUR); FE `RecordPaymentPayload` missing `partner_id` (latent); `latestEnrichmentResult` `->latest()` → `->orderByDesc('version')` hardening; go-live security audit's **RoleController privesc (launch blocker)** — memory `project_go_live_security_audit_2026_06_14`.
8. **Branch/worktree hygiene:** decide (merge/rebase/discard) the ahead-of-dev branches: `feat/db-per-tenant-deploy` (10), `triage/pg-suite-98-failures` (7), `feat/demo-pharmacy-account` (5, docs-only unique), `feat/supplier-invoice-ocr` (5), `fix/dashboard-stats-correctness` (2, known-obsolete), `verify/coffeeshop-e2e` (2, known-superseded), `feat/bank-reference-verification` (1), `fix/parapharmacy-seeder-pg-min-uuid` (1, known-obsolete). Prune the ~29 zero-ahead branches + ~19 stale worktrees.

**VPS advantages to exploit:** full test suites are safe there; long-running adversarial review fleets; Playwright E2E against a VPS-local stack on every merge.

## Track B — Laptop queue (owner-in-the-loop)

1. **Anything `apps/pos` Tauri-native** — builds/signing/running are macOS-bound: fresh Tauri build → staging, POS return-disposition selector UI, caisse redesign P4-P7 + TransactionCart restyle, shifts v56 loose ends.
2. **Customer-workflow features from the demo** — needs emerged from the customer's actual workflow; flesh out in a DEDICATED spec/brainstorming session with the owner first (do NOT fold into this session). Outputs feed Track A.
3. **Owner decision gates:** GL A1 (expert-comptable countersign), procurement OQ3, margin-hierarchy FE UX choices, PHP version pin, CI-on-dev vs PR-flow choice.
4. **Visual sign-offs** — design-heavy pages (caisse, product editor) after VPS agents draft them.
5. **Live staging/production ops** until the factory earns trust (deploys are cheap to run from either, but keep the owner in the loop on anything touching real tenants).

## Process rules (inherited — respect them)
- Orchestrator gates ALL merges to dev; clean fast-forward promotions only; never force-push dev (`dev-push-guard` hook enforces; run commit and push as separate Bash calls).
- Main laptop worktree is filthy: NEVER `git add -A`; stage explicit paths.
- Laptop: tests BY PATH only, never full suites. VPS: full suites allowed.
- Adversarial review of spec+plan BEFORE dispatching implementation (standing owner rule).
- Codex: reviews to file not inline; worktree sandbox + task-log protocol; never Fable for research subagents — sonnet/opus.
- Published API / DB shape changes → `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`.
- Staging web has `autoDeploy=false` — every promotion needs an explicit web redeploy (or flip autoDeploy on as a Phase 0.3 fix).
- Playwright MCP = ONE shared browser; parallel agents write standalone playwright-core scripts with their own headless Chrome.

## Reference docs
- Demo-day state + credentials: `docs/sessions/DEMO-CHEATSHEET-2026-07-03.md` (logins/PINs, POS reset, stack ports)
- Memory index: `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/MEMORY.md` (Active Work list + per-domain indexes)
- Staging ops: memory `project_izipos_staging_deploy_2026_06_19` (Dokploy IDs, psql :5434, no SSH) + `claude/deploy-runbook.md` (platform side)
