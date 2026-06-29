# Adversarial Design Review (Round 2) — Parapharmacy Merchandising: the two amended deltas only

> Reviewer: skeptical senior architect (Claude). Date: 2026-06-28.
> Spec under review: `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md`
> Worktree root: `/Users/houssamr/Projects/syneriva/apps/erp.parapharm` (branch `feat/parapharmacy-merchandising`, off `origin/dev`).
> Scope: ONLY the two round-2 deltas. Round-1 findings (already folded) are not re-litigated.
> - **DELTA 1** — gating flipped to vertical-gating (§2, §1.4, §4 references).
> - **DELTA 2** — §5 POS UI ownership expansion (ProductCard/ProductGrid restyle, settingsStore dual-source reconcile, `/customers` build-out).

**Counts: 1 Critical, 2 Important, 3 Nit. Verdict: the customer-sync vertical-gating does NOT hold — it leaks to every vertical and needs an explicitly-specified guard. Product-side vertical-gating holds. Most of §5 is blocked on the redesign branch, as the spec partly (but not fully) admits.**

---

## DELTA 1 — vertical-gating

### [CRITICAL] D1-C1. Customer `skin_type`/`skin_advice_note` vertical-gating is UNENFORCEABLE as written — the fields leak to ALL verticals
**Spec claims (must hold):** §1.4 line 88 "Serialized/synced only for the parapharmacy vertical (§2a)"; §2a line 124 "Customer `skin_type`/`skin_advice_note` are serialized/synced only for parapharmacy tenants. Non-parapharmacy tenants never receive these fields"; §4 line 157.

**Code evidence — there is no gate, and no vertical in scope to build one from:**
- `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:21-52` — `toArray()` returns a **flat, unconditional** array. Every key is emitted for every caller; there is **no `when()`, no vertical branch**. Adding `'skin_type' => $this->skin_type` / `'skin_advice_note' => $this->skin_advice_note` here ships them to every tenant.
- `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:29-69` — `index()` resolves only `requireTenantId()` (line 33) and `requireCompanyId()` (line 34). It **never resolves the tenant vertical** and **never passes a vertical/flag into the resource** (line 56 calls `(new PosCustomerMirrorResource($customer))->toArray($request)` with no vertical context).

The product path's gate is real precisely because the loader is conditional (`ProductController` only eager-loads `parapharmacyMetadata.*` inside `if ($company->tenant->vertical === Vertical::Parapharmacy)` — `ProductController.php:74, 284, 399, 542`). The customer path has **no equivalent conditional anywhere**. So the spec's "parapharmacy-only" promise for the customer fields is currently a statement with nothing enforcing it.

**Where the guard MUST go (both required):**
1. **Controller** — `PosCustomerSyncController::index` must resolve the vertical and pass a boolean into the resource. The vertical IS reachable: `CompanyContext::requireCompany()` eager-loads `tenant` (`apps/api/app/Modules/Company/Services/CompanyContext.php:100-103`), so add `$isParapharmacy = $this->companyContext->requireCompany()->tenant->vertical === Vertical::Parapharmacy;` and thread it into the resource (constructor arg or `->additional([...])`). The controller currently does **not** call `requireCompany()`, so this is a net-new line the spec must mandate.
2. **Resource** — `PosCustomerMirrorResource::toArray` must wrap the two keys in `$this->mergeWhen($isParapharmacy, ['skin_type' => ..., 'skin_advice_note' => ...])` (or equivalent), keyed off the flag the controller passes. A bare `$this->when(...)` cannot work without the controller supplying the condition — the resource has no access to the tenant vertical on its own.

**Why this is Critical, not cosmetic:** without the guard, every IziPOS/Otospex POS that runs customer sync receives parapharmacy skin columns in its `/pos/customers/sync` payload. The device side (§4 v59) adds the SQLite columns "regardless… stay null for non-parapharmacy — harmless" — but that harmlessness assumption is only true if the server actually withholds the data, which it does not. This is the exact rule-12 both-layer failure the round-1 reviews flagged for products, reappearing on the customer path that the delta did not re-audit.

