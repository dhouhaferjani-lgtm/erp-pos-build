# Product Metadata Vertical-Gating — Backend Audit

**Scope:** Product module backend (FormRequests, ProductController, ProductData DTO, Product domain, routes). Read-only.
**Date:** 2026-06-15
**Bug report:** A team member testing as a RESTAURANT vertical saw product fields relevant only to the PARAPHARMACY vertical.

---

## Summary

**Is the backend a root cause of the restaurant leak? PARTLY — but it is almost certainly NOT the source of what the tester saw.**

The backend's **persistence** of vertical-specific metadata IS correctly gated by the company vertical, and the **read/serialization** path only returns metadata for the matching vertical. A restaurant company:

- **Cannot persist** `parapharmacy_metadata` — `ProductController::store/update` only writes it when `vertical === Vertical::Parapharmacy` (store line 322, update line 448). The same guard exists for automotive (lines 327, 456).
- **Cannot read back** `parapharmacy_metadata` — `ProductData::fromModel` only emits the metadata object when the `parapharmacyMetadata` relation is **loaded** (ProductData.php:82-84), and the controller only loads that relation for parapharmacy/automotive tenants (index 69-80, show 251-266, store 355-371, update 496-512). For a restaurant the relation is never loaded, so the DTO field stays `null` (ProductData.php:44 default).

So the API never hands a restaurant client a populated **or empty-scaffold** `parapharmacy_metadata` object. The serializer emits `null`, not `{}`. **The most likely culprit is the frontend form rendering parapharmacy fields based on something other than the API response (e.g. a hardcoded form section, a vertical flag the FE computes itself, or a stale/cached vertical).** That is the frontend agent's domain — see hand-off.

**However**, there IS a real backend defect: **the FormRequests accept and validate `parapharmacy_metadata.*` and `automotive_metadata.*` from ANY vertical with no guard** (CreateProductRequest.php:75-138, UpdateProductRequest.php:75-138). A restaurant POST containing `parapharmacy_metadata` passes validation; the data is then silently dropped by the controller's vertical check. This is a silent-accept-then-discard anti-pattern (validation passes, write is a no-op) — confusing and a latent leak risk if any future code path persists `$validated['parapharmacy_metadata']` without re-checking the vertical. Rated HIGH per the audit brief.

A second, distinct finding: **`requires_batch_tracking` defaults to TRUE for restaurant and coffee_shop** (config/verticals.php:77, 106) and is auto-applied to every physical product at create time (Product.php:175-178). Batch/expiry is a pharmacy-style concept; forcing it on restaurants is at least surprising, but it is **config-intended** (VerticalConfigServiceTest asserts it) and does NOT add required product **fields** — it only sets a boolean flag downstream. Rated LOW/INFO.

---

## Reproduction reasoning (concrete)

**What a restaurant company gets back from the API:**
- `GET /products` and `GET /products/{id}`: `ProductData` with `parapharmacy_metadata: null` and `automotive_metadata: null` — the relations are never eager-loaded for a restaurant (controller index 69-80, show 251-266). No empty scaffold is sent.
- `requires_batch_tracking: true` on every physical product (DTO field, ProductData.php:36/66), because the restaurant vertical default is `true`.

**What a restaurant company can submit:**
- A `POST /products` body with a full `parapharmacy_metadata` block **passes validation** (CreateProductRequest.php:76-96 — `sometimes`/`nullable`/`required_with`, no vertical guard). It is then **discarded** by the controller (store line 322 `&& $company->tenant->vertical === Vertical::Parapharmacy`). No 422, no persistence — a silent no-op.

**Conclusion:** The restaurant client cannot obtain populated parapharmacy data from the backend, so the rendered fields the tester saw were not driven by the product read API. The backend's contribution is the silent-accept validation gap and the (intended) batch-tracking default, not a data leak through the read path.

---

## Findings

### [HIGH] FormRequests accept vertical-specific metadata regardless of company vertical

**Evidence:**
- `CreateProductRequest::rules()` validates `parapharmacy_metadata.*` (lines 76-96) and `automotive_metadata.*` (lines 99-138) with only `sometimes`/`nullable`/`required_with` rules. No reference to the company vertical, the Parapharmacy module, or `CompanyContext`.
- `UpdateProductRequest::rules()` — identical, lines 76-138.
- The request `use` list imports `ParapharmacyCategory`, `DosageForm`, `AgeRestriction` enums (lines 8-16) but never the `Vertical` enum or `CompanyContext`.
- The only `Vertical` enforcement happens later, in the controller (store 322/327, update 448/456), which silently drops mismatched metadata.

