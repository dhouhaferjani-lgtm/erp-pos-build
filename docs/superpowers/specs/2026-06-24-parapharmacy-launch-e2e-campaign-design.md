# Para-pharmacy End-to-End Launch-Readiness Campaign — Design Spec

> **Status:** DESIGN — approved approach, **execution GATED** (see §10).
> **Date:** 2026-06-24
> **Author:** Claude (Opus 4.8) with owner (admin@otospex.com)
> **Type:** Test campaign + onboarding-guide deliverable (NOT a feature build)

---

## 1. Objective

Validate that the ERP is functionally and visually ready to launch the **minimum necessary**
for a real Tunisian para-pharmacy customer, by **manually onboarding a tenant and operating it
end-to-end** (not merely seeding) across the **web admin** and the **Tauri POS** — exercising
happy paths, edge cases, and stress — then produce:

1. A verified, coherent end-to-end system (happy path works A→Z).
2. A **bug/issue ledger** of every inconsistency found.
3. A **French A-Z onboarding guide** for a new tenant/customer (conditional — see §8).

The campaign exists because the owner has observed recurring onboarding convolutions and
inconsistencies (canonical example: a product flagged `requires_batch_tracking=true` with **no
batches created** silently breaks the purchase-order/stock-transfer flow). The goal is to surface
that entire class of problem from a real user's seat.

## 2. Scope

**In scope**
- Manual tenant creation + onboarding through the real web UI.
- Hybrid data: hand-create account, locations, users, treasury, and ~12 representative products;
  bulk-load the long catalog tail via a **corrected** seeder.
- End-to-end operational flows across web admin (Playwright) + Tauri POS (computer-use).
- A bug ledger; inline fixes for **clear launch-blockers only** (with per-bug owner go-ahead).
- A French onboarding guide.

**Out of scope (this campaign)**
- Deploying to staging/production (this is a **local** campaign).
- Building net-new features (e.g. POS cash-rounding/tolerance) unless owner reclassifies one as a
  launch-blocker mid-campaign.
- Non-launch-critical refactors; anything owned by a parallel session (balances/AR-AP/GL/event
  sourcing/POS-sync hardening — see §10).
- Knowledge base / multi-doc help system (explicitly "for later" per owner).

## 3. Topology (owner-specified)

Tunisian para-pharmacy tenant, **created manually** through onboarding:

| Element | Value |
|---|---|
| Country / currency | Tunisia / TND (scale 3) |
| Vertical | Parapharmacy |
| Locations | **1 warehouse + 2 shops** (3 total) |
| Tax | Tunisia COA; VAT 19 / 13 / 7 / exempt; *matricule fiscal* per-branch (regex `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$`, canonical `1234567AM000`, per-branch varies last 3) |
| POS | One terminal per shop (2), location-scoped cashiers |
| Roles | Owner, manager, shop cashiers |

> Note: prior `DemoPharmacySeeder` used 5 locations; this campaign scales to **3** per the owner's
> "two shops and an inventory warehouse." The seeder will be parameterized/trimmed accordingly for
> the bulk-catalog phase, but locations + users are created **by hand** to exercise onboarding.

## 4. Harness & prerequisites (Phase 0)

1. **Isolated workspace** — `git worktree add` off **freshly-pulled, post-merge `dev`** (NOT the
   current `docs/media-subsystem-architecture` branch, which is 515 behind; NOT this checkout — a
   parallel Codex session is live here). New branch e.g. `test/parapharmacy-launch-e2e`.
