# Para-pharmacy End-to-End Launch-Readiness Campaign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ EXECUTION GATED (owner directive):** Do NOT start any task below until **all other in-flight sessions have finished and merged to `dev`**. Then pull `dev`, fast-forward, and run Task 0.1 (worktree off merged `dev`) FIRST. Re-verify §6 known-issues against merged `dev` before relying on them.

**Goal:** Manually onboard and operate a Tunisian para-pharmacy tenant end-to-end across web admin (Playwright) and Tauri POS (computer-use), produce a bug ledger, and write a French A-Z onboarding guide.

**Architecture:** A phased test campaign. Web flows driven via Playwright MCP (browsers are read-only under computer-use); native Tauri POS driven via computer-use. Hybrid data: create account/locations/users/representative products by hand; bulk-load the catalog tail via a corrected seeder. Log every finding; fix only launch-blockers inline with per-bug owner OK.

**Tech Stack:** Laravel 12 / PHP 8.4 (db-per-tenant, Horizon), React 19 / Vite (web), Tauri 2 (POS), PostgreSQL 16, Redis. Playwright MCP, computer-use MCP.

**Spec:** `docs/superpowers/specs/2026-06-24-parapharmacy-launch-e2e-campaign-design.md`

## Global Constraints

- **Branch base:** fresh worktree off **merged, post-other-sessions `dev`**. Never the current `docs/media-subsystem-architecture` branch. Never this checkout (parallel Codex session live here).
- **Never run the full PHPUnit suite or `--parallel`** (crashes the laptop). Scoped `--filter` only.
- **Money/quantity:** never float on money/qty; `CurrencyScale::bcformatStrict`; payloads as strings; TND scale 3, quantity scale 4.
- **Web app = Playwright; Tauri POS = computer-use.** Capture a screenshot at each meaningful step.
- **Horizon MUST be running** before any POS/fiscal flow (projections are async on `fiscal-projections`).
- **Bug policy:** log all in the ledger; fix only clear launch-blockers inline, only after showing the owner and getting a per-bug OK; everything else → `deferred-branch` or `log-only`.
- **No scope creep:** do not touch balances/AR-AP/GL/event-sourcing/POS-sync (parallel-session lanes); do not build net-new features (e.g. POS cash-rounding) unless owner reclassifies.

---

## Phase 0 — Harness setup

### Task 0.1: Isolated workspace off merged dev

**Files:** none (git/worktree ops).

- [ ] **Step 1:** Confirm all other sessions merged. Run: `git fetch origin dev && git rev-list --left-right --count dev...origin/dev` — Expected: `0	0` after `git checkout dev && git pull --ff-only`.
- [ ] **Step 2:** Create worktree + branch. Run via the `superpowers:using-git-worktrees` skill, targeting branch `test/parapharmacy-launch-e2e` off `dev`.
- [ ] **Step 3:** Copy spec + this plan into the new worktree's `docs/superpowers/` and commit them as the first commit on the branch.
  - Commit: `docs(test): para-pharmacy launch E2E campaign spec + plan`.

### Task 0.2: Bring up the local stack

**Files:** `apps/pos/.env` (create/point at localhost).

- [ ] **Step 1:** Start API. Run (background): `cd apps/api && php artisan serve` — note the port.
- [ ] **Step 2:** Start Horizon (background): `cd apps/api && php artisan horizon`. Verify: `php artisan horizon:status` → "running".
- [ ] **Step 3:** Start web (background): `cd apps/web && pnpm dev`. Verify Vite URL reachable. Enable Playwright **video recording** for all sessions (`recordVideo` / context `video: 'on'`) — `.webm` per session, reused later in Phase 8. POS sessions: screen-record the computer-use run.
- [ ] **Step 4:** Configure POS `.env`: `VITE_API_URL=http://localhost:<api-port>`, local Reverb host. Start (background): `cd apps/pos && pnpm tauri dev`.
- [ ] **Step 5:** `request_access` for the POS app window (computer-use). Take one screenshot of each surface (web login, POS launch) to confirm both are driveable.
- [ ] **Step 6:** Create the ledger file `docs/superpowers/audits/2026-06-24-launch-e2e/ledger.md` with the §7 table header. Commit.

**Acceptance:** API+Horizon+web+POS all up; web reachable via Playwright `browser_navigate`; POS window screenshotable + clickable via computer-use.

---

## Phase 1 — Onboarding (manual, web UI) — *primary guide source*

> Drive every step via Playwright. After each sub-step: screenshot + a one-line note in a running
> `phase1-notes.md` (what was confusing / non-obvious / a dead end). These notes seed the guide.

### Task 1.1: Account + tenant + vertical
- [ ] Navigate to signup; create account; create tenant; select **Parapharmacy**; reach the dashboard.
- [ ] Screenshot each screen. Log any friction to the ledger (severity per §7) + `phase1-notes.md`.
- [ ] **Acceptance:** logged in, tenant exists, vertical=Parapharmacy confirmed via `GET /api/v1/company/config`.

