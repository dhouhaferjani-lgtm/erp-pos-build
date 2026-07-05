# HANDOVER — Demo wrap-up orchestrator (start ~2026-07-03, demo in ~1.5–2h)

You are the orchestrator for the final pre-demo wrap-up. Owner is presenting a FULL web + POS demo (Tunisia parapharmacy, tenant login `owner@pharmabio.tn` / `password`). Local stack recipe: memory `reference_local_db_per_tenant_demo_launch` (API :8010 serves the MAIN worktree `apps/erp` on `dev`, vite :5173 same, MinIO remapped :9100 bucket `autoerp`, `images` queue needed for renditions, DB 127.0.0.1:5433). Rollback pin if anything burns: tag `demo-stable-2026-07-03` on origin.

## 1. Already DONE today (do not redo)
- post-demo fully merged into dev + promoted; latest promotion `9538179e8..431721654`. Includes: line-entry P3, pricing context, landed-cost, bonus-qty, multiloc settings UX, treasury C1–C7, enrichment H-A/H-B/H-C, owner-dashboard BE+FE (hour granularity + `/reports/sales/live` — built TODAY, it never existed), free_quantity 422 fix (document saves work now), POS refund/void RESTOCK fix (`79bedc1b6`), staging Horizon supervisors fix.
- POS precision sweep D0-2..D0-6 shipped 07-02 (`5e55b5225`). Variant stock decrement in dev.
- Live smoke passed on: login, /reports dashboard (all 200s), /finance/overview, quote create via line-entry (QT-2026-0002, POST 201), settings multiloc surfaces, /income page, products list.
- 47 merged worktrees + 49 branches cleaned.

## 2. Mergeable to dev RIGHT NOW (survey done, promote first)
- **Local dev is 7 ahead / 0 behind origin** — pure-ff promotable: brand-mapping merge `7f91a213f` (another session; its merge message documents 213 BE + 82 FE tests + reviews) + docs-only procurement spec `8968cc3a4`. Sanity-skim then `git push origin dev` (dev-push-guard hook enforces ff).
- **Local post-demo is 43 ahead / 0 behind** — contains dev merge-back + unified-imports spec (spec only). Push `origin post-demo` as backup (pure ff). Ongoing work keeps landing there per owner.
- **EVALUATE for demo relevance (unmerged branches, unknown state — check author sessions/recency before touching):** `fix/parapharmacy-seeder-pg-min-uuid` (seeder fix — may matter for reseed!), `fix/dashboard-stats-correctness`, `feat/demo-pharmacy-account` (demo seeder branch), `verify/coffeeshop-e2e`. Everything else (accounting-gl, bank-ref, margin-override, ocr, procurement-completeness, unified-imports impl) = NOT demo, leave.
- **`feat/media-prod-fixes-agent` = DELETE-don't-merge** (memory: would break dev).

## 3. Seeding + images (owner explicitly asked)
- Reseed the demo tenant (DemoPharmacySeeder per recipe memory). Verify idempotency/duplication behavior before running against the existing tenant — if the seeder isn't idempotent, prefer a FRESH tenant provision.
- **Verify product images end-to-end:** seeded products must have media in MinIO (`autoerp` bucket, MinIO on :9100 NOT 9000 — php-fpm squats 9000), `images` queue worker running (multi-queue worker per recipe; check `config/horizon.php` queue coverage), renditions generated, images visible on web product list/detail, AND included in the POS first-sync payload (check the POS product-sync endpoint serializes image URLs and they're reachable from the POS webview). Owner will connect a fresh POS and expects images on first synchronization.
- Live-sales dashboard feed + hourly trend need TODAY'S pos_receipts — demo tenant currently has ZERO receipts/shifts. Either the seeder rings some today-dated sales or owner rings live ones during demo.
- POS old-tenant decoupling (owner will test): clear localStorage for the POS origin (shared on localhost!) + delete the device SQLite so the terminal re-provisions against the demo tenant (safe locally only). Tauri serves whatever worktree it launches from (:1420) → launch from main worktree.

## 4. E2E test fleet (dispatch sub-agents, owner-requested flows)
Flows, in demo order: **create a branch/location** (multiloc quick-add: country select, TN tax-ID required) · **import products** (CSV wizard — FR semicolon delimiter + decimal-comma supported) · **import parties** (address/city/country persist + detail page shows them) · **purchase order A→Z** (create → confirm → receive with batch/bonus fields → supplier invoice → payment; landed-cost/GR-IR path) · **stock transfer between branches A→Z** (line-entry scan bar, batch/FEFO allocate, complete + stock levels move) · **expenses** (create → post → appears in Trésorerie upcoming/out + P&L) · **enrichment flow** (product create via barcode lookup / photo-first capture H-B, FOUND backlink, review queue accept) · **POS** (owner tests manually; agents cover web side).
- **CRITICAL — Playwright MCP is ONE shared browser: do NOT let parallel agents use it simultaneously.** Either (a) sequence flows through 1–2 agents using the MCP one-at-a-time, or (b) have each agent write a standalone playwright-core Node script launching its OWN headless Chrome (pattern in memory: playwright-core + system Chrome). Prefer (b) for parallelism given time.
- Automation gotchas from today's smoke (real, will waste agent time if unknown): PartnerSearchSelect closes on trusted clicks → open via `dispatchEvent('click')` and click rows via DOM `.click()`; typing may not land when the window is unfocused → `page.keyboard.insertText` after JS `.focus()`; line-entry results need the full pointer/mouse event sequence on `[role="option"]`; document forms register `beforeunload` → handle dialogs; login is FR locale.
- Agents must REPORT failures with root-cause evidence, fix only demo-blockers (gate any fix: tests by path only, NEVER full suites — crashes the laptop), and never touch git without the orchestrator gating.

## 5. Known non-blockers (do NOT rediscover/fix)
- `/reports/finance-summary` endpoint has never existed → Trésorerie "Aperçu financier" card shows 0,00 EUR silently (candidate quick-win if time remains, else ignore).
- /income list pane sits on "Chargement…" (form works).
- Pre-existing test failures (documented in memory `project_post_demo_merge_back_2026_07_03`): 11 document tenantScope + 1 inventory key-shape (post-demo side), 9 finance whole-dir pollution (dev side — `finance/pages` alone is green).
- BranchLeaderboard hardcoded TND fallback (fine for this demo); ar/reports.json missing 36 legacy keys.
- RELAY from brand-mapping session: 16 FE tests in 4 files fail under FRESH node_modules only (stale vi.mocks) — environment artifact.

## 6. Process rules that bit us today (respect them)
- Main worktree is FILTHY with untracked files: NEVER `git add -A` there — stage explicit paths (a merge commit got contaminated with ~150 junk files today; rebuilt clean).
- dev-push-guard blocks the whole Bash call — run commit and push as SEPARATE commands; reconcile `git merge origin/dev` first if behind.
- Fable subagents can die on usage credits mid-task (one did today) — check partial work in the worktree before redoing; prefer sonnet/opus for research-type agents.
- Never run full PHPUnit/Vitest suites. Tests by path only.
