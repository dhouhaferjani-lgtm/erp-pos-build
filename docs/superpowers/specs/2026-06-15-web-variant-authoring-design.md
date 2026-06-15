# Spec B — Web-Admin Variant Authoring & Onboarding Polish

**Date:** 2026-06-15
**Branch:** `feat/web-variant-authoring` (off `dev` @ `c8d35b5fd`)
**Worktree:** `apps/erp.web-variant-authoring`
**Program:** Variant program, Spec B of 3 (A = POS offline variants, DONE-not-merged; C = label/QR printing, later)
**Surface:** `apps/api` (Catalog + Tenant modules) **and** `apps/web` (catalog feature) — see §0.

---

## 0. Scope correction (read first)

The original brief assumed Spec B was "apps/web only." Code inspection shows **four of five features require backend work** — the frontend cannot deliver them alone:

| Feature | Backend change required because… |
|---|---|
| Per-product value subset + idempotent generate | `ProductVariantService::generateMatrix(productId, attributeIds)` takes only attribute IDs and uses **all** values of each; it re-`createVariant`s **every** combo, which collides with `UNIQUE(product_id, variant_code)` on regenerate. |
| Orphan "not in current selection" badge | `ProductVariantData` DTO does **not** expose the attribute-value junction; the frontend has no way to know which option values a variant maps to. |
| Barcode uniqueness "at save" | Enforced only by a DB partial-unique index today; a duplicate surfaces as a raw 500, not a field-scoped 422. |
| Onboarding step | Steps are a server-side enum (`OnboardingStep`) + check service. |
| Combinatorial-explosion guard | Live count + soft warn is frontend-only; the **hard cap** must also be enforced server-side so the API can't be handed a runaway cartesian product. |

Forking off `dev` remains correct: Spec B's touched Catalog files (`generateMatrix`, `ProductVariantData`, the variant FormRequests, `GenerateMatrixRequest`) are disjoint from Spec A's POS-feed files (`PosVariantFeedService`, `ProductData.has_variants`), so the two branches do not entangle. Expected light merge contact only in `Catalog/Presentation/routes.php` if both add routes.

---

## 1. Current state (grounded in code)

### Backend data model — already rich
- **`product_attributes`** (`database/migrations/tenant/2026_06_02_100001_*`): tenant-scoped (no `product_id`), `code`, `name`, `data_type` (text/numeric/boolean/date/selection/color/image), `is_variant_axis`, `display_order`, `is_active`, soft-deletes. `UNIQUE(tenant_id, code)`. **Global & reusable per tenant.**
- **`product_attribute_values`** (`…100002_*`): `attribute_id` (cascade), `code`, `label`, `hex_color` (PG CHECK `^#[0-9A-Fa-f]{6}$`), `image_url`, `display_order`. `UNIQUE(attribute_id, code)`.
- **`product_variants`** (`…100003_*`): `product_id` (cascade), `variant_code`, `sku`, `barcode` (nullable), `name_suffix`, `is_default`, `is_active`, `display_order`, `price_override`, `cost_override` (advisory only, spec §6.7), `image_url`, soft-deletes. Constraints:
  - `UNIQUE(product_id, variant_code)` — **plain unique, NO `deleted_at` predicate** → a soft-deleted row still blocks re-creating the same code.
  - `UNIQUE(tenant_id, sku) WHERE deleted_at IS NULL`
  - `UNIQUE(tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL`
  - `UNIQUE(product_id) WHERE is_default = true AND deleted_at IS NULL`
- **`product_variant_attribute_values`** (`…100004_*`): junction `variant_id` (cascade), `attribute_id`/`attribute_value_id` (restrict). `UNIQUE(variant_id, attribute_id)`.
- **`ProductVariantMatrixGenerator::cartesian(array $axes, array $excluded = [])`** — pure, no DB. **Already accepts `$excluded`** (combos to skip). The service never passes it.

