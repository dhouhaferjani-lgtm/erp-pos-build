# Vertical Setup & Module Gating — Audit Synthesis

**Date:** 2026-06-15
**Scope:** ERP backend (`apps/api`), ERP web admin (`apps/web`), Tauri POS (`apps/pos`). Read-only.
**Trigger:** A team member testing the app "as a restaurant" saw product fields that are relevant only to the **parapharmacy** vertical.
**Method:** 6 parallel read-only audit agents (one per domain) + orchestrator reconciliation. Per-domain reports in this folder (`01`–`06`).

---

## TL;DR

The vertical/module gating system is **architecturally sound and config-driven** (`config/verticals.php` is the single source of truth, read via `VerticalConfigService` with `vertical_configs` DB overrides; the old `Vertical::defaultModules()` enum duplicate was correctly deleted). The product **backend correctly gates every parapharmacy read, write, and serialization on `tenant.vertical === Parapharmacy`**, and the web `ProductForm` + Tauri POS are fail-closed.

**The reported bug is therefore almost certainly a vertical-misconfiguration of the test tenant, not a code leak** — the entire gate is keyed on one value, `tenant.vertical`. If that value is `parapharmacy` (wrong signup selection, a parapharmacy/demo seeder, or shared-`localhost` `localStorage` bleed between IziPOS apps), then *every* surface correctly shows parapharmacy fields. **Pending confirmation from the user of how the "restaurant" was provisioned and exactly which fields were seen** (literal parapharmacy block vs. batch/expiry fields — see HIGH-B).

That said, the audit surfaced **real systemic weaknesses** that are worth fixing regardless of the specific repro, because they make the single-point-of-truth gate brittle and leave several vertical-exclusive features reachable across verticals.

---

## Root-cause analysis of the trigger bug

The full parapharmacy display chain is gated on `tenant.vertical`:

| Layer | Gate | File |
|---|---|---|
| Product list/detail eager-load | `tenant->vertical === Vertical::Parapharmacy` | `ProductController.php:69, 251` |
| Persistence (create/update) | same | `ProductController.php:322, 448` |
| Response serialization | only emits when relation loaded | `ProductData.php:82-87` |
| Web edit form fields | `config?.vertical === 'parapharmacy'` | `ProductForm.tsx:94, 645` |
| Web POS `ProductInfoModal` tab | `!!product.parapharmacy_metadata` (fed by `/products/{id}` above) | `ProductInfoModal.tsx:163` |
| Tauri POS | minimal wire shape, no metadata fields at all | `apps/pos .../types/product.ts` |

Because `/products/{id}` (`show`) only loads/serializes parapharmacy metadata for a parapharmacy tenant, a true `restaurant` tenant receives `parapharmacy_metadata: null` and **no** downstream surface renders it. **Conclusion: a correctly-provisioned restaurant cannot see parapharmacy fields through any audited path.** The leak implies the test tenant's `vertical` was not `restaurant`.

### Two repro hypotheses to confirm with the reporter
- **(A — most likely) Wrong vertical on the test tenant.** Signup defaults / a parapharmacy or demo seeder (`DemoTenantSeeder`) / shared-`localhost` `localStorage` bleed left `tenant.vertical = parapharmacy`. → Not a code bug; a provisioning + guard-rail + docs problem.
- **(B) The tester saw *batch/expiry* fields, not the literal parapharmacy block.** `config/verticals.php` sets `requires_batch_tracking => true` for **restaurant AND coffee_shop**, which auto-enables lot/expiry tracking on physical products (`Product.php:175`). These are pharmacy-flavored and could be *perceived* as "parapharmacy" data. This is config-intended today but worth a product decision. (Note: `ProductForm` renders no batch fields, so this would surface elsewhere — confirm where.)

---

## Cross-cutting findings (ranked)

### CRITICAL / HIGH