2. **Local stack** (verified available: PostgreSQL :5432 ✅, Redis :6379 ✅ already up):
   - API: `cd apps/api && php artisan serve` (db-per-tenant; central DB `synerivia_central`).
   - **Horizon: `php artisan horizon`** — MANDATORY. POS/fiscal projections are async on the
     `fiscal-projections` queue (`ApplyFiscalEventProjectionJob` ShouldQueue). Without Horizon,
     POS sales never project into web-admin. Recovery if backlogged:
     `php artisan fiscal:enqueue-resolved-event-projections --tenant=<tenant-uuid> --actor-id=<user-uuid>`.
     (`--tenant` became REQUIRED on 2026-08-05 — the command now BINDS that tenant's database
     rather than adding a `where` on the central connection, where `fiscal_events` does not
     exist. Without it the command exits 1 with "Missing --tenant option". The actor must belong
     to that same tenant. There is deliberately no fleet mode: the permission gate resolves the
     actor in exactly one tenant's `users` table, so recover N tenants with N invocations.)
   - Web: `cd apps/web && pnpm dev` (Vite).
   - POS: `cd apps/pos && pnpm tauri dev` with `.env` → `VITE_API_URL=http://localhost:8000` (or
     the actual local API port) + local Reverb host. (Local needs no baked-URL rebuild dance;
     that was a staging-only constraint.)
3. **Tooling split (the defining constraint):**
   - **Web admin → Playwright MCP** (DOM-aware). Computer-use grants *browsers* only a "read" tier
     (screenshot yes, click/type no), so the web app **must** be driven via Playwright.
   - **Tauri POS → computer-use** (native app = "full" tier: screenshot + click + type). Requires
     `request_access` for the POS app window.
4. **Screenshots + video capture** at each meaningful step. Web: enable **Playwright video
   recording** (`.webm` per session, free) — these double as the onboarding guide's figures AND
   the raw footage for the deferred walkthrough-video pipeline (§12). POS: screen-record the
   computer-use session. **No video tooling installed during testing** — capture only.
5. **Bug ledger** at `docs/superpowers/audits/2026-06-24-launch-e2e/ledger.md`.

## 5. Test phases

Each phase ≈ one working session, with an owner checkpoint at the end. Phases are sequential
(later phases depend on data from earlier ones).

### Phase 1 — Onboarding (manual, web UI) — *primary guide source*
Walk the real new-customer path and record every friction point:
- Sign up → create tenant → select **Parapharmacy** vertical.
- Create **3 locations** (1 warehouse, 2 shops).
- Create users + roles + POS PINs (owner, manager, 2 shop cashiers); verify permission scoping.
- Configure treasury / payment methods (cash, card).
- Create **~12 representative products** by hand, including:
  - at least one **batch-tracked** product (deliberately walk the trap: enable batch tracking,
    then attempt a PO — observe whether the UI guides the user to create batches/lots);
  - a mix of barcoded (`619…` Tunisia GS1 EAN-13) and barcode-less products;
  - VAT 19 / 13 / 7 / exempt representation;
  - at least one product with price omitted (soft-warning path).
- **Capture:** every step that is confusing, every required field that isn't obvious, every dead
  end. This is the raw material for §8.

### Phase 2 — Bulk catalog load
- **Fix the known seeder bug first:** `ParapharmacySeeder.php:654` flags ~70% of products
  `requires_batch_tracking=true` but no seeder ever creates `product_batches` → batch-tracked
  product has stock but zero selectable lots → PO/transfer blocked. Fix = seed `product_batches`
  per batch-tracked product per location with FEFO expiries (best via
  `GoodsReceiptService::receiveGoods()` which mints lots when `requires_batch_tracking`, OR a
  dedicated `seedBatchesForBatchTrackedProducts()` step) so qty reconciles to `StockLevel`.
- Load the long catalog tail (the bulk of products) so downstream flows have realistic volume.

### Phase 3 — Procure-to-stock (web)
- Create a supplier (partner).
- **Purchase order:** draft → confirm (`PurchaseOrderService::confirm()`) → goods receipt
  (`GoodsReceiptService::receiveGoods()`), including a **partial** receipt (stays confirmed with
  per-line `quantity_received`). Verify stock + WAC effects, and that batch-tracked lines create
  lots.
- **Stock transfer** warehouse → shop, including **batch/lot selection** (variant-aware path).
- Verify the batch-tracking-without-batches block is gone post-fix.

### Phase 4 — POS operations (Tauri, computer-use)
- Claim terminal (active + unclaimed → claimable; mints genesis seed).
- Open shift; ring sales: cash, card, mixed tender.
- Returns; store-credit issue/redeem.
- X report (mid-shift), Z report (close); verify per-branch matricule rendering and totals.
- Close shift; verify reconciliation.
- **Tunisia edge cases to observe (known gaps — log, don't fix unless reclassified):**
  cash rounding / payment tolerance (currently a missing feature — POS hard-requires full tender),
  stamp duty (timbre) not applied to POS receipt totals, per-branch matricule on `z-report.blade.php`.

### Phase 5 — Back-office reconciliation (web)
- Do the POS sales appear correctly on the **owner dashboard** and **reports**? (F-3 landing
  redirect, F-5 returns-leak filter were recently fixed — verify they hold.)
- Partner balances (non-negative magnitude convention), AR/AP, GL consistency for the day's sales.
- Today-sales panel / shift receipts (404-fallback fix `6d9102fbc` — verify).

### Phase 6 — Edge & stress
- Offline POS: queue sales offline, reconnect, drain, verify projection + no double-count.
- Concurrent shifts across the 2 terminals; cross-terminal refund behavior.
- Oversell attempts; malformed input (qty/price regex ceilings, money-as-string contract).
- Permission boundaries: cashier cannot reach owner reports; cross-location access denied;
  suspended-member access denied (recent FU-2 fix).
- UUID-in-URL paths (validate `Str::isUuid()` before lookups; known 500 vector).

### Phase 7 — French onboarding guide (conditional)
Written **only if** Phases 1–5 yield a coherent happy path (or the blocking fixes have landed).
See §8.

## 6. Known issues to verify (from prior memory — confirm current state on merged dev)

| # | Issue | Expected current state |
|---|---|---|
| K1 | Batch tracking flag w/o `product_batches` blocks PO/transfer | **Fix in Phase 2**; verify Phase 3 |
| K2 | POS within-tolerance / cash-rounding sale | Missing feature — **log only** |
| K3 | Stamp duty not on POS receipt totals | Caveat — **log only** |
| K4 | Per-branch matricule on Z-report blade | Possible small fix / caveat — log |
| K5 | Store-credit / customer-advance sign | Reportedly fixed by Codex `5e4fc0dab` — verify |
| K6 | Dashboard landing shows ~0 revenue for POS-only tenant | F-3 fixed `fd2861e38` — verify |
| K7 | Returns leak into report breakdowns | F-5 fixed `249307a6f` — verify |
| K8 | Today-sales 404 on device-minted shift id | Fixed `6d9102fbc` (needs current POS build) |

## 7. Bug ledger format

`docs/superpowers/audits/2026-06-24-launch-e2e/ledger.md`, one row per finding:

| ID | Phase | Path / screen | Expected | Actual | Severity | Screenshot | Disposition |
|----|-------|---------------|----------|--------|----------|------------|-------------|

Severity: BLOCKER (launch-blocking) / HIGH / MED / LOW.
Disposition: `fixed-inline` / `deferred-branch:<name>` / `log-only` / `wontfix`.

## 8. French onboarding guide (deliverable)

Path: `docs/guides/fr/guide-demarrage-tenant.md` (final location TBD with owner).
Audience: a brand-new tenant / customer, zero prior knowledge. Language: **French**.
Structure (A→Z, ordered to route *around* discovered convolutions, or after fixes land):

1. Créer son compte et son établissement (tenant, vertical Parapharmacie)
2. Configurer ses emplacements (entrepôt + boutiques)
3. Créer ses utilisateurs, rôles et codes PIN (caisse)
4. Paramétrer la fiscalité (matricule fiscal, TVA, COA Tunisie)
5. Configurer les moyens de paiement (espèces, carte)
6. Créer son catalogue produits (codes-barres, lots/péremption, TVA)
   - encadré: « Quand activer le suivi des lots — et ne pas oublier de créer les lots »
7. Approvisionnement: fournisseur → bon de commande → réception (totale/partielle)
8. Transferts de stock entre emplacements
9. Caisse (POS): réclamer un terminal, ouvrir un poste, encaisser, retours, avoirs
10. Clôture de caisse: rapports X et Z
11. Tableau de bord et rapports (propriétaire)
12. Annexe: pièges fréquents et comment les éviter

Each step gets the captured screenshots and the exact UI labels. The guide **must not** instruct
users into a known dead-end; where a convolution remains unfixed, the guide explicitly warns and
gives the workaround.

## 9. Deliverables

1. Verified end-to-end local system (happy path A→Z).
2. Bug ledger (§7).
3. French onboarding guide (§8) — conditional on §8's gate.
4. Corrected bulk-catalog seeder (Phase 2 fix).
5. A short launch-readiness summary: what works, what's blocking, what's deferred.

## 10. Execution gate (owner directive)

> **Do NOT begin execution until all other in-flight sessions have finished and merged their work.**

Concretely:
- Wait for the parallel Codex session (POS/identity FU-2 in `apps/erp.fu2-company-context`) and any
  other active branches to merge to `dev`.
- Then: pull `dev` → fast-forward → create the worktree + branch off the **merged** `dev`.
- Re-verify the §6 known-issue states against that merged `dev` before testing (memory may be stale).
- This spec is committed on the fresh branch at execution start (NOT on the current stale branch).

## 12. Future: walkthrough videos & in-product tours (DEFERRED — post-testing)

> Owner directive: test first; **only once everything runs fine**, install the video package and
> build walkthroughs/docs. The test campaign (§4.4) captures the raw footage so this phase is
> cheap when it starts. Two distinct jobs, two tools:

- **Docs + support-portal walkthrough videos → Remotion** (React, scriptable, renders MP4,
  **regenerable** when the UI changes; composites the captured Playwright/POS footage + branded
  intros, zoom-callouts, captions, voiceover). ⚠️ **Licensing:** free for individuals/small teams,
  **paid Company License above a team-size threshold** — verify current terms before adopting for a
  commercial product. Fallback if licensing is a blocker: a manual editor (e.g. Screen Studio),
  not agent-scriptable.
- **Dashboard-embedded onboarding "once someone signs up" → interactive tour** (driver.js or
  Shepherd.js, both MIT, embed in the React dashboard). In-product tours point at the real UI,
  adapt as the app changes, and drive action over passive watching — generally better than an
  embedded video as the *primary* first-run mechanism. A short embedded overview video MAY
  accompany the tour, but should not replace it.
- Nothing installed until the testing campaign passes and the owner greenlights this phase.

## 11. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Tauri POS build/run friction locally | Phase 0 builds it first; `pnpm tauri dev` against localhost (no baked-URL constraint locally) |
| Horizon not running → POS sales invisible | Explicit Phase 0 prerequisite + recovery command |
| Campaign balloons into fix-everything | Bug policy: log all, fix blockers only with per-bug OK |
| Stale known-issue assumptions | Re-verify §6 against merged dev before relying on it |
| Computer-use can't drive the browser | Web driven via Playwright; computer-use only for the native POS |
| Full PHPUnit suite crashes laptop | Never run full/`--parallel` suite; scoped `--filter` only |
| Parallel-session collision | Dedicated worktree; execution gated on their merge |
```
