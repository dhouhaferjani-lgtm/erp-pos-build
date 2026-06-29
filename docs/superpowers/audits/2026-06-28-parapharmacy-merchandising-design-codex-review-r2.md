# Adversarial Design Review R2 - Parapharmacy Merchandising Amended Deltas

Reviewer: Codex. Date: 2026-06-28.

Scope: only the two amended deltas requested: vertical data gating in §2 and POS UI ownership expansion in §5. I did not re-litigate round-1 findings that the amended spec already folded.

## Verdict

The product merchandising-array part of the vertical-gating amendment is sound: if the new arrays are added to `ParapharmacyProductMetadataData` and loaded under the existing `parapharmacyMetadata.*` branches, non-parapharmacy product reads will not serialize them.

The amendment is not fully sound because customer `skin_type` / `skin_advice_note` are on a separate `/pos/customers/sync` path that is not currently vertical-aware. The spec claims those fields are parapharmacy-only but does not specify the explicit guard needed to make that true.

## Validated

### Product merchandising arrays ride the parapharmacy metadata gate

Spec §2a says the new product arrays extend `ParapharmacyProductMetadataData` and ride the existing conditional metadata load. That matches the current product read/write paths:

- `ProductController::index()` initializes `$with` with only `category` and `unitOfMeasure`, then adds `parapharmacyMetadata.ingredients`, `keyComponents`, `healthClaims`, and `certifications` only when `$company->tenant->vertical === Vertical::Parapharmacy` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:72`, `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:74`).
- `show()` loads base `unitOfMeasure`, then separately loads `parapharmacyMetadata.*` only inside the same vertical check (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:267`, `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:283`).
- `store()` only creates parapharmacy metadata when metadata is present and the tenant vertical is parapharmacy (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:365`), and only loads that metadata for the response under the same vertical check (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:398`).
- `update()` only writes parapharmacy metadata under the parapharmacy vertical check (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:493`) and only loads it for the response under the same check (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:541`).
- `ProductData::fromModel()` serializes `parapharmacy_metadata` only when the `parapharmacyMetadata` relation is loaded and non-null (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:92`).
- `ParapharmacyProductMetadataData` is the DTO that owns the parapharmacy metadata payload constructor/fromModel mapping (`apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:30`, `apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:51`).

Concrete implementation note: the new `skinSuitabilities`, `equivalentProducts`, `complementProducts`, and `routines` relation loads must be added inside the existing parapharmacy-only load lists above. If an implementer adds those relations to a base `$with`, the spec's safety claim no longer holds.

## Critical

### C1. Customer skin fields will leak through `/pos/customers/sync` unless the spec adds an explicit vertical guard

Spec sections: §1.4, §1.8, §2a, §4.

The spec says `partners.skin_type` / `skin_advice_note` are "serialized/synced only for the parapharmacy vertical" and that non-parapharmacy tenants never receive them (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:124`). But customer sync does not use the product metadata path. It is routed separately at `/pos/customers/sync` (`apps/api/app/Modules/POS/routes.php:120`) into `PosCustomerSyncController`.

That controller currently authorizes POS operation, resolves tenant/company IDs, and queries customers by tenant/company/type only (`apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:31`, `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:33`, `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:39`). It never resolves the company object, checks `tenant->vertical`, or imports `Vertical`. The resource it maps through returns a fixed customer mirror array with no vertical context (`apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:56`, `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:28`). If the implementation follows §1.8 and simply adds `skin_type` / `skin_advice_note` to that resource, those fields will be emitted for every vertical with customers.

The POS pull confirms this is the live sync path: `pullCustomers()` calls `apiGet('/pos/customers/sync', ...)` (`apps/pos/src/lib/customer/customerSyncService.ts:149`), parses `CustomerMirrorRow[]` (`apps/pos/src/lib/customer/customerSyncService.ts:13`), and upserts each row (`apps/pos/src/lib/customer/customerSyncService.ts:158`). There is no product-metadata gate in that flow.

Concrete fix:

- In `PosCustomerSyncController::index()`, resolve the company (`requireCompany()`), compute `$isParapharmacy = $company->tenant->vertical === Vertical::Parapharmacy`, and pass that boolean to the resource, or set it on the request.
- In `PosCustomerMirrorResource`, include `skin_type` and `skin_advice_note` only when that boolean is true. For non-parapharmacy tenants, omit the keys entirely if the contract is "never receive"; do not send `null` keys and call it gated.
- Add feature tests for `/pos/customers/sync`: parapharmacy response includes the two fields; a non-parapharmacy response with populated partner columns excludes both fields.
- Keep the POS SQLite columns nullable as §4 says, but make the server payload the authority for vertical non-leakage.

## Important

### I1. §2's "no rule-12 exception" conflicts with the "unbundle later = config-only" claim

Spec section: §2.

The current code supports vertical-gated product metadata, and the architecture docs explicitly allow automotive product metadata to be authorized by vertical rather than module because it has no single module (`docs/architecture/vertical-module-gating.md:200`). But the repo's rule 12 says vertical-exclusive routes and fields must be module-gated on both layers (`CLAUDE.md:49`), and the gating guide says vertical-specific fields/sections should be gated inline with `hasModule('<Name>')` (`docs/architecture/vertical-module-gating.md:164`).

The spec simultaneously says the merchandising experience is module-gated (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:129`), can be unbundled later by moving `Merchandising` from `default_modules` to `compatible_extras` (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:133`), and that rule 12 is satisfied because backend data is vertical-gated while FE UX is module-gated (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:136`). That hand-waves the unbundled state: once `Merchandising` is off for a parapharmacy tenant, product/customer merchandising data still flows by vertical unless backend serialization also checks module entitlement.

Backend config can determine module entitlement: `CompanyConfigService` merges vertical defaults and enabled extras into `all_enabled_modules` (`apps/api/app/Services/CompanyConfigService.php:57`, `apps/api/app/Services/CompanyConfigService.php:71`), and POS `hasModule()` reads that list (`apps/pos/src/stores/productStore.ts:110`). The amended spec uses that only for FE experience gating, not backend field gating.

Concrete fix: choose one contract and state it plainly.

- If merchandising data is truly parapharmacy-core metadata, keep vertical-gated data and change §2/§9 to say unbundling hides only the POS experience; backend data remains available to parapharmacy tenants. That is a signed rule-12 exception/precedent, not "both layers genuinely gated."
- If `Merchandising` is a paid extra later, backend serialization for product merchandising arrays and customer skin fields must also check module entitlement, not just vertical. That likely means a helper that evaluates the tenant/company effective config server-side and tests for parapharmacy-with-module vs parapharmacy-without-module.

### I2. §5.2 cites a shipped `density` setting that does not exist in this worktree

Spec section: §5.2.

The current `settingsStore` has `displayMode`, language, touch/fullscreen, cart position, inactivity timeout, and lock-after-sale fields, but no density field or setter (`apps/pos/src/stores/settingsStore.ts:5`, `apps/pos/src/stores/settingsStore.ts:18`). Its defaults also omit density (`apps/pos/src/stores/settingsStore.ts:35`). `SettingsPage` reads and writes `displayMode` only for the display mode UI (`apps/pos/src/pages/SettingsPage.tsx:44`, `apps/pos/src/pages/SettingsPage.tsx:48`, `apps/pos/src/pages/SettingsPage.tsx:170`).

The dual-source display-mode problem is real: active `HomePage` imports `ProductGrid` from `@/components/organisms/ProductGrid` (`apps/pos/src/pages/HomePage.tsx:50`), and that grid reads `localStorage['pos-display-mode']` via `getStoredDisplayMode()` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:24`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:33`), stores local component state (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:93`), and writes localStorage on toggle (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:107`). It never reads `useSettingsStore`.

But `getColumns()` currently accepts only `displayMode`, not density or an explicit width (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:60`), and card sizing has only `CARD_MIN_H_GRID` / `CARD_MIN_H_VISUAL` constants (`apps/pos/src/components/molecules/ProductCard/cardSizing.ts:12`, `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:17`).

Concrete fix: rewrite §5.2 to say density must be introduced on this branch unless it is guaranteed by the redesign rebase. If it is redesign-only, add an explicit rebase checkpoint: verify `settingsStore.density` exists before starting §5.2, otherwise add it with persisted migration/default semantics and settings UI.

