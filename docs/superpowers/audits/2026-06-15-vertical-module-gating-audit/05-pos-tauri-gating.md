# POS (Tauri) Vertical / Module Gating Audit

> Scope: `apps/pos` only (Tauri offline POS). Web admin and backend are other agents' domains.
> Date: 2026-06-15. Auditor: read-only. Part of vertical/module-gating audit triggered by a
> restaurant tester seeing parapharmacy-only fields in the (web) product UI.

## Summary

**The Tauri POS does NOT leak vertical-specific behavior.** Posture is good. The app is
genuinely vertical/module-aware and gates the two pieces of vertical UI it has:

- **F&B (Menu) chrome** — table selector, consumption-mode (SUR_PLACE / À_EMPORTER) toggle,
  modifier picker, and the Menu-vs-flat catalog path — is gated on the `Menu` module via a
  single `hasModule(config, 'Menu')` helper. A restaurant tenant gets it; a parapharmacy or
  automotive tenant does not.
- **Parapharmacy Smart Prompts** (the `skin_type` context field + recommendation panel) is gated
  on `vertical === 'parapharmacy'` **and** an explicit `smart_prompts_enabled` flag. A restaurant
  tenant never reaches the parapharmacy branch.

Critically for the triggering bug: **the POS product surfaces carry no vertical metadata at all.**
The `POSProduct` type (`apps/pos/src/types/product.ts`) is a deliberately minimal wire shape — no
DLU/expiry, no batch/lot, no posology, no skin-type, no vehicle/OE/VIN/TecDoc fields. There is
**no batch/expiry capture at sale** anywhere in the POS, and the `ProductDetailDrawer` shows only
generic fields (name, price, SKU, barcode, category, stock, tax). So the web "parapharmacy fields
on a restaurant product" class of leak structurally cannot occur in the POS — the data isn't even
in the catalog payload the POS receives.

Two minor risks worth noting (LOW/MEDIUM, not leaks): the POS hardcodes its own module/vertical
strings rather than importing the backend enums (drift risk), and it gates F&B chrome on the
`Menu` module while the backend also has a separate `Tables` module — table UI is keyed off
`Menu`, not `Tables`, so a Menu-without-Tables config (if it ever exists) would still show the
table selector (mitigated today only by the server returning empty floors).

## How POS learns vertical/modules

The POS gets vertical + enabled-modules from a dedicated company-config endpoint, caches it in the
product store, and persists it per-company for offline use.

- **Type** — `apps/pos/src/types/companyConfig.ts:8-14`:
  ```ts
  export interface CompanyConfig {
    all_enabled_modules: string[];
    receipt_visibility?: ReceiptVisibility;
    vertical?: string;
    smart_prompts_enabled?: boolean;
    smart_prompts_variant?: 'inline' | 'toast' | 'both' | 'off';
  }
  ```
- **Fetch** — `apps/pos/src/api/productApi.ts:42-44` → `apiGet<CompanyConfig>('/company/config')`.
- **Store** — held in `useProductStore.companyConfig` (`apps/pos/src/stores/productStore.ts:33,59`),
  loaded lazily during `fetchProducts` (`productStore.ts:192-200`) and refreshed via
  `authStore.refreshCompanyConfig` (`apps/pos/src/stores/authStore.ts:418-426`), which also runs
  after a full sync (`syncService.ts:1872`).
- **Offline cache** — persisted per company at `companyConfigCacheKey(companyId)` and rehydrated
  when the network is down (`apps/pos/src/lib/migration/c2BareCartLineDump.ts:166-185`).
- **The single gate helper** — `hasModule(config, moduleName)`
  (`apps/pos/src/stores/productStore.ts:110-112`): `config?.all_enabled_modules?.includes(moduleName) ?? false`.

Note: separately from `all_enabled_modules`, the **stock policy** comes from the *terminal* payload
(`terminal.pos_stock_policy`), not company config — see stock section below.

## POS vertical/module gating inventory