**Impact:** A restaurant (or any non-parapharmacy) caller can POST/PATCH `parapharmacy_metadata` and receive a 2xx with the data silently dropped. This is (a) a confusing contract — clients get no signal the data was ignored; (b) a latent leak: any future refactor that persists `$validated['parapharmacy_metadata']` before re-checking the vertical (e.g. a new bulk-import service, a queued job, or a careless `Product::create([...$validated])`) would write parapharmacy data onto a restaurant product. The validation layer is where the vertical contract should be enforced, not buried in a controller `if`.

**Recommendation:** Gate the metadata rules on the resolved company vertical. Either (a) inject `CompanyContext` into the FormRequest and return a 422 when `parapharmacy_metadata` is present but `vertical !== Parapharmacy` (and symmetrically for automotive), or (b) add a `prepareForValidation`/`withValidator` hook that rejects the foreign-vertical key. Prefer an explicit `prohibited_unless`-style rule keyed off the vertical so the client gets a clear error instead of a silent drop.

---

### [MEDIUM] Silent-discard of mismatched metadata in controller masks the validation gap

**Evidence:** `ProductController::store` lines 290-302 extract `parapharmacy_metadata`/`automotive_metadata` out of `$validated`, then write them only if the vertical matches (lines 322, 327). When it does not match, the extracted array is simply discarded — no error, no log, no warning. Same in `update` (lines 417-490).

**Impact:** Combined with the HIGH finding, the API silently accepts and ignores cross-vertical metadata. A client (or the frontend) submitting parapharmacy fields on a restaurant product gets a success response and no indication the fields were dropped — which could itself contribute to confusing UX or hide a frontend bug that is submitting the wrong shape.

**Recommendation:** Once validation is gated (HIGH), this discard branch becomes unreachable for the mismatch case. Until then, consider at least logging when extracted metadata is dropped due to a vertical mismatch, to surface mis-submitting clients.

---

### [LOW] `requires_batch_tracking` defaults to TRUE for restaurant/coffee_shop and auto-applies to physical products

**Evidence:**
- `config/verticals.php`: `restaurant.product_defaults.requires_batch_tracking => true` (line 77), `coffee_shop` => true (line 106), `parapharmacy`/`pharmacy` => true (lines 334, 60); `retail`/`mechanic`/`fashion`/all otospex verticals => false.
- `Product::booted()` `creating` hook (Product.php:154-179): if the attribute was not explicitly set and the product `is_physical`, it reads `config("verticals.{vertical}.product_defaults.requires_batch_tracking", false)` (lines 175-178) and applies it. Non-physical products always get `false` (lines 169-172).
- `ProductData` exposes `requires_batch_tracking` to every client (ProductData.php:36, 66) regardless of vertical.
- Downstream this only toggles batch/FEFO behavior (FEFOInventoryService.php:321, GoodsReceiptService.php:149/174, StockTransferService.php:436, SalesOrder converters) — it does NOT add required product input fields.

**Impact:** A restaurant's physical products are flagged for batch/expiry tracking by default — a pharmacy-style concept. This is the only place a "pharmacy-flavored" property reaches a restaurant product, so it is worth flagging in the context of the reported bug. But it is config-intended (asserted by `VerticalConfigServiceTest::test_get_product_defaults_marks_batch_heavy_verticals_for_batch_tracking`), perishable-goods tracking is legitimately relevant to restaurants, and it imposes no required form fields. Not a security/leak issue.

**Recommendation:** No code change required for the reported bug. If restaurants should not see batch/expiry UI, that is a frontend gating decision keyed off the vertical/module, not a backend defect. Confirm with product owner whether restaurant batch-tracking is desired; if not, flip the config default — single-line change in `config/verticals.php`.

---

### [INFO] Read path is correctly vertical-gated — no empty parapharmacy scaffold is emitted