---

### [IMPORTANT] D1-I1. "The merch arrays extend `ParapharmacyProductMetadataData` so they ride the gated load automatically" is structurally broken — the relations live on `Product`, the DTO carrier cannot reach them
**Spec:** §1.8 lines 106-108 declares the four merch arrays on `ParapharmacyProductMetadataData`; §2a line 124 says they "ride that gated load automatically." But §1.5 line 91 (`Product::skinSuitabilities(): HasMany`), §1.6 line 97 (`Product::routines()`), §1.7 line 102 (`Product::equivalentProducts()/complementProducts()`) anchor every new relation on **`Product`**, not on `ParapharmacyProductMetadata`.

**Code evidence:** `ParapharmacyProductMetadataData::fromModel(ParapharmacyProductMetadata $metadata)` (`apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:50` onward) receives **only the metadata model** and reads relations **off that metadata model** (`$metadata->ingredients`, `$metadata->keyComponents`, …, even self-lazy-loading them at lines 54-65). It has **no handle to the parent `Product`**, so it cannot read `Product::skinSuitabilities`/`routines`/`equivalentProducts`/`complementProducts`. `ProductData::fromModel` only constructs the metadata DTO `if ($product->relationLoaded('parapharmacyMetadata') …)` (`ProductData.php:92-93`).

**Consequence:** as literally specified, the merch arrays declared on `ParapharmacyProductMetadataData` have no data source — they will be null/empty, or the implementer will "fix" it by eager-loading `Product::skinSuitabilities` **outside** the vertical conditional and mapping it on `ProductData`, which **reintroduces the exact leak of D1-C1** for products.

**Fix (pick one, state it):**
- (a) **Anchor the new relations on `ParapharmacyProductMetadata`** (parented via `product_id`, like `ingredients`) so `ParapharmacyProductMetadataData::fromModel` can source them. Because that DTO is **only ever built for parapharmacy** (`ProductData.php:92`), the gate then genuinely "rides automatically." This contradicts §1.5/§1.6/§1.7's "on `Product`" wiring — reconcile it. OR
- (b) Keep the relations on `Product`, map the arrays on `ProductData` (not `ParapharmacyProductMetadataData`), and **explicitly eager-load them only inside the `if vertical === Parapharmacy` branch** on all four read paths (`ProductController.php:74, 284, 399, 542`) with `ProductData::fromModel` mapping them **only when `relationLoaded`** — mirroring the existing parapharmacy/automotive pattern. Then drop the "rides ParapharmacyProductMetadataData automatically" framing, because it doesn't.

Either way the spec's current mix (relations on `Product` + arrays on the metadata DTO + "rides automatically") is internally inconsistent and is the load-bearing claim of the whole DELTA-1 product-gating argument.

---

### [IMPORTANT] D1-I2. "Unbundle to a paid extra later = config-only" contradicts vertical-gated data — the customer keeps the data, defeating the paywall
**Spec:** §0.1 line 25 / §2c lines 130, 133 promise unbundling is "config-only": move `Merchandising` from `default_modules` to a priced `compatible_extras` entry.

**The contradiction (this is the question the brief raised, and it is real):** after that config move, a parapharmacy tenant that has **not** bought the extra still has `vertical === Parapharmacy`. Per DELTA 1, all merchandising **data** is gated on the **vertical**, not the module — so:
- The server still emits skin suitability / equivalents / complements / routines on `/products` (vertical-gated, D1-I1 path) and skin fields on `/pos/customers/sync` (once D1-C1 is fixed, still vertical-gated).
- The device still pulls and stores all of it in SQLite (§4 v58/v59).
- Only `hasModule('Merchandising')` flips false, hiding the **UX**.

So the unpaid tenant's terminal holds the complete merchandising dataset locally; the "paywall" is a UI hide, trivially defeated by reading SQLite or flipping the client flag. For a **bundled-by-default demo** this is harmless and acceptable. But the spec **overstates** the productization seam: "unbundle = config-only" is false for any real paid-feature confidentiality. True paid-gating requires the **data emission to also become module-gated** server-side (a code change — make the `/products` merch arrays and `/pos/customers/sync` skin fields conditional on the tenant having the `Merchandising` entitlement, not just the vertical), which is exactly what §2 deliberately removed.