- **[HIGH-A] 9 of 22 modules have no route gate.** Only `Inventory, Vehicle, Workshop, Ecommerce, Parapharmacy` are gated by `RequireModule` in routes. **`Menu, Tables, BatchExpiry, Prescription, Loyalty, CompositeItems, Appointments, Reservation, Fleet` have no `module:` gate** — e.g. Menu CRUD (`Menu/.../routes.php:12`) and pharma lot/expiry/recall (`BatchExpiry/.../routes.php:11`) are reachable by any vertical with the right permission. Gating is opt-in per route file and nothing in CI fails when a vertical-exclusive route omits it. *(Report 02)*
  - **AMENDED 2026-08-05 — the denominator was wrong, and two modules are invisible to the whole model.** Re-counted against `app/Enums/ModuleName.php` and every `module:` middleware usage under `app/Modules/*/**/routes.php`:
    - The enum has **24** cases, not 22. `Merchandising` and `PurchaseBonus` are enum cases that are **missing from the module catalog table** in [`docs/architecture/vertical-module-gating.md:259-282`](../../../architecture/vertical-module-gating.md) — that 22-row table is where "22" came from. Fix the table when acting on this finding.
    - **9 of the 24 carry a `module:` gate somewhere**: `BatchExpiry, CompositeItems, Ecommerce, Inventory, Loyalty, Menu, Parapharmacy, Vehicle, Workshop` (`Menu`, `BatchExpiry`, `Loyalty`, `CompositeItems` were gated after the original audit; `CompositeItems` via `Catalog/Presentation/routes.php`, `Ecommerce` via `Channel/…`, `Parapharmacy` + `Inventory` via `Product/routes.php`, `Workshop` via `Service/…` and `Scheduling/…`).
    - **15 have no gate at all.** Eight of those are cross-vertical core where a gate is meaningless (`Identity, Tenant, Catalog, Partner, Sales, Treasury, Accounting, PlatformIntegration`). The **7 that are genuinely vertical-exclusive and ungated** are `Tables, Appointments, Fleet, Prescription, Reservation, Merchandising, PurchaseBonus` — the last two never appeared in this audit at all because they are absent from the catalog table.
    - **Plus 2 modules that are not in the enum, so they *cannot* be gated:** `Marketplace` (`app/Modules/Marketplace/Presentation/routes.php`) and `Procurement` (`app/Modules/Procurement/Presentation/routes.php:20-23`, which documents the omission). `ModuleNameTest` pins the enum to the union of `config/verticals.php`, so adding a case is a **product decision** about which verticals sell the module — not a code fix. Both route files rely on per-route `can:` permissions only.
    - `Marketplace` additionally carries a live privilege-escalation exposure (a fleet-wide seller-admin surface behind a permission every tenant admin holds). It is contained as of 2026-08-05 by the `config('marketplace.enabled')` kill-switch (default **false**) rather than by module gating; the redesign is tracked in [`docs/superpowers/tickets/2026-08-05-marketplace-admin-surface-redesign.md`](../../tickets/2026-08-05-marketplace-admin-surface-redesign.md).
    - **Implementation gotcha for the eventual CI guard — and its exact scope:** under a **non-authoritative autoloader (local dev + CI)** a module's `routes.php` is `include`d even when its service provider never calls `loadRoutesFrom()` — `config/event-sourcing.php` scans `app()->path()` and `DiscoverEventHandlers` PSR-4-autoloads every file under it, so the file registers its routes regardless of the provider. **This does NOT hold in the production image** (corrected 2026-08-05 after adversarial review of the marketplace kill-switch): `apps/api/Dockerfile` builds with `composer dump-autoload --optimize --classmap-authoritative`, and `vendor/composer/ClassLoader::findFile()` short-circuits to `false` before the PSR-4 fallback, so a class-less routes file is never included by the discovery probe and the **provider condition is the effective gate there**. A CI guard must therefore reason about **both** layers — the routes file itself (dev/CI truth) and the provider's `loadRoutesFrom()` condition (production truth) — and any module relying on a conditional route load must carry the guard in both places, as `Marketplace` now does.
- **[HIGH-B] `requires_batch_tracking=true` for restaurant & coffee_shop** auto-applies pharmacy-style batch/lot/expiry to physical products. Config-intended (asserted in tests) but is the most plausible "pharmacy-ish fields on a restaurant" explanation if the tester didn't mean the literal parapharmacy block. Product decision needed; no per-tenant override exists to turn it off. *(Reports 01, 03)*
- **[HIGH-C] `CreateProductRequest`/`UpdateProductRequest` accept `parapharmacy_metadata.*` and `automotive_metadata.*` with no vertical guard** (`sometimes`/`nullable`). A restaurant can POST parapharmacy metadata and get a 2xx; the controller silently discards it. Latent leak if any future code path persists `$validated['parapharmacy_metadata']` without re-checking vertical. *(Report 03)*
- **[HIGH-D] Frontend gating is inconsistent and partly module-blind.** Four different strategies in use: raw `config.vertical` string (`ProductForm`), `hasModule()` (sidebar), build-time `isOtospex` env (automotive fields), and **data-presence** (`ProductInfoModal` parapharmacy tab, `/parapharmacy/*` routes guarded only by `RequirePermission settings.manage`, no `ModuleGuard`). A `settings.manage` user on any vertical can deep-link to parapharmacy authoring pages. *(Report 04)*

