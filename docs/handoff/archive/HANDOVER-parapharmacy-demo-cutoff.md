# HANDOVER — Para-pharmacy Demo Cutoff (MASTER)

> **Date:** 2026-06-27 · **Owner:** admin@otospex.com · **Status:** cutoff locked, execution next
> **Context:** A potential parapharmacy customer (multiple shops + 1 inventory warehouse, Tunisia/TND)
> needs a stable product covering the basics. We are drawing a hard cutoff so work stops dragging
> before the customer meeting, then testing + hardening end-to-end.

## 1. Strategy — two branches

- **`dev` = customer-facing.** ONLY cutoff work (Bucket B below) lands here. Stabilize → freeze →
  run the E2E campaign → harden on `dev`.
- **Deferred integration branch (long-lived), e.g. `integration/post-launch-deferred` off `dev`.**
  ALL deferred work (Bucket C + deferred items from other sessions) lands here. Kept reasonably
  stable but **NOT merged into `dev` until each piece is proven stable**. This is the delivery
  vehicle for the post-launch backlog.
- Branch/worktree discipline per CLAUDE.md rule 21: worktree off `dev`; merge to LOCAL dev first;
  promote to origin/dev as clean fast-forwards; never force-push shared dev.

## 2. Tenant config flips (free — at tenant setup, no build)

`apps/api/config/verticals.php` → parapharmacy. For the demo tenant:
- **Sales module OFF.** Verified safe: POS retail selling does NOT depend on the Sales module —
  POS sales sync via `POST /api/v1/pos/sync/fiscal-events` (Fiscal module routes, no `module:Sales`
  gate; `PosCoreReceiptProjection::requiresModule()` returns `null`). Removing Sales removes only
  the B2B documents (quotes/orders/invoices).
- **Loyalty ON** (it is a `compatible_extra`; activate via `tenants.enabled_extras` — treat this
  client as having paid for the module).
- Keep: Identity, Tenant, Catalog, Partner, Inventory, BatchExpiry, Treasury, Accounting,
  Parapharmacy.

## 3. Bucket B — MUST LAND on `dev` before freeze

| # | Item | Handover | Size |
|---|------|----------|------|
| B1 | ✅ **DONE (merged to dev 2026-06-27).** Procurement-to-pay (GR-IR, supplier invoice, 3-way match, credit note, supplier payment, web UI + go-live BLOCKER fixes `84b4481d6`/`f7fcae4a4`) is on dev. Closed accounting supplier-cycle gaps #1/#2/#7 + partner-balance B4. | `HANDOVER-cutoff-procurement-landing.md` | — |
| B2 | **Loyalty earn** — per-product points assigned on purchase (the per-product loyalty-points field exists on the new product editor but is an unwired placeholder). Wire field persistence + earn from the live POS projection. Reward-catalog redemption is already built and stays IN. | `HANDOVER-cutoff-loyalty-earn.md` | M |
| B3 | **Seeder batch fix** — `ParapharmacySeeder` flags products `requires_batch_tracking=true` but seeds no `product_batches` → PO/transfer silently blocks. Seed lots (FEFO) reconciling to `StockLevel`. Already specced as Task 2.1 of the E2E plan. | E2E plan §Task 2.1 (`docs/superpowers/plans/2026-06-24-parapharmacy-launch-e2e.md`) | S (1 TDD task) |

## 4. Bucket C — DEFERRED (to the integration branch, NOT on dev)

- **Loyalty pay-with-points tender** (~4–6d + a fiscal-design decision: discount vs tender vs
  customer-credit). No `LoyaltyPoints` case in `PaymentInstrumentKind`, no POS tender UI, no GL
  treatment yet.