### Task 1.2: Locations (1 warehouse + 2 shops)
- [ ] Create warehouse + 2 shops through the locations UI. Set each shop's per-branch matricule suffix.
- [ ] **Acceptance:** 3 locations listed; matricules pass the TN regex; screenshot.

### Task 1.3: Users, roles, PINs
- [ ] Create owner (exists), manager, 2 shop cashiers; assign roles; set POS PINs; scope cashiers to their shop.
- [ ] **Acceptance:** users listed with correct roles + location scope; screenshot.

### Task 1.4: Treasury / payment methods
- [ ] Configure cash + card payment methods.
- [ ] **Acceptance:** methods available for selection at POS later; screenshot.

### Task 1.5: ~12 representative products by hand (the trap-walk)
- [ ] Create ~12 products including: ≥1 **batch-tracked** (then attempt a PO and observe whether the UI tells the user to create batches — **log this UX explicitly**), barcoded (`619…`) + barcode-less, VAT 19/13/7/exempt, ≥1 price-omitted.
- [ ] **Acceptance:** products listed with correct attributes; the batch-tracking convolution documented in the ledger with a screenshot.

**Checkpoint:** owner reviews Phase 1 notes + ledger before Phase 2.

---

## Phase 2 — Bulk catalog load (the ONE code task: seeder batch fix)

### Task 2.1: Fix `requires_batch_tracking` w/o `product_batches` (TDD)

**Files:**
- Modify: `apps/api/database/seeders/ParapharmacySeeder.php` (~:654 and stock-seeding step)
- Test: `apps/api/tests/Feature/Seeders/ParapharmacyBatchSeedingTest.php` (create)

**Interfaces:**
- Consumes: `GoodsReceiptService::receiveGoods()` (mints lots when `requires_batch_tracking`, `GoodsReceiptService.php:149`) OR a new `seedBatchesForBatchTrackedProducts()` helper.
- Produces: for every batch-tracked product at every location, `product_batches` rows whose qty sums to the product's `StockLevel`, with FEFO expiries.

- [ ] **Step 1: Write the failing test.**
```php
public function test_every_batch_tracked_product_has_lots_summing_to_stock(): void
{
    $this->seed(\Database\Seeders\ParapharmacySeeder::class);
    $batchTracked = \App\Modules\Catalog\Domain\Models\Product::query()
        ->where('requires_batch_tracking', true)->get();
    $this->assertNotEmpty($batchTracked);
    foreach ($batchTracked as $product) {
        $stockByLoc = /* StockLevel qty per location for $product */;
        foreach ($stockByLoc as $locationId => $qty) {
            $lotSum = /* sum product_batches qty for ($product,$location) */;
            $this->assertSame($qty, $lotSum, "lots must reconcile to stock for {$product->id}@{$locationId}");
        }
    }
}
```
- [ ] **Step 2: Run it — expect FAIL** (no `product_batches` seeded). Run: `cd apps/api && php artisan test --filter=ParapharmacyBatchSeedingTest`.
- [ ] **Step 3: Implement** `seedBatchesForBatchTrackedProducts()` (FEFO expiries; qty splits reconcile to `StockLevel`) and call it after stock seeding. Keep additive/idempotent.
- [ ] **Step 4: Run it — expect PASS.**
- [ ] **Step 5: Commit** `fix(seeder): seed product_batches for batch-tracked products (PO/transfer unblock)`.

### Task 2.2: Load the catalog tail
- [ ] Run the corrected seeder against the live tenant to add the bulk catalog (trimmed to 3-location topology).
- [ ] **Acceptance:** catalog volume realistic; batch-tracked products have selectable lots in the UI.

---

## Phase 3 — Procure-to-stock (web / Playwright)

### Task 3.1: Supplier + PO + goods receipt (incl. partial)
- [ ] Create a supplier partner. Create a PO (draft) → confirm → goods receipt; do one **partial** receipt.
- [ ] Verify: stock increases, WAC updates, batch-tracked lines create lots, partial PO stays `confirmed` with per-line `quantity_received`.
- [ ] **Acceptance:** stock + WAC + lots correct; screenshot each state. Log any break.

### Task 3.2: Stock transfer warehouse → shop (batch/lot selection)
- [ ] Transfer batch-tracked + non-batch products warehouse→shop; select lots.
- [ ] **Acceptance:** transfer completes (the K1 block is gone); destination stock + lots correct; screenshot.

**Checkpoint:** owner reviews procurement findings.

---

## Phase 4 — POS operations (Tauri / computer-use)

### Task 4.1: Claim terminal + open shift
- [ ] On a shop's POS: claim the terminal (active+unclaimed), open a shift with cashier PIN.
- [ ] **Acceptance:** terminal claimed, shift open; screenshot.