### Backend service / API
- `ProductVariantService::generateMatrix(string $productId, array $attributeIds)`: loads **all** values per attribute, builds axes, `cartesian()` with **no exclusions**, wraps creation in one outer transaction, sets the first combo as default only when the product has no existing default. Re-calling it with overlapping axes throws on the `variant_code` unique constraint and rolls back.
- Routes (`Catalog/Presentation/routes.php`): `POST products/{productId}/variants/generate-matrix` → `ProductVariantController@generateMatrix`, body `{ attribute_ids: string[] }` (`GenerateMatrixRequest`); `POST products/{productId}/variants` (`CreateVariantRequest`); `PATCH product-variants/{id}` (`UpdateVariantRequest`); `DELETE product-variants/{id}`; attribute + value CRUD under `product-attributes`.
- `CreateVariantRequest` / `UpdateVariantRequest`: `barcode => ['sometimes','nullable','string','max:255']` — **no uniqueness rule**, and `max:255` mismatches DB `VARCHAR(100)`.
- `ProductVariantData` DTO: id, tenant_id, company_id, product_id, variant_code, sku, barcode, name_suffix, is_default, is_active, display_order, price_override, cost_override, image_url — **no attribute_values**.

### Onboarding (server-driven, clean)
- `OnboardingStep` enum (`Tenant/Domain/Enums`): CompanyInfo, TaxConfig, PaymentMethods, PaymentRepositories, PosTerminal, FirstProduct — each with `isRequired()`, `label()`, `settingsPath()`.
- `OnboardingChecklistService::getStatus(companyId)`: one `match` arm per step → `{ step, label, completed, required, settings_path }`. Frontend (`SetupChecklist.tsx`, `onboardingApi.ts`) is fully data-driven; translation key = `onboarding.steps.${step}`. **Adding a step is enum-case + check-method + i18n key only.**

### Frontend authoring UI
- `ProductVariantMatrixEditor.tsx` (`features/catalog/components`): axis checkboxes over `attributes.filter(is_variant_axis)`; "Generate/Regenerate" calls `useGenerateMatrix(productId)` with selected axis IDs; editable table (SKU/barcode/price/cost/active) with per-row save + delete. No value selection, no combo count, no warnings, no orphan concept, no delete confirm, barcode errors surface only via generic toast.
- Embedded in `features/inventory/ProductForm.tsx` (edit mode), behind a "variants" toggle that auto-opens when variants exist.
- Hooks `features/catalog/hooks/useVariants.ts`; API `features/catalog/api/variantApi.ts` (`generateVariantMatrix(productId, attributeIds)` posts `{ attribute_ids }`).
- Existing tests: `components/__tests__/ProductVariantMatrixEditor.test.tsx`, `pages/__tests__/AttributeListPage.test.tsx`.

---

## 2. Decisions (locked with owner, 2026-06-15)

1. **Reusable option-set UX = per-product value subset + idempotent additive generate.** Reuse the existing global attributes; **no new tables**. (Saved option-set *templates* explicitly deferred.)
2. **Sync semantics = additive-only, never auto-delete.** Generate creates only missing combos; deselected/orphan variants are kept and flagged, removed only via explicit per-combination delete. (No soft-delete-on-sync.)
3. **Explosion guard = soft warn + hard cap.** Soft visual warning at **≥ 50** combos; **Generate disabled > 200**; cap enforced on **both** client and server (422 server-side).
4. **Barcode enforcement = server 422 + inline frontend field error**, robust to the DB unique-violation race; fix `max` length to 100.
5. **Onboarding step = "Set up product options", optional**, complete when the tenant has **≥ 1 `ProductAttribute` with `is_variant_axis = true`** (non-deleted).

Thresholds 50/200 are defined as named constants (backend `const`, frontend exported const) so they stay in lockstep.

---

## 3. Design

### 3.1 Backend — matrix generation (per-product value subset + idempotent)