- **Accounting residual backlog** toward the "eliminate ~90% of the accountant" goal: POS
  account-charge Draft→Posted (#4), supplier advance GL / import procurement Phase 2 (#3), B2B
  payment-tolerance write-off (#5), `source_type` consistency (#6), opening-balance per-account
  proof granularity (#8). See `PROMPT-accounting-gl-roadmap-audit.md`.
- **Treasury/payments completeness** — see `PROMPT-treasury-payments-audit.md` (audit will classify
  cutoff vs deferred; default assumption = deferred unless audit finds a demo blocker).
- **Branch tax-id P2** (device-signed matricule in SALE_RECEIPT bytes). Print/server side (P0,
  `TaxIdentityResolver` + receipt/Z blades) already works → demo shows correct per-branch matricule.
- Variants (T2), T11 B2B/B2C, composite/86-ing, media-unification beyond procurement's need,
  walkthrough videos (Remotion + in-product tours), deep accounting reports.
- Other sessions' deferred items (margin-hierarchy, opening-balance, uom-quantity-step,
  post-save-stay-on-record, etc.) — fold onto the integration branch as they mature.

## 5. Corrected state (memory was stale — verified 2026-06-27)

- **Security launch-blocker #1 (RoleController privesc): DONE** — merged to dev `5fd...`/`5bbf93f5f`
  (2026-06-19). The Active-Work "deferred" note is stale.
- **Partner balance sign convention: DONE** — merged origin/dev `5e4fc0dab` (2026-06-22),
  non-negative magnitudes locked.
- **Procurement: feature + web UI complete** on `feat/procurement-to-pay` (22 ahead / 62 behind dev
  as of 2026-06-27). Remaining is integration, not feature work.
- **Accounting B2C side is solid + proof-linked** (customer invoice/credit-note/COGS/payment/
  advance, POS receipt, expenses, vouchers, opening balances all carry `source_type`+`source_id`).
  The supplier cycle is wired by B1.

## 5b. State as of 2026-06-29 (pre-restart checkpoint)

**On dev (cutoff DONE):** procurement-to-pay, loyalty EARN (wired into live POS projection, idempotent,
refund-guarded), expenses (+GL), batch-seeding + default-batch invariant, demo-units, variant-aware
stock decrement, POS caisse redesign (dark-mode/token-detox + payment modals). `dev` == `origin/dev`.

**In flight — still MISSING for full cutoff:**
- `fix/pos-refund-void-restock` — returns currently DECREMENT stock on dev (double-removal bug);
  fix being done properly in a parallel session. **Backed up to origin 2026-06-29.**
- `feat/loyalty-pos-online` — POS loyalty chrome (balance/tier/live-earn on attach); "ready to merge",
  stale (rebase needed). Owner folding in if possible. **Backed up to origin.**
- `feat/pos-return-disposition` — spec/plan only (restock vs damage/quarantine + refund fiscal-chain
  reconciliation); bigger feature. Owner folding in if possible. On origin but **diverged
  (13 ahead/3 behind its remote) — owning session must reconcile**.

**Pushed to origin as post-cutoff backups (2026-06-29):** fix/pos-refund-void-restock,
feat/loyalty-pos-online, feat/parapharmacy-merchandising, feat/margin-category-override,
fix/post-save-fast-follows, fix/dashboard-stats-correctness. Already on origin: feat/accounting-gl-go-live,
feat/supplier-invoice-ocr. **Uncommitted WIP (NOT on origin until committed):**
`feat/parapharmacy-merchandising` (5 files @ erp.parapharm), `fix/post-save-fast-follows` (1 file @
erp.savenav-ff) — survive a restart on disk, but commit them to back up.

## 5c. Reduced E2E scope — run NOW, excluding in-flight functionality

We can start the campaign immediately on the happy path, deferring only the returns/refund family and
loyalty-at-POS display until those branches land. Then a short **second test pass** covers the rest.

**INCLUDE (run now):**
- Phase 0 harness, Phase 1 onboarding, Phase 2 catalog (batch-seeding ✅), Phase 3 procure-to-stock
  (PO→GR→transfer ✅), Phase 4.1 claim terminal + open shift, 4.2 sales cash/card/mixed,
  4.4 X/Z + close shift, Phase 5 back-office reconciliation, Phase 6.1 offline drain,
  6.2 permission/boundary checks (non-refund).
- **Loyalty EARN** — verify points credited on a sale via the web loyalty member view (earning is
  server-side on dev even without the POS chrome).

**EXCLUDE (second pass, after the branches land):**
- Phase 4.3 returns/refunds/voids + store-credit (blocked by `fix/pos-refund-void-restock`).
- Phase 6.2 cross-terminal refund.
- POS loyalty chrome display + pay-with-points (`feat/loyalty-pos-online` / deferred redeem).
- Full return-disposition (`feat/pos-return-disposition`).

## 6. Sequence

1. Land B1 (procurement) → this is the freeze trigger.
2. Land B2 (loyalty earn) + B3 (seeder batch fix) in parallel worktrees off dev.
3. Apply config flips (§2) when provisioning the demo tenant.
4. **Freeze `dev`.** Branch `test/parapharmacy-launch-e2e` off frozen dev.
5. Run the E2E campaign (`docs/superpowers/plans/2026-06-24-parapharmacy-launch-e2e.md`),
   **re-weighted**: light on B2B Sales (off); heavy on procurement → multi-shop inventory → POS
   retail → loyalty earn → back-office reconciliation. Web via Playwright MCP; Tauri POS via
   computer-use; Horizon MUST run.
6. Log bugs in the ledger; fix only launch-blockers inline with per-bug OK; everything else → the
   integration branch. Produce the French onboarding guide (E2E Phase 7).