### Task 4.2: Sales — cash, card, mixed
- [ ] Ring 3+ sales (cash, card, mixed tender). Verify receipt totals, VAT lines, per-branch matricule on receipt.
- [ ] **Acceptance:** sales complete; receipts correct; screenshot each.

### Task 4.3: Returns + store-credit
- [ ] Process a return; issue + redeem store-credit.
- [ ] **Acceptance:** return + credit flows complete; verify K5 sign (store-credit not shown as debt); screenshot.

### Task 4.4: X + Z reports, close shift
- [ ] Run an X report mid-shift; close the shift with a Z report.
- [ ] **Acceptance:** X/Z totals reconcile to rung sales; per-branch matricule rendering checked (K4); screenshot.

### Task 4.5: Tunisia edge cases (log-only unless reclassified)
- [ ] Attempt an under-tender / cash-rounding sale (K2 — expect hard full-tender requirement). Check stamp duty on totals (K3).
- [ ] **Acceptance:** behavior documented in ledger as `log-only`; no fix unless owner reclassifies.

**Checkpoint:** owner reviews POS findings.

---

## Phase 5 — Back-office reconciliation (web / Playwright)

### Task 5.1: Dashboard + reports reflect POS sales
- [ ] As owner, open the landing/dashboard + reports. Verify revenue reflects the POS sales (K6 landing redirect; K7 returns not leaking into breakdowns).
- [ ] **Acceptance:** dashboard revenue matches the day's POS sales; screenshot.

### Task 5.2: Balances + AR/AP + GL
- [ ] Check partner balances (non-negative magnitude), AR/AP, and GL consistency for the day.
- [ ] **Acceptance:** balances coherent; any drift logged (route GL/balance issues to the parallel lane, do not fix here).

---

## Phase 6 — Edge & stress

### Task 6.1: Offline POS drain
- [ ] Queue sales offline; reconnect; verify drain + projection + no double-count.
- [ ] **Acceptance:** offline sales appear once in web-admin; screenshot.

### Task 6.2: Concurrency + boundaries
- [ ] Concurrent shifts on both terminals; cross-terminal refund. Permission boundaries (cashier blocked from owner reports; cross-location denied; suspended-member denied). Malformed input (qty/price regex ceilings). UUID-in-URL 500 vector.
- [ ] **Acceptance:** each boundary holds; violations logged.

**Checkpoint:** owner reviews edge/stress findings + full ledger; decides which deferred bugs (if any) to fix before launch.

---

## Phase 7 — French onboarding guide (conditional)

### Task 7.1: Write `docs/guides/fr/guide-demarrage-tenant.md`
- [ ] **Gate:** only if Phases 1–5 happy path is coherent (or blocking fixes landed).
- [ ] Write the 12-section A-Z guide (spec §8) in French, using captured screenshots + exact UI labels; warn around any remaining convolution with its workaround.
- [ ] Run the `humanizer` skill over the prose.
- [ ] **Acceptance:** a new tenant could follow it start→finish without hitting a documented dead-end; commit.

### Task 7.2: Launch-readiness summary
- [ ] Write a short summary: what works, what's blocking, what's deferred (with branch names).
- [ ] Commit.

---

## Phase 8 — Walkthrough videos & in-product tours (DEFERRED — separate greenlight)

> Do NOT start until the testing campaign passes AND the owner explicitly greenlights this phase
> and the Remotion licensing decision. Reuses the §0.2 captured footage. See spec §12.

### Task 8.1: Production pipeline decision + install
- [ ] Verify current Remotion licensing terms vs team size; owner decides Remotion vs alternative.
- [ ] Install the chosen video package (Remotion) + an interactive-tour lib for the dashboard (driver.js or Shepherd.js, MIT).

### Task 8.2: Walkthrough videos (docs + support portal)
- [ ] Compose narrated/captioned MP4 walkthroughs from the captured Playwright/POS footage, one per guide section (spec §8).

### Task 8.3: Dashboard first-run onboarding
- [ ] Build the interactive tour embedded in the React dashboard (fires on first sign-in); optionally embed a short overview video alongside it.

---

## Self-review notes (author)

- **Spec coverage:** §3 topology → Task 1.2; §4 harness → Phase 0; §5 phases → Phases 1–7; §6 known issues K1–K8 → mapped in Tasks 2.1/3.x/4.x/5.1; §7 ledger → Task 0.2; §8 guide → Phase 7; §10 gate → header + Task 0.1. No gaps.
- **Placeholders:** the only code task (2.1) has concrete test + interface; other tasks are manual flows with explicit acceptance criteria (intentionally not code).
- **Type consistency:** single code symbol introduced (`seedBatchesForBatchTrackedProducts()`) used consistently.
```