**Evidence:**
- `ProductController::index` builds the eager-load set conditionally: parapharmacy relations only for `Vertical::Parapharmacy` (lines 69-74), automotive only for `isAutomotive()` (lines 76-80). Base load is `['category', 'primaryImage', 'unitOfMeasure']` (line 68).
- `show` (251-266), `store` (355-371), `update` (496-512) all apply the same conditional `load()`.
- `ProductData::fromModel` emits `parapharmacy_metadata` only when `relationLoaded('parapharmacyMetadata') && parapharmacyMetadata !== null` (ProductData.php:82-84); otherwise the constructor default `null` (line 44) stands. Same for automotive (lines 85-87).

**Impact:** A restaurant client receives `parapharmacy_metadata: null` — not an empty object `{}`. The backend read path is NOT the source of rendered parapharmacy fields. This effectively exonerates the product read API and points the investigation at the frontend.

---

### [INFO] Routes: product CRUD gated on `module:Inventory`; parapharmacy master-data gated on `module:Parapharmacy`

**Evidence:** `routes.php`:
- Product CRUD (`/products`) is inside the `module:Inventory` group (line 42) with per-action `can:` permissions (lines 44-66). It is NOT gated on a Catalog-vs-Parapharmacy distinction — any vertical with the Inventory module can create/update products (correct; products are universal).
- The parapharmacy **master-data** endpoints (`parapharmacy/ingredients`, `/certifications`, `/health-claims`, `/key-components`) are additionally wrapped in `Route::middleware('module:Parapharmacy')` (line 71). A restaurant (no Parapharmacy module) is blocked from those endpoints at the middleware layer.

**Impact:** The product-create route itself is intentionally not parapharmacy-gated — vertical enforcement for the embedded metadata is (only) the controller's `if`. The master-data sub-resources are properly module-gated. The restaurant vertical's default modules (config/verticals.php:79-91) do not include `Parapharmacy`, so the master-data routes 403 for restaurants.

---

### [INFO] MigrateParapharmacyDataCommand / SmartPrompts — out of leak path

**Evidence:** `app/Console/Commands/MigrateParapharmacyDataCommand.php` exists (console-only, not request-reachable). A grep for `parapharmacy_metadata|parapharmacyMetadata|ParapharmacyCategory` under `app/Modules/SmartPrompts` returned **no matches** — SmartPrompts does not reference parapharmacy product metadata. Neither is on the HTTP product create/read path; no vertical-leak contribution.

---

## Cross-cutting / hand-off

**To the FRONTEND agent (highest priority):** The backend read API does NOT emit `parapharmacy_metadata` for a restaurant — it serializes `null` (ProductData.php:44/82-84), never an empty scaffold. Therefore the parapharmacy fields the tester saw are almost certainly rendered by the **frontend product form deciding to show those sections independent of the API payload** — e.g. a hardcoded form section, a client-side vertical/feature flag that is wrong or stale, a cached vertical, or a form that always renders all metadata sections. Investigate the product create/edit form's section-visibility logic and confirm it keys off the company vertical / enabled modules (Parapharmacy) and off the actual API `parapharmacy_metadata`/`automotive_metadata` presence — not a default-on render.

**To the orchestrator:** If the FE form gates correctly off vertical, the next suspect is a stale/mis-resolved vertical on the tested account (was the test company actually provisioned as `restaurant`, or is its `tenant.vertical` something else?). The backend gating is keyed off `company->tenant->vertical`; if that value is wrong for the test tenant, both the leak and the fix live upstream of the Product module.

**Backend follow-up (this domain):** The HIGH finding (un-gated FormRequest validation) should be fixed regardless of the FE outcome — it is a latent contract/leak defect. It does not require touching the read path.

---

## Files reviewed

- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php`
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php`
- `apps/api/app/Modules/Product/routes.php`
- `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`
- `apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php`
- `apps/api/app/Modules/Product/Domain/Product.php` (booted/creating hook + fillable/casts)
- `apps/api/config/verticals.php`
- `apps/api/app/Enums/Vertical.php` (Restaurant/Parapharmacy cases, isAutomotive)
- `apps/api/app/Services/VerticalConfigService.php` (getProductDefaults — grep)
- `apps/api/app/Console/Commands/MigrateParapharmacyDataCommand.php` (existence/scope only)
- SmartPrompts module (grep — no parapharmacy metadata references)
- grep sweep of `requires_batch_tracking` consumers (FEFO, GoodsReceipt, StockTransfer, SalesOrder converters, seeders)