### I3. The `/customers` placeholder route/nav claim is not true in this worktree

Spec section: §5.0 / §5.4.

The spec says the `/customers` page placeholder, route, and nav-rail item already exist on the redesign branch and should be built out in place (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:174`). In this worktree, the POS route tree has only `/`, `/settings`, `/sales`, `/reports/z`, and a catch-all redirect (`apps/pos/src/components/AppShell.tsx:145`). The top-level router delegates `/*` to `AppShell` (`apps/pos/src/App.tsx:346`). There is no `NavRail` file in `apps/pos/src` from `rg --files`, and the current header imports direct navigation controls rather than a nav rail (`apps/pos/src/components/Header.tsx:3`, `apps/pos/src/components/Header.tsx:21`).

Concrete fix: keep the redesign-branch warning, but do not imply the route exists in this worktree. Change §5.0 to: "After rebasing onto `feat/pos-caisse-redesign`, verify the existing `/customers` placeholder route/nav item and build there; if absent, add one in the redesign UI layer, not in the pre-rebase data/sync work."

### I4. Several §5 atom/token symbols are redesign-branch-only, not present under current `apps/pos/src`

Spec section: §5.0 / §5.1.

The spec instructs reuse from `@/components/ui`: `ProductThumb`, `StockBadge`, `Pill`, `Tabs`, `Badge`, `SegmentedControl`, `Button`, and `IconButton` (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:176`). Current `components/ui/index.ts` exports only `Button`, `IconButton`, `Badge`, `StatusPill`, and `SegmentedControl` (`apps/pos/src/components/ui/index.ts:11`, `apps/pos/src/components/ui/index.ts:14`, `apps/pos/src/components/ui/index.ts:17`, `apps/pos/src/components/ui/index.ts:20`, `apps/pos/src/components/ui/index.ts:23`). `apps/pos/docs/design-language.md` likewise lists those existing atoms and does not mention `ProductThumb`, `StockBadge`, `Pill`, or `Tabs` (`apps/pos/docs/design-language.md:37`).

The current `index.css` token block defines surfaces/ink/border/action/brand/status tokens but no `stock-*`, `accent`, `rounded-tile`, or `rounded-card` tokens in the visible theme section (`apps/pos/src/index.css:100`, `apps/pos/src/index.css:124`, `apps/pos/src/index.css:130`, `apps/pos/src/index.css:133`). The spec correctly says `ezTap` is "not yet present" and must be added (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:182`); current `index.css` has no keyframes after the utility block (`apps/pos/src/index.css:151`).

Concrete fix: classify these as hard rebase prerequisites. Before §5 UI work starts, verify the redesign branch supplies `ProductThumb`, `StockBadge`, `Pill`, `Tabs`, stock/accent/rounding tokens, and `NavRail`. If not, either move their creation into the redesign session's ownership list or explicitly authorize this session to add them after pinging the redesign owner.

## Nit

### N1. §5 should name the active ProductGrid/ProductCard tree to avoid editing the legacy copy

Spec section: §5.0.

There are duplicate older files under `apps/pos/src/components/pos/ProductGrid.tsx` and `apps/pos/src/components/pos/ProductCard.tsx`, but the active `HomePage` imports `@/components/organisms/ProductGrid` (`apps/pos/src/pages/HomePage.tsx:50`) and that grid imports `@/components/molecules/ProductCard` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:9`). The spec says "ProductCard" / "ProductGrid" without paths.

Concrete fix: update §5.0/§5.1/§5.2 to name `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx` and `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` as the in-place targets after rebase.

### N2. Existing ProductCard invariants cited by §5.1 do exist

This is not a blocker. The current card has the data-testids the spec says to preserve: `in-cart-badge` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:178`), `view-details-button` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:193`), `price-row` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:266`), `stock-row` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:277`), and `incoming-badge` (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:299`). It also has the three-path `locationStock` stock logic (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:73`), modifier handling (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:71`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:214`), activation blocking (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:132`), keyboard handling (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:141`), and `memo` export (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:313`).
