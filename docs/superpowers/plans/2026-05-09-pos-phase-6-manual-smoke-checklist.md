# POS Phase 6 — Manual Slow-3G Smoke Checklist (audit-phase runbook)

> **What this is:** the concrete runbook the pre-launch audit phase uses to execute the Phase 6 Slow-3G smoke test on the POS Tauri build. Authored 2026-05-09 by the Phase 6 prep session; not executed in that session by design — Tauri-native flows + real Stripe terminal hardware are out of CLI/Playwright reach.
>
> **Trigger:** before un-drafting the dev → main PR (Phase 6 final go-live gate). All checkboxes below must close green; any FAIL means STOP, file a follow-up plan under `docs/superpowers/plans/`, and brief the orchestrator before un-drafting.
>
> **Related:**
> - `docs/superpowers/plans/2026-04-30-pos-roadmap.md` Tier 2 row T2.3 (Phase 6 final go-live gate).
> - `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md` §Phase 6 (preflight + Slow-3G + dev→main + tagged release).

---

## Environment setup

### Test device

- **Hardware:** real workstation matching pharmacy spec (Mac mini M-series or Intel NUC + small touchscreen). Production-like Tauri build, not the dev server.
- **OS:** macOS or Windows (whichever the parapharmacy will deploy first; if both are in scope, run the full smoke twice).
- **Network throttling:** Chrome DevTools-style "Slow 3G" preset (400 ms RTT, 400 kbps down, 400 kbps up, 500 ms latency). Apply at the **OS level** via Network Link Conditioner (macOS) or Clumsy (Windows) — DevTools throttling only affects the WebView's outbound, not Tauri's IPC + sync scheduler.

### Test data

- Fresh tenant seeded via `php artisan db:seed --class=ParapharmacySeeder` with `PARAPHARMACY_SEEDER_SCALE=5` (5000 products) — exercises the T1.0 large-catalog fixture under realistic conditions.
- One terminal registered, one cashier user, one manager user (use `RolesAndPermissionsSeeder` defaults).
- One real Stripe terminal device paired (BBPOS WisePOS E or equivalent) in test mode.

### Capture targets (collect throughout)

For every step below, capture:
- **HAR file** — `chrome://inspect` → DevTools → Network → "Save all as HAR with content".
- **Screenshots** — at every state transition (success, error, sync indicator change). Name `step-N.M-description.png`.
- **Console log** — Tauri devtools → Console → "Save as…". One file per step.
- **Backend log** — `tail -f apps/api/storage/logs/laravel.log` from the same window.

Aggregate captures into a dated bundle: `audit-2026-MM-DD-pos-phase-6-smoke/` and link from the PR comment that closes this checklist.

---

## Pre-flight (before powering on the test device)

- [ ] Backend: production-like Postgres + Redis up, migrations clean (`php artisan migrate:status` shows no pending).
- [ ] Backend: `composer test` PASS, `./vendor/bin/phpstan` PASS, `./vendor/bin/pint --test` PASS.
- [ ] Frontend (apps/web): `pnpm typecheck` PASS, `pnpm test` PASS, `pnpm lint` PASS.
- [ ] POS (apps/pos): `pnpm typecheck` PASS, `pnpm test` PASS. (Note: `pnpm lint` SKIPPED — ESLint v9 config break carried forward; tracked under T2.2 Step 5.3 sweep.)
- [ ] Fiscal v3 fixture parity: `./apps/pos/scripts/check-fiscal-fixture-parity.sh` returns ✅.
- [ ] CI on the dev → main draft PR: all checks SUCCESS or SKIPPED (no FAIL).

---

## Golden path (cold start → first sale → refund → Z-report → restart)

### 1. Cold first-launch install

- [ ] Wipe any existing Tauri install (delete `~/Library/Application Support/com.autoerp.pos` on macOS or `%APPDATA%\com.autoerp.pos` on Windows).
- [ ] Drag the freshly-built Tauri app into `/Applications/` (macOS) or run the MSI installer (Windows).
- [ ] Launch — no internet permission prompt; should reach the activation screen.
- **Expected:** activation screen renders within 5 s on Slow-3G; no console errors; no chain-break banner.
- **Acceptance:** activation completes within 60 s on Slow-3G with the typed activation code.

### 2. Login + initial sync