**API contract** (`POST products/{productId}/variants/generate-matrix`):

```jsonc
// New preferred shape
{ "axes": [ { "attribute_id": "<uuid>", "value_ids": ["<uuid>", "<uuid>"] }, … ] }
// Back-compat shape (still accepted): value_ids omitted ⇒ all values of that attribute
{ "attribute_ids": ["<uuid>", …] }
```

`GenerateMatrixRequest`:
- Accept either `axes` (preferred) or `attribute_ids` (legacy). Validate UUIDs, non-empty, each `attribute_id` is a variant axis, each `value_id` belongs to its attribute.
- Compute the **selected** combo count = `∏ |value_ids|` (legacy: `∏ |all values|`). Reject with **422** (`combinations` field) when `> MAX_VARIANTS_PER_GENERATE` (**200**). This bounds work *before* any DB write.

`ProductVariantService::generateMatrix(string $productId, array $axes)`:
- Build axes from the **selected** value IDs only (legacy path expands to all values, preserving today's behavior).
- Load existing variants for the product (incl. soft-deleted — the `variant_code` unique has no `deleted_at` predicate). Derive the set of existing `variant_code`s, map back to the combo shape, and pass as `cartesian()`'s **`$excluded`** → only **missing** combos are produced. **No variant is ever deleted.**
- Default-variant rule unchanged (first *ever* variant for a product becomes default; idempotent re-runs never add a second default).
- Return a summary: `{ created: ProductVariantData[], created_count, skipped_count }`. (Controller wraps in `{ data: … }`.)

Idempotency guarantee: regenerating with an unchanged or subset selection creates 0 and throws nothing. Adding a value creates exactly the new combos.

### 3.2 Backend — variant DTO exposes junction

`ProductVariantData` gains `attribute_values: list<{ attribute_id: string, attribute_value_id: string }>`, sourced from the `product_variant_attribute_values` junction (eager-loaded in `index`/`store`/`update`/`generateMatrix` reads to avoid N+1). Drives the frontend orphan badge. Regenerate TS types via `php artisan typescript:transform`.

### 3.3 Backend — barcode uniqueness → 422

- Add a tenant-scoped uniqueness rule to `CreateVariantRequest` and `UpdateVariantRequest`: barcode unique among non-deleted variants, **excluding the current variant** (update) — matching the DB partial index. On update, the variant id comes from the route.
- Service layer additionally **catches the PG unique-violation** (`23505` on `product_variants_tenant_barcode_unique`) and rethrows a domain `DuplicateBarcodeException` → mapped to **422** `{ errors: { barcode: ["<message naming the conflicting variant>"] } }`. This closes the validate-then-write race.
- Tighten `barcode`/`sku` `max:255` → `max:100` to match the column. Non-breaking (stricter).
- Message includes the conflicting variant's `name_suffix` (resolve via tenant-scoped lookup).

### 3.4 Backend — onboarding step

- `OnboardingStep::ProductOptions = 'product_options'`; `isRequired() => false`; `label() => 'Set up product options'`; `settingsPath() => '/catalog/attributes'`.
- `OnboardingChecklistService`: new arm → `checkProductOptions()` returns whether **any** `ProductAttribute` with `is_variant_axis = true` and not soft-deleted exists. Attributes are tenant-scoped (no `company_id`), so the check is a tenant-wide existence query (the per-request connection is already the tenant DB).
- Frontend needs only the `onboarding.steps.product_options` i18n key (en + fr); `SetupChecklist` renders it automatically.

### 3.5 Frontend — `ProductVariantMatrixEditor` rework

- **Axis + value selection:** checking an axis reveals its values as toggle chips (fetched via `getAttributeValues(attributeId)`; all selected by default). Deselecting a chip excludes that value. Color-type values render a swatch from `hex_color`.
- **Live combo count:** `∏ selected value counts`, shown inline. Soft warning styling at **≥ 50**; **Generate disabled with explanatory message > 200** (shared `MAX_VARIANTS_PER_GENERATE` constant).
- **Generate = "Generate / sync matrix":** posts `{ axes }`; on success a toast reports `created`/`skipped`. Additive — existing edited rows are preserved (server is source of truth; refetch re-baselines).
- **Orphan badge:** a variant whose junction `attribute_values` is **not** a subset of the current selection shows a "not in current selection" badge; its delete button is the escape hatch (no auto-delete).
- **Inline barcode error:** on row save, map a `422 errors.barcode` to a per-row field error (not just a toast).
- **Delete confirm:** per-combination delete opens a fixed-size confirmation dialog (per `feedback_modal_fixed_size`) before calling `deleteVariant`.
- Design tokens only (no hardcoded Tailwind colors); all strings via `t()`.

### 3.6 Frontend — API client, hooks, types
- `generateVariantMatrix(productId, axes)` posts `{ axes }`; return type carries `{ created, created_count, skipped_count }`.
- `useAttributeValues(attributeId)` (or reuse existing) for per-axis chips; `useGenerateMatrix` updated for the new return.
- `ProductVariant` type picks up `attribute_values` from regenerated DTOs.

---

## 4. Error handling
- Generate over cap: 422 client-blocked **and** server-rejected (defense in depth).
- Duplicate barcode: 422 with field error, inline on the row; race-safe via PG error capture.
- Invalid/foreign value_id or non-axis attribute: 422 from `GenerateMatrixRequest`.
- Generate is transactional: any mid-loop failure rolls back the whole batch (existing behavior retained).
- Orphans are never silently mutated.

## 5. Testing (TDD; each milestone → Codex adversarial review, Opus fallback)
**Backend (PG-aware where constraints matter):**
- Idempotent regenerate: same selection twice ⇒ `created_count=0`, no exception, no dup rows.
- Value-subset matrix: 2 axes, partial values ⇒ only selected combos created with correct `name_suffix`/`variant_code`.
- Adding a value ⇒ exactly the new combos created; existing untouched.
- Combo-count cap: selection of 201 ⇒ 422, **zero** rows written.
- Barcode dup ⇒ 422 with `barcode` error naming the conflict (store + update); race path (catch 23505) ⇒ 422.
- `barcode`/`sku` `max:100` enforced.
- DTO includes `attribute_values` with correct pairs; no N+1.
- Onboarding: no axis attribute ⇒ incomplete; one created ⇒ complete; step is optional.

**Frontend (Vitest):**
- Axis check reveals value chips; deselect updates combo count.
- Live count math; soft-warn ≥50; Generate disabled >200 with message.
- Generate posts `{ axes }` with selected value_ids.
- Orphan badge appears for a variant outside the current selection.
- Inline barcode 422 surfaces on the row.
- Delete shows fixed-size confirm; confirm calls delete, cancel does not.

## 6. Out of scope (deferred)
- Saved option-set **templates** (named presets / new tables) — owner option B, deferred.
- Spec C: per-variant QR/barcode label printing (GS1 Digital Link / Sunrise-2027).
- Any auto-delete / soft-delete-on-sync semantics.
- Bulk variant delete endpoint.
- POS-side changes (Spec A territory).

## 7. Constants
- `MAX_VARIANTS_PER_GENERATE = 200` (hard cap, client + server).
- `VARIANT_COUNT_SOFT_WARN = 50` (frontend warning threshold).

## 8. Verification gates (per repo CLAUDE.md)
Backend: scoped PHPUnit (`--filter`, never full suite), PHPStan L8, Pint. Frontend: Vitest (scoped), `pnpm typecheck`, ESLint (color-guard). `php artisan typescript:transform` after the DTO change. End-to-end: author a product with a value subset, generate, edit a barcode to a dup (see 422), delete a combo (confirm), verify onboarding step flips.