**Fix:** state plainly that unbundling-to-paid **hides UX only; data still ships and is cached on-device**, and that withholding the data from non-entitled tenants is a **future code change (module-gate the payload), not config-only**. Decide now whether that is acceptable for the eventual paid SKU; if confidentiality matters, the data must be module-gated too. Do not leave "config-only" as the documented unbundle path — it is the one sentence in DELTA 1 that promises more than the architecture delivers.

---

### DELTA 1 — paths that ARE consistent (no action)
- **Product-side vertical-gating HOLDS.** `ProductController` index/show/store/update eager-load `parapharmacyMetadata.*` **only** inside `if ($company->tenant->vertical === Vertical::Parapharmacy)` (`ProductController.php:74-79, 284-291, 398-406, 541-549`); `ProductData::fromModel` maps `parapharmacy_metadata` **only when** `relationLoaded` (`ProductData.php:92-93`). Provided D1-I1 is resolved via option (a) or (b)-with-conditional-load, the merch arrays inherit a genuine gate.
- **POS product pull uses the SAME gated endpoint.** The device pulls `/products` (`apps/pos/src/api/productApi.ts:9,13,35`; `syncService.ts:565`), routed to `ProductController::index` (`apps/api/app/Modules/Product/routes.php:45`). There is **no** POS-specific product serializer that could bypass the vertical gate — so the brand-universal vs merch-parapharmacy split is consistent on the product pull. Brand is loaded on all paths (§1.8) and is intentionally universal (§2b) — consistent.
- The **only** inconsistent read/sync path is the customer sync (D1-C1).

---

## DELTA 2 — §5 POS UI ownership expansion

### [NIT] D2-N1. §5.2 says density/theme/accent/corner "already shipped" — they are NOT on this branch; the density work is blocked, the displayMode reconcile is not
**Spec:** §5.0 line 174 / §5.2 line 186 assert "the Appearance settings — theme/accent/corner/density — already shipped" and require threading density into `getColumns(displayMode, density, width)` + a 4-cell `CARD_MIN_H_*` matrix.