### MEDIUM

- **[MED-1] Scheduling gated by `module:Workshop` instead of `Appointments`** (`Scheduling/...:52`) — the `Appointments` extra does nothing, and Appointments-compatible `tire_shop` (no Workshop default) is wrongly blocked. *(Report 02)*
- **[MED-2] Controller silently drops mismatched metadata** (no 422/log), masking HIGH-C. *(Report 03)*
- **[MED-3] Automotive fields gated on build-time env, not tenant module** — inconsistent with the sidebar's runtime `hasModule`. *(Report 04)*
- **[MED-4] POS hardcodes module/vertical strings** (`'Menu'`, `'parapharmacy'`) instead of importing backend `ModuleName`/`Vertical`; currently in sync but no drift guard. *(Report 05)*
- **[MED-5] Batches page gated differently** in sidebar (`['BatchExpiry','Parapharmacy']`) vs inventory hub (`Parapharmacy` only). *(Report 04)*

### Documentation (root cause of the *misunderstanding*)

- **[DOC-1] Root `claude/` docs describe a different codebase (the Synerivia platform), not the ERP vertical model.** `claude/architecture.md`, `vertical-boundaries.md`, `glossary.md` talk about per-vertical DBs and `Automotive/*` / `Parapharmacy/*` platform modules; the ERP's `tenants.vertical` enum + `config/verticals.php` + `enabled_extras` + `RequireModule` model is never mentioned. The glossary's "vertical = own DB + own modules" actively contradicts the ERP meaning (12 verticals share one tenant DB). *(Report 06)*
- **[DOC-2] The `Parapharmacy` module and `config/verticals.php`-as-SoT are undocumented.** `module-loading.md` / `security.md` (dated 2025-12-30, never updated) list phantom modules (`Communication`, `Media`, `Recipe`) and per-vertical module lists that contradict `config/verticals.php`. *(Report 06)*
- **[DOC-3] No doc states that vertical-specific product metadata must be gated**, nor which layer is canonical (backend gates on `module:Parapharmacy`; frontend on `vertical` string). A developer following the docs would not know to gate it — the structural reason this class of bug recurs. *(Report 06)*
- **[DOC-4] No tester/QA guidance** on choosing a vertical, mapping scenarios → seeders, or verifying via `GET /api/v1/company/config`. Directly relevant to how the reporter ended up on the wrong vertical. *(Report 06)*

### What is correct (INFO — do not "fix")

- Vertical is set once at registration from the enum-validated signup field; DB default is the safe `'retail'`; no parapharmacy default anywhere. Seeders set correct verticals. *(Report 01)*
- Module activation is computed from `vertical.default_modules` + `tenants.enabled_extras`; admin `updateExtras` rejects extras outside `compatible_extras` (422); `ModuleNameTest` drift guard keeps enum ↔ config in sync. No over-activation path. *(Report 02)*
- Product backend read/write/serialize fully vertical-gated. *(Report 03)*
- Tauri POS is not vertical-leaky: F&B (Menu) chrome and parapharmacy Smart Prompts are both gated; the POS product wire shape carries no vertical metadata; missing-config fallback fails safe. *(Report 05)*

---

## Branch caveat

On this `docs/media-subsystem-architecture` branch, `PosStockPolicy::defaultForVertical()` still calls the deleted `Vertical::defaultModules()` → fatal on every registration. Memory records this as **already fixed on `dev` (`c37fd6471`)**. Verify the fix is present before drawing conclusions from live behavior on this branch. *(Report 01)*

---

## Recommended follow-ups (NOT done — audit is report-only)