| UI / behavior | Gate | File:line | Correct? |
|---|---|---|---|
| Catalog source (Menu hierarchy vs flat `/products`) | `hasModule(config,'Menu')` | productStore.ts:212; syncService.ts:672 | Yes |
| Menu reconcile / wipe guard (don't wipe retail catalog) | `hasModule(config,'Menu')` | syncService.ts:1464-1475 | Yes |
| Location-stock display map (skip for Menu + unknown config) | `config===null \|\| hasModule(config,'Menu')` | productStore.ts:448 | Yes |
| Stock gate bypass for made-to-order (always sellable) | `hasModule(config,'Menu')` → PASS | lib/stock/stockGate.ts:58-60 | Yes |
| `isFnB` flag (drives all F&B chrome below) | `hasModule(companyConfig,'Menu')` | pages/HomePage.tsx:104 | Yes |
| Consumption-mode toggle (SUR_PLACE / À_EMPORTER) | `isFnB ? ... : undefined` | HomePage.tsx:1445, 1091-1092, 1143-1144 | Yes |
| Table selector UI | `isFnB && consumptionMode==='SUR_PLACE'` | HomePage.tsx:1428-1430 | Partly — keyed on Menu, not Tables module (LOW) |
| Modifier selection modal | rendered only when product has `modifier_groups` | ModifierSelectionModal.tsx:46; product.ts:44 | Yes (data-driven; empty for non-menu products) |
| Smart Prompts panel + `skin_type` field | `vertical==='parapharmacy' && smart_prompts_enabled` | stores/smartPromptsStore.ts:8,63-78 | Yes |
| `PosStockPolicy` decrement behavior | `terminal.pos_stock_policy` (off/warn/block), Menu→off | stockGate.ts:53-96 | Yes |
| ProductDetailDrawer metadata | n/a — only generic fields rendered | components/pos/ProductDetailDrawer.tsx:84-128 | Yes (no vertical metadata exists) |
| Tables/floors sync pull | **ungated** (server returns empty for non-FnB) | syncService.ts:1848, 1368-1404 | Server-trust, not client-gated (LOW) |

## Findings

### [INFO] POS catalog payload carries no vertical metadata — the web leak class cannot occur here
**Evidence:** `apps/pos/src/types/product.ts:33-76` — `POSProduct` has only `id, name, sku, barcode,
sale_price, stock_quantity, category, image_url, tax_rate, sellableType, modifier_groups,
has_variants, sellable_id, menu_category_id, is_physical`. No expiry/DLU, batch/lot, posology,
skin-type, or vehicle/OE/VIN fields. `ProductDetailDrawer.tsx:84-128` renders only SKU/barcode/
category/stock/tax. A repo-wide grep for `batch|expiry|lot_number|dlu|peremption|vehicle|vin|
oe_number|tecdoc|parapharmac|posolog` in `src/**` returns no product-metadata hits (only unrelated
DB-batching and voucher-expiry).
**Impact:** Positive finding — the restaurant-sees-parapharmacy-fields bug is structurally absent
in the POS because the metadata never reaches it.
**Recommendation:** None. Keep the `POSProduct` wire shape minimal; if vertical metadata is ever
added to the POS catalog, gate its display the way Smart Prompts is gated.

### [MEDIUM] POS hardcodes module/vertical strings instead of importing backend enums (drift risk)
**Evidence:** Module gating uses the bare string `'Menu'` (productStore.ts:212, syncService.ts:672,
stockGate.ts:58, HomePage.tsx:104, c2BareCartLineDump.ts:171). Vertical gating uses
`const SUPPORTED_VERTICALS = ['parapharmacy']` (smartPromptsStore.ts:8). The backend canonical
values live in `apps/api/app/Enums/ModuleName.php` (`case Menu = 'Menu'`, also `Tables`,
`BatchExpiry`, `Parapharmacy`, …) and `apps/api/app/Enums/Vertical.php`
(`case Parapharmacy = 'parapharmacy'`, `Restaurant = 'restaurant'`, `CoffeeShop = 'coffee_shop'`, …).
The strings currently MATCH (`'Menu'` ↔ `ModuleName::Menu`, `'parapharmacy'` ↔
`Vertical::Parapharmacy`), so there is no live bug. But the POS has no shared/generated constant and
no drift guard — a backend rename or casing change would silently turn off F&B chrome or Smart
Prompts with no compile error.
**Impact:** Future silent breakage if backend enum string values change. Cross-app contract is
implicit.
**Recommendation:** Either generate a small POS-side constant from the backend enums (similar to
the typescript:transform flow used elsewhere) or add a contract test asserting the POS literals
equal the backend enum `->value`s. Backend/types-generation is another agent's domain — flagged as
hand-off.

### [LOW] F&B table UI is gated on the `Menu` module, not the dedicated `Tables` module
**Evidence:** `pages/HomePage.tsx:104` `isFnB = hasModule(companyConfig,'Menu')`; the TableSelector
renders under `isFnB && consumptionMode==='SUR_PLACE'` (HomePage.tsx:1428-1430). The backend has a
*separate* `case Tables = 'Tables'` module (`ModuleName.php:29`). The POS never checks `'Tables'`
(grep of `src/**` shows the only gated module name is `'Menu'`). So a tenant with `Menu` enabled
but `Tables` NOT enabled would still see the table selector. Today this is masked because
`pullTables` (`syncService.ts:1368`) hits `/pos/floors`, and a non-Tables tenant gets empty floors,
making `TableSelector` return `null` (`components/atoms/TableSelector.tsx:60-63`).
**Impact:** Display correctness depends on the server returning empty floors rather than on a client
module gate. If a Menu+no-Tables config ever ships floors, the POS would show table UI it shouldn't.
**Recommendation:** Gate the table selector on `hasModule(config,'Tables')` (in addition to `Menu`
for consumption mode), so the client doesn't rely solely on server emptiness.

### [LOW] `pullTables` runs unconditionally in the sync loop (no module gate)
**Evidence:** `syncService.ts:1848` calls `pullTables(db)` on every `runFullSync`, with no
`hasModule(config,'Menu'/'Tables')` guard (contrast `pullProducts`/`pullActiveMenu` which gate on
`Menu` — syncService.ts:652-680, 1464-1475). For a parapharmacy/automotive tenant this issues an
extra `/pos/floors` request each cycle that returns empty.
**Impact:** Minor wasted request per sync tick for non-FnB tenants; no correctness issue (empty
result is cached and ignored). No data leak.
**Recommendation:** Optionally skip `pullTables` when `!hasModule(config,'Tables')` (or `'Menu'`),
consistent with the other catalog pulls.

### [INFO] Stock policy is fail-safe and Menu-aware; no pharmacy concepts surface at sale
**Evidence:** `lib/stock/stockGate.ts:11-16,53-96` — policy source is `terminal.pos_stock_policy`
(`off`/`warn`/`block`), missing field defaults to `'block'` (fail-safe for retail), and Menu
tenants are forced to `'off'` regardless (stockGate.ts:58-60) so made-to-order sales never freeze.
There is no batch/lot/expiry selection at the point of sale for any vertical — a restaurant cashier
sees no pharmacy/parapharmacy capture UI.
**Impact:** Correct per-vertical behavior; no leak.
**Recommendation:** None.

### [INFO] Missing-config offline fallback fails toward safety, not toward a default vertical
**Evidence:** When `companyConfig` is `null` (boot / failed refresh), the POS does **not** assume a
vertical. Catalog routing *defers* the tick rather than guessing (syncService.ts:657-679,
"deferring /products until next tick"); the location-stock display map is left untouched
(productStore.ts:448); the stock gate, lacking a `Menu` signal, falls through to the terminal
policy which defaults to `'block'` (fail-safe). `isFnB` resolves to `false` when config is null
(`hasModule(null,...) === false`, productStore.ts:111), so F&B chrome is hidden — a conservative
default (no extra vertical UI) rather than a leak.
**Impact:** No incorrect-vertical UI is shown during the unknown-config window; worst case is
deferred catalog/stock until config loads.
**Recommendation:** None. This is the desired posture.

## Cross-cutting / hand-off

- **Backend (other agent):** confirm `/company/config` returns `all_enabled_modules` using the exact
  `ModuleName` enum `->value` strings and `vertical` using the `Vertical` enum `->value`. The POS
  literal `'Menu'` and `'parapharmacy'` depend on these. Also confirm `/pos/floors` returns empty
  for tenants without the `Tables` module (the POS currently relies on this for table-UI
  suppression).
- **Types generation (other agent):** POS hardcodes module/vertical strings (MEDIUM finding). If a
  generated-constants or contract-test mechanism exists for cross-app enum parity, extend it to the
  POS.
- **Web admin (other agent):** the triggering bug (parapharmacy fields on a restaurant product) is a
  web product-UI issue; the POS does not share that code path or that data shape.

## Files reviewed

- `apps/pos/src/types/companyConfig.ts`
- `apps/pos/src/types/product.ts`
- `apps/pos/src/api/productApi.ts`
- `apps/pos/src/stores/productStore.ts` (hasModule, fetchProducts, refreshLocationStock)
- `apps/pos/src/stores/authStore.ts` (refreshCompanyConfig)
- `apps/pos/src/stores/smartPromptsStore.ts`
- `apps/pos/src/lib/stock/stockGate.ts`
- `apps/pos/src/lib/sync/syncService.ts` (resolveCatalogTenantGate, pullProducts, pullTables, pullActiveMenu, runFullSync)
- `apps/pos/src/lib/migration/c2BareCartLineDump.ts`
- `apps/pos/src/pages/HomePage.tsx` (isFnB and consumers)
- `apps/pos/src/components/atoms/TableSelector.tsx`
- `apps/pos/src/components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx`
- `apps/pos/src/components/pos/ProductDetailDrawer.tsx` (+ organisms/ProductDetailDrawer/index.ts re-export)
- `apps/api/app/Enums/ModuleName.php`, `apps/api/app/Enums/Vertical.php` (backend enum cross-check only)