- [ ] Log in with cashier credentials.
- [ ] Wait for the initial catalog + payment-config sync.
- **Expected:**
  - Sync indicator transitions: empty → spinner → green within ~30 s on Slow-3G with 5000-SKU fixture.
  - `pendingReceiptCount` badge stays at 0 (no offline backlog yet).
  - `lastSyncAt` populates the SyncButton's "X seconds ago" affordance.
  - `paymentConfigReady` gate green: cash + card buttons enabled in PaymentSummary.
- **T1.0 + T1.1 + T1.2 + T1.3 acceptance:** all four landed surfaces visible — large catalog populated, login token persisted (no auth-orphan on second app open), payment config available, sync indicator truthful.

### 3. Add 5 products to cart

- [ ] Search for one product by name.
- [ ] Search for one product by barcode (scan or type-paste).
- [ ] Long-tap a category, pick a product.
- [ ] Add a manual line item.
- [ ] Add a stock-tracked product near zero stock — observe the low-stock indicator if implemented.
- **Expected:** every add takes < 500 ms perceived (UI not janky on Slow-3G; product list renders from SQLite-first per T1.0/T1.3 hydrations).
- **Acceptance:** cart shows 5 lines, totals correct, no console errors.

### 4. Apply transaction discount

- [ ] Open the discount modal, apply 10% off.
- **Expected:** cart total recomputes, modal closes, discount line visible.
- **Acceptance:** total = subtotal × 0.90 (rounded to currency scale).

### 5. Apply customer

- [ ] Open the customer-search modal, search by phone.
- [ ] Select a customer.
- **Expected:** customer name + loyalty info appears on the cart header.
- **Note:** customer-lookup-by-phone/email/loyalty was deferred per `project_refund_flow_phases.md` memory — verify the search even works against the live tenant or document gracefully if it's a known gap.

### 6. Pay cash + print receipt

- [ ] Click the cash button, enter denomination using NumPad.
- [ ] Confirm payment.
- [ ] Trigger receipt print (or render PDF).
- **Expected:**
  - Payment success animation < 1 s after confirm.
  - `pendingReceiptCount` badge increments to 1 within 2 s (post-COMMIT debounce — T2.2 Step 5.1 contract).
  - Sync tick fires within ~250 ms of COMMIT (T2.2 verified); `pendingReceiptCount` returns to 0 within ~30 s on Slow-3G.
  - Sync indicator stays green (no degraded amber dot).
  - Receipt PDF renders with correct totals + fiscal hash + QR.
- **Acceptance:** the cashier-critical path (T0.1 Échec du paiement diagnosis target) returns no error; payment goes through cleanly.

### 7. Pay card via real Stripe terminal

- [ ] Initiate a card payment for a fresh cart.
- [ ] Tap a real test card (or insert).
- **Expected:**
  - Stripe terminal status: connecting → reading → processing → approved.
  - Tauri UI mirrors each state.
  - Receipt prints with `payment_method_id` = card method, `instrument_serial` populated from the terminal's response (T2.2 fiscal v3 fixture-08 contract).
- **Acceptance:** card payment closes the cart cleanly, fiscal hash includes the instrument_serial per the v3 schema.

### 8. Refund

- [ ] Find the cash receipt from Step 6 in the receipt list.
- [ ] Initiate a full refund.
- [ ] Verify manager override prompt if cashier session is below the refund threshold.
- [ ] Login as manager, complete refund.
- **Expected:**
  - Refund permission gate enforces correctly per the 2026-05-09 spec realignment (manager has refund_above_threshold + refund_no_receipt + refund_extend_daily_cap + issue_goodwill_voucher + void_voucher + extend_voucher_expiry + refund_destination_override + refund_voucher_to_cash; admin-only items remain admin-only).
  - Refund prints a credit note with negative totals + new fiscal hash chained to the prior receipt.
  - `pendingReceiptCount` increments + drains as in Step 6.
- **Acceptance:** refund flow passes per `RefundFlowPermissionsTest`'s 7 assertions (now green on dev as of `280e22ec`).

### 9. Z-report

- [ ] End the cashier shift.
- [ ] Generate Z-report (cash count + close shift).
- [ ] Print Z-report.
- **Expected:**
  - Z-report PDF renders with sales summary, VAT breakdown, payment methods table.
  - Hash chain advances correctly (verify via `php artisan pos:verify-chains`).
  - Cash count tolerance enforcement works per T1.x cluster.