**Make the gate robust (the system is single-point-of-truth on `tenant.vertical`):**
1. Add `RequireModule` gates to the ungated vertical-exclusive module route groups (HIGH-A); add a CI/test guard that fails when a vertical-exclusive route lacks a module gate. *(2026-08-05: the live list is `Tables, Appointments, Fleet, Prescription, Reservation, Merchandising, PurchaseBonus` — see the HIGH-A amendment. `Marketplace` and `Procurement` need a `ModuleName` case first, which is a product decision, and the catalog table in `docs/architecture/vertical-module-gating.md:259-282` needs `Merchandising` + `PurchaseBonus` added.)*
2. Add a vertical/module guard to `CreateProductRequest`/`UpdateProductRequest` returning 422 instead of silently dropping cross-vertical metadata (HIGH-C).
3. `ModuleGuard` the `/parapharmacy/*` web routes and switch `ProductInfoModal` + `ProductForm` to `hasModule('Parapharmacy')` instead of data-presence / raw vertical string (HIGH-D). Introduce one central FE capability helper.

**Product decision:**
4. Confirm whether restaurant/coffee_shop should really have `requires_batch_tracking=true` (HIGH-B); if not, flip config and/or add a per-tenant override.

**Docs (prevent recurrence of the misunderstanding):**
5. Rewrite the ERP-side vertical/module docs from `config/verticals.php`; add the `Parapharmacy` module; state config-as-SoT + the `vertical_configs` override layer; add a "gate vertical-specific metadata" rule to the new-feature checklist; add QA "choosing a vertical for testing" guidance; disambiguate "vertical" between platform and ERP in the glossary; add discoverable pointers from `apps/erp/CLAUDE.md`. (See report 06 for the precise file/section list.)

---

## Addendum (2026-06-15, post-owner-review)

New facts established after the initial six reports, plus owner decisions.

### Reproduction narrowed
- The tester used a **fresh signup, selecting Restaurant** (rules out seeders / `localStorage` bleed). The signup form *does* send the chosen vertical (`apps/web/.../useRegisterForm.ts:137`).
- **A real restaurant tenant cannot reach the inventory `ProductForm` parapharmacy block:** `/products` create/update is gated by `module:Inventory` (`Product/routes.php:42`) and restaurant's `default_modules` omits `Inventory`; the FE form also gates the block on `config.vertical==='parapharmacy'`. Restaurants author sellable items through the **Menu** feature (`apps/web/src/features/menu/*`), whose item manager exposes only price/search fields — no parapharmacy, no expiry.
- **Therefore the literal parapharmacy block should not appear for a genuine restaurant tenant.** The two viable explanations are (a) the tester actually saw **batch/expiry** fields (`requires_batch_tracking=true`), or (b) the tenant's `vertical` was not persisted/resolved as `restaurant` (signup→config bug). **Diagnostic:** read `GET /api/v1/company/config` → `vertical` for that tenant + screenshot the fields.

### Config inconsistency found (supports the batch decision)
`config/verticals.php`: restaurant has `requires_batch_tracking => true` **but** its `default_modules` contains **neither `Inventory` nor `BatchExpiry`**. So the "track batches" product default is on while the modules that surface batch/expiry management are off — the flag and the gating are misaligned. (`BatchExpiry` is a `compatible_extras` only for pharmacy.)

### Owner decisions (to implement later; this audit stays report-only)
1. **Batch/expiry for restaurant/coffee_shop is legitimate but must be optional.** Restaurants do have perishable ingredients, so the default isn't "wrong" — but it should be **opt-in per product** (e.g. a "tracks expiry / batch-managed" checkbox flagging which products carry expiry) and/or offered as a **paid upgrade module** the owner can enable, rather than forced on every physical product. Reconcile with the `Inventory`/`BatchExpiry`/`requires_batch_tracking` misalignment above.
2. **Gate `parapharmacy_metadata` (and `automotive_metadata`) acceptance by vertical/module and REJECT, don't silently drop.** Even though it's not the on-screen leak, `CreateProductRequest`/`UpdateProductRequest` accepting cross-vertical metadata that the controller then discards is a latent correctness hole; it should return 422 when the tenant's vertical/module doesn't permit it (HIGH-C). Owner flagged this as a priority hardening item.

## Reports
- `01-vertical-setup-provisioning.md`
- `02-module-gating-backend.md`
- `03-product-metadata-gating-backend.md`
- `04-frontend-gating-web.md`
- `05-pos-tauri-gating.md`
- `06-documentation-audit.md`