**Code evidence on this branch:**
- `apps/pos/src/stores/settingsStore.ts:5-25` — `SettingsState` has **only** `displayMode: 'grid' | 'visual'`, `language`, `touchMode`, `fullscreen`, `cartPosition`, `inactivityTimeout`, `lockAfterSale`. **No `density`, no `theme`, no `accent`, no `corner`.** (`grep density` over `apps/pos/src` returns nothing.)
- `getColumns` is `getColumns(displayMode: DisplayMode): number` (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:60`) — single arg, no density/width param (width is read internally via `window.innerWidth`).
- `CARD_MIN_H_*` has only two values keyed by displayMode: `CARD_MIN_H_GRID=140` / `CARD_MIN_H_VISUAL=220` (`apps/pos/src/components/molecules/ProductCard/cardSizing.ts:12,17`) — not the visual/compact × comfortable/dense matrix the spec wants.

The Appearance settings live on `feat/pos-caisse-redesign` (no remote ref in this worktree; `git merge-base HEAD origin/feat/pos-caisse-redesign` → none). The §5 preamble (line 170) honestly says the UI "rebases onto the redesign branch," but §5.2 then states density as a **shipped fact** — misleading. **Split the §5.2 work:** the density threading + `CARD_MIN_H` matrix are **blocked until the redesign branch lands**; only the displayMode dual-source reconcile is buildable now (next finding).

### Verified TRUE — §5.2 displayMode dual-source bug is real and buildable now
`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:93` — `const [displayMode, setDisplayMode] = useState<DisplayMode>(getStoredDisplayMode)` seeds from `localStorage['pos-display-mode']` (`:24, :34, :109`) and the component **never reads `useSettingsStore`**, while `settingsStore.displayMode` (`settingsStore.ts:6,35`) sits ignored. The dual-source reconcile (make the grid read `settingsStore.displayMode`) is implementable on this branch today. Note this is the **live** grid (`HomePage.tsx:50` imports `@/components/organisms/ProductGrid`); `apps/pos/src/components/pos/ProductGrid.tsx` is dead code — target the organisms version.

### [NIT] D2-N2. Mode naming + column-count matrix conflict with current code — risks a spurious third mode
The codebase's `displayMode` values are **`'grid' | 'visual'`** (`settingsStore.ts:6`). §5.1 line 184 and §5.2 line 186 say **"compact"** and "visual". §5.2's matrix (visual+comfortable=5, visual+dense=6, compact+comfortable=4, compact+dense=5) also **inverts** the current counts: `getColumns` returns grid=5 / visual=4 at ≥1024px (`ProductGrid.tsx:65-77`). If "compact" is a new third value rather than an alias of `'grid'`, the implementer will fork the enum. **Fix:** pin "compact" == existing `'grid'` (or rename the enum deliberately in one place) and reconcile the column-count matrix against `getColumns`'s current return values before coding.

### [NIT] D2-N3. §5.0 reuse atoms `ProductThumb`/`StockBadge`/`Pill`/`Tabs` do not exist on this branch — §5.1/§5.3 blocked until redesign lands; `/customers` route absent too
**Code evidence:** `apps/pos/src/components/ui/` contains only `Badge.tsx`, `Button.tsx`, `IconButton.tsx`, `SegmentedControl.tsx`, `StatusPill.tsx`, `index.ts` (+ `__tests__`). **Missing:** `ProductThumb`, `StockBadge`, `Pill`, `Tabs`. Note `StatusPill` ≠ the cited `Pill` (category toggle / removable chips) — do not assume they are the same atom.
- §5.1 `ProductCard` restyle (needs `ProductThumb` + `StockBadge`) and §5.3 detail **Tabs** + filter **Pills** are **blocked** until the redesign branch lands. `SegmentedControl` (view toggle) and `Badge` exist now.
- `ezTap` keyframe: correctly noted absent (§5.1 line 182) — `grep ezTap` over `apps/pos/src` returns nothing. Accurate.
- `/customers` route + nav item: **not on this branch.** `AppShell.tsx:146-150` routes are `/`, `/settings`, `/sales`, `/reports/z` only — no `/customers`. The spec (§5.0 line 174, §5.4) correctly attributes the placeholder route/nav item to the redesign branch ("build it out in place"), so this is accurate, but it confirms §5.4 is **blocked** until rebase.

**Net for DELTA 2:** buildable now = the `settingsStore.displayMode` dual-source reconcile (and any data-layer wiring from §1-4). Blocked until `feat/pos-caisse-redesign` lands = density threading + `CARD_MIN_H` matrix (D2-N1), `ProductCard`/detail-tab/filter-chip restyle and `/customers` build-out (D2-N3). The spec's §5 preamble flags the dependency, but §5.0/§5.2 overstate what is already shipped — tighten those two sentences so the implementer does not start density work expecting `settingsStore` to already carry it.

---

## Verdict
The vertical-gating delta is sound for products (the gated `/products` load and the shared POS endpoint both inherit it) but **the customer-sync side does not hold**: `PosCustomerMirrorResource::toArray` is unconditional and `PosCustomerSyncController` never resolves the vertical, so the "parapharmacy-only" skin fields will leak to every vertical unless the spec explicitly mandates a controller-resolved `isParapharmacy` flag + a `mergeWhen` guard in the resource (D1-C1). Two Important issues remain: the "rides `ParapharmacyProductMetadataData` automatically" claim is structurally inconsistent with relations declared on `Product` (D1-I1), and "unbundle to paid = config-only" over-promises because vertical-gated data still ships to unpaid tenants (D1-I2). DELTA 2 is mostly accurate but blocked on the redesign branch; only the `displayMode` dual-source reconcile is buildable now, and §5.2's "density already shipped" is false on this branch (D2-N1). **Amend D1-C1 (mandatory), D1-I1, and D1-I2 before build; tighten the §5.0/§5.2 "already shipped" wording.**