- **Acceptance:** Z-report sequence increments correctly; `php artisan pos:verify-chains` returns 0 broken chains.

### 10. Sync indicator under flaky network

Toggle network OFF (DevTools or Network Link Conditioner offline mode):
- [ ] Process one cash sale offline.
- [ ] Wait 30 s.
- [ ] Process a second cash sale offline.
- [ ] Verify `pendingReceiptCount` badge shows 2.
- [ ] Verify sync indicator transitions to amber (degraded) per T1.3 contract: tick failed (no network), `lastSyncResult.degraded === true`.
- [ ] Toggle network back ON.
- [ ] Wait for next tick (~60 s default interval, or click SyncButton to retry).
- **Expected:**
  - On retry, both queued receipts push successfully.
  - `pendingReceiptCount` returns to 0.
  - Sync indicator returns to green.
  - No double-billing: each receipt has a unique `idempotency_key` per T0.2.
- **Acceptance:** the offline-first contract holds; no receipts lost or duplicated.

### 11. Crash recovery (Tauri-native)

- [ ] Mid-checkout (cart populated, payment NOT yet initiated): force-quit the Tauri app via OS task manager.
- [ ] Relaunch.
- **Expected:** app boots back to a usable state (login or terminal screen), no auth-orphan, no chain-break banner. Cart contents may or may not be preserved depending on T0.x hold-recall integration; document the actual behavior.
- **Acceptance:** no crash, no manual SQLite repair needed, no fiscal chain breakage.

Then:
- [ ] Mid-checkout (cart populated, payment IN PROGRESS): force-quit the Tauri app while the payment confirm spinner is showing.
- [ ] Relaunch.
- **Expected:**
  - If the COMMIT landed before the kill: the receipt is in SQLite as `status='pending'`, `pendingReceiptCount` reflects it, sync drains on next tick (T2.2 Step 5.1 contract — trigger fires AFTER COMMIT).
  - If the COMMIT didn't land: nothing in SQLite, no fiscal hash advanced, no chain break.
- **Acceptance:** never an in-between state where SQLite has a row but the chain didn't advance, or vice versa. The transactional boundary holds.

### 12. Restart + verify state preservation

- [ ] Quit the Tauri app cleanly.
- [ ] Relaunch.
- **Expected:**
  - Login persists (auth token still valid per T1.4 12-month lifetime if shipped, otherwise the 30-day default).
  - `lastSyncAt` hydrates from SQLite within ~10 ms of activation (T1.3 Step 4.2 hydration contract); SyncButton's "X minutes ago" affordance shows immediately, not blank.
  - `pendingReceiptCount` hydrates from SQLite (T1.3 Step 4.1 contract); badge accurate from boot.
- **Acceptance:** all three T1.3 hydrations land correctly on cold restart.

---

## Pass/fail criteria

The smoke is **PASS** only if:
- All 12 numbered sections close green.
- No console errors at any step (warnings OK if pre-existing and documented).
- No chain-break banner anywhere.
- `php artisan pos:verify-chains` returns 0 broken chains at the end.
- All captures (HAR, screenshots, console logs, backend logs) are bundled and linked from the PR.

Any FAIL means:
- File a follow-up plan doc under `docs/superpowers/plans/` describing the regression.
- Brief the orchestrator.
- Do **NOT** un-draft the dev → main PR.

---

## Other gates that must close before un-drafting

These are not Slow-3G smoke items but are pre-launch audit dependencies. The dev → main draft PR body lists them; they're tracked there, not here.

- [ ] Graphify run — owner: audit phase.
- [ ] Security audit — owner: audit phase.
- [ ] Doc realignment — owner: audit phase.
- [ ] Any other gates surfaced during audit.

---

## Out of scope for this checklist

- **Stress / performance benchmarks** beyond the 5000-SKU smoke (POS performance session owns deeper benchmarking).
- **Multi-terminal concurrency** (parapharmacy launch is single-terminal; defer to T3.x).
- **Tauri auto-update flow** (parapharmacy will get manual installer updates initially).
- **Offline-first cold-start mode** (Phase 0 deferred per T2.2 Step 5.3 sweep — see `terminalStore.ts:90` TODO).
