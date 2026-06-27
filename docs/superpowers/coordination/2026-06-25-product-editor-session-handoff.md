# Session handoff — IZI POS product editor build (start here)

## What this is
Rebuilding the IZI POS product editor to a high-fidelity mock, reuse-and-expand, no regressions. **Theme + chrome are done and confirmed high-fidelity; the remaining work is field/content parity + small backend wiring.** Build from the plan docs below.

## Where to work
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`
- **Branch:** `feat/izipos-theme-product-editor` — **current with `origin/dev`** (behind 0 / ahead 18), tree clean, typecheck + build pass.
- Includes the merged **media unification** (full engine) and **StockMovement "reason"** work.

## Read first (the spec)
1. `docs/superpowers/plans/2026-06-25-product-editor-field-model-and-identity.md` — field model, identity, units, controls, **no-regression map**, §6 build order, **§9 = Codex fixes to apply**.
2. `docs/superpowers/plans/2026-06-24-izipos-product-editor.md` — staged plan (Media un-deferred; Stage 3 unblocked).
3. `docs/superpowers/reviews/2026-06-25-product-editor-plan-codex-review.md` — adversarial review.
4. Pixel source of truth: `docs/handoff/mocks/IZI POS - Add Product.dc.html` (serve it: `cd docs/handoff/mocks && python3 -m http.server 8091`, open `http://localhost:8091/IZI%20POS%20-%20Add%20Product.dc.html`).
5. Loyalty is its OWN session: `docs/handoff/HANDOFF-loyalty-product-fields.md` (do NOT build loyalty here; it's gated on the Loyalty module).

## Start with the Codex HIGH fixes (gating)
1. **Drop "Storable"** from the Type select — `ProductType` enum is `part|service|consumable` only (sending `storable` → 422). Enforce `type`↔`is_physical` consistency server-side.
2. **Move the REAL `BarcodeLookupInput`** (debounce/scanner/enrichment, `features/inventory/components/BarcodeLookupInput.tsx`) into `BarcodeHero`; delete the General copy — kills the two-barcode bug.
3. **Units mirror server-side:** when `unit_id` is set, mirror the unit's code/symbol into legacy `unit` (so `Product::getSellableUnit()` stays correct); expose `unit_id` in `ProductData` (+ `typescript:transform`).
4. **Remove `parseFloat`** at `features/inventory/ProductForm.tsx` WAC display (use `formatCurrency`/bcformat — precision rule 19).
5. **Keep edit-mode `ProductImageSection` + `ProductVariantMatrixEditor`** (no regression); build the new create-mode Media gallery too (full engine is available now).

## Build order (plan §6, refined)
`Toggle` atom → `UnitSelect` atom → **General parity** (SKU+Type+Description+Brand/Mfr/Country placeholders+UnitSelect+Category+Active/e-commerce) → **barcode-into-hero** → **Pricing** (purchase price, computed margin, sale TTC, tax; loyalty/discount fields gated-on-module placeholder) → **Inventory** (units-per-pack NEW, batch Toggle, shelf/reorder product-level nullable) → **Pharmacy** re-lay-out → **Media** section (reuse `ProductImageSection`/`productImages.ts`, restyle to mock grid) → **backend field-wiring** (`type`/`unit_id`/`is_active_for_ecommerce`/`requires_batch_tracking`/`units_per_pack`/`purchase_price` through FormRequest+DTO+`typescript:transform`) → **Stage 2** (brands/manufacturers/country real) → **Automotive** section (Otospex — decide launch scope) → **Stage 3** (opening balance → one `Opening` `StockMovement` with `reason`, lock-after-movement).

## Conventions (enforced — CLAUDE.md)
TDD (failing test first); strict types (no `any`/`mixed`); **design tokens** (`@/lib/designTokens`, no raw Tailwind colors); **`t()` i18n** (en/fr/ar, no hardcoded strings); atoms for all fields; constructor injection only; routes `['api','auth:sanctum',SetPermissionsTeam::class]` + both-layer vertical gating; precision rule 19 (`MoneyInput`/`QuantityInput`, decimal strings); run targeted `--filter`/`vitest` tests, **never the full PHPUnit suite**; `./scripts/preflight.sh` before commits.

## Live visual verification (already set up)
- `izipos-verify-web` nginx container serves THIS worktree's `apps/web/dist` on **http://localhost:8089** (the original demo `erp-dev-web-1` is stopped). After any FE change, `cd apps/web && pnpm build` updates the live page.
- Persistent demo login (Cafe Tunis tenant, user "Ahmed Ben Ali"); product editor at `/inventory/products/new`. Compare against the mock on :8091.
- To restore the original demo container when done: `docker stop izipos-verify-web && docker start erp-dev-web-1`.

## Execution
Subagent-driven (fresh implementer per task + reviewer + ledger at `.superpowers/sdd/progress.md`). NOTE: implementation subagents stalled twice on infra during the prior session — be ready to run some steps directly if they fail. Rebase onto `origin/dev` before starting if it has advanced.
