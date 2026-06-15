# Spec B — Web-Admin Variant Authoring & Onboarding Polish

**Version:** v3 (Codex review rounds 1–2 resolved — see §9, §10)
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

1. **Reusable option-set UX = per-product value subset + idempotent additive generate.** Reuse the existing global attributes; **no new tables**. (Saved option-set *templates* explicitly deferred.) The per-product subset is **not separately persisted** — it is the set of attribute-value pairs encoded by the product's existing variants (the junction table). On load the editor **hydrates** its selection from those rows (§3.5); the requirement is inference-based, not a durable per-product whitelist. *(Resolves Codex HIGH-4.)*
2. **Sync semantics = additive-only, never auto-delete; regenerate restores re-selected soft-deleted combos.** Generate creates only missing combos and **restores** (un-soft-deletes) any soft-deleted variant whose combo is in the current selection — giving delete a recovery path. It never deletes; deselected/orphan variants are kept and flagged, removed only via explicit per-combination delete. (No soft-delete-on-sync.) *(Restore extends this decision per Codex HIGH-1 — owner may override to "keep deleted + report `deleted_skipped_count`".)*
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

`GenerateMatrixRequest` (fast-path HTTP validation only):
- Accept either `axes` (preferred) or `attribute_ids` (legacy). Validate UUIDs, non-empty, each `attribute_id` is a variant axis, each `value_id` belongs to its attribute.
- Compute the **selected** combo count = `∏ |value_ids|` (legacy: `∏ |all values|`). Reject with **422** (`combinations` field) when `> MAX_VARIANTS_PER_GENERATE` (**200**). This is an early HTTP guard.

`ProductVariantService::generateMatrix(string $productId, array $axes)`:
- Build axes from the **selected** value IDs only (legacy path expands to all values, preserving today's behavior).
- **The cap is on the GROSS resulting matrix size** — `∏ |value_ids|` = the total variants the product would have for that selection, *not* net-new rows after exclusion. Rationale: the ceiling bounds how many variants one product may carry; a selection whose cross-product exceeds 200 is rejected even if some combos already exist, because the product cannot legitimately hold >200 variants. *(Resolves Codex r2 HIGH-N2.)*
- **Re-enforce the cap at the service boundary** (`∏ |value_ids| ≤ MAX_VARIANTS_PER_GENERATE`) *before* calling `cartesian()`, throwing a domain exception the controller maps to the same 422. The FormRequest is only a fast-path; the service is the authoritative guard so no future backend caller can bypass it or materialize a runaway cartesian array. *(Resolves Codex MED-6.)*
- **Idempotency source of truth = the junction table (attribute_id/value_id), not `variant_code` strings.** Load existing variants (incl. soft-deleted) **with** their `product_variant_attribute_values` rows, key each by its `(attribute_id → attribute_value_id)` set, and match in **ID space**:
  - The service already builds `$lookup[attributeCode][valueCode] = {attributeId, attributeValueId, label}` from values it loaded **by `value_id`** — so codes are always current and there is **no stale-rename hazard** (the frontend sends value IDs, never codes). `cartesian()` runs in code-space; the **code↔id translation is explicit**: each existing variant's ID-set is mapped back through `$lookup` to derive `$excluded` (code-shape), and each generated combo is mapped forward to its ID-set before matching. *(Resolves Codex r2 MED-8.)*
  - **exclude** combos already present as an **active** variant (via `cartesian()`'s `$excluded`), and
  - **restore** (un-soft-delete) a soft-deleted variant whose combo is re-selected, rather than a fresh insert — the hard `UNIQUE(product_id, variant_code)` (no `deleted_at` predicate) makes re-insert impossible, so restore is the only correct path and preserves prior edits. Restore reloads the junction before returning. **At most one row (active or soft-deleted) can exist per combo** (the hard `variant_code` unique guarantees it), so restore is unambiguous. *(Resolves Codex HIGH-1; refutes the r2 "multiple soft-deleted rows per combo" subpoint via the schema.)*
  - **Concurrency:** find-then-restore runs inside the existing outer `DB::transaction` and selects the candidate `… FOR UPDATE`, so concurrent regenerate calls serialize on the row instead of racing two `restore()`s. *(Resolves Codex r2 HIGH-1 race.)*
  - `variant_code` is an output identifier only, never the idempotency key.
- Restore edge case: if reviving a soft-deleted variant would collide with an **active** variant's `sku`/`barcode` (those partial-unique indexes exclude soft-deleted rows), the restore surfaces a **422** naming the conflict rather than 500. (Rare — `sku` is deterministic per product; only manual edits cause it.)
- Default-variant rule unchanged (first *ever* variant for a product becomes default; idempotent re-runs never add a second default).
- Return a summary `{ created_count, skipped_count, restored_count }` alongside the created/restored variants.

**Response contract (non-breaking):** keep `data` as the array of affected `ProductVariantData` (the current shape) and put counts in `meta`: `{ data: ProductVariantData[], meta: { created_count, skipped_count, restored_count } }`. Existing callers that read `data` as an array keep working. *(Resolves Codex MED-5.)*

Idempotency guarantee: regenerating with an unchanged or subset selection creates 0, restores 0, throws nothing. Adding a value creates exactly the new combos. Re-selecting a previously-deleted combo restores it.

### 3.2 Backend — variant DTO exposes junction

`ProductVariantData` gains `attribute_values: list<{ attribute_id: string, attribute_value_id: string }>`, sourced from the `product_variant_attribute_values` junction. **Relationship contract** *(resolves Codex MED-7)*:
- Add a named `ProductVariant::attributeValues(): HasMany` relation to `ProductVariantAttributeValue`.
- `index`/`store`/`update`/`generateMatrix` must `with('attributeValues')` (lists) or `loadMissing('attributeValues')` (single, after write) before transformation.
- `ProductVariantData::fromModel()` consumes the **loaded** relation only — never queries inside the mapper — so listing 200 variants stays a single query. Regenerate TS types via `php artisan typescript:transform`.

### 3.3 Backend — barcode uniqueness → 422

- Add a tenant-scoped uniqueness rule to `CreateVariantRequest` and `UpdateVariantRequest`: barcode unique among non-deleted variants, **excluding the current variant** (update) — matching the DB partial index. On update, the variant id comes from the route. This is the **fast-path**.
- **Race-safety mechanism = a centralized PG unique-violation mapper** for SQLSTATE `23505`, applied so it covers **both** create **and** the update path (which today `fill()`/`save()`s Eloquent directly without a service). Either route the barcode-writing save through a service method that catches the violation, or register a handler that inspects the violated **constraint name**. The product_variants indexes are (exact names, verified against migration `…100003`):
  - `product_variants_tenant_barcode_unique` → **422** `{ errors: { barcode: [...] } }`
  - `product_variants_tenant_sku_unique` → **422** `{ errors: { sku: [...] } }`
  - `product_variants_product_id_variant_code_unique` (Laravel default from `$table->unique(['product_id','variant_code'])`) and `product_variants_default_unique` → these are internally-derived, not user-entered; map to a generic 409/422 (never to a `barcode` error).

  The mapper must match on the **specific** constraint name so a `sku`/`variant_code`/`is_default` violation is **not** mis-reported as a barcode error. The FormRequest rule is *not* relied on for race safety. *(Resolves Codex HIGH-2 + r2 constraint-naming gap.)*
- Tighten `barcode`/`sku` `max:255` → `max:100` to match the column. Non-breaking (stricter).
- **Conflict-name message is best-effort polish** *(Codex LOW-9)*: the 422 must always carry a `barcode` field error; naming the conflicting variant's `name_suffix` is added on top via a **tenant-scoped** lookup that mirrors the partial index exactly (excludes soft-deleted). If the lookup is ambiguous/empty, fall back to a generic message — never block the 422 on it. (Note: existing `findByBarcode` is `company_id`-scoped, so a new tenant-scoped query is needed.)

### 3.4 Backend — onboarding step

- `OnboardingStep::ProductOptions = 'product_options'`; `isRequired() => false`; `label() => 'Set up product options'`; `settingsPath() => '/catalog/attributes'`.
- `OnboardingChecklistService`: new arm → `checkProductOptions()` returns whether **any** `ProductAttribute` with `is_variant_axis = true` and not soft-deleted exists. Attributes are tenant-scoped (no `company_id`), so the check is a tenant-wide existence query (the per-request connection is already the tenant DB).
- Frontend needs only the `onboarding.steps.product_options` i18n key (en + fr); `SetupChecklist` renders it automatically.

### 3.5 Frontend — `ProductVariantMatrixEditor` rework

- **Selection hydration on load** *(resolves Codex HIGH-4)*: the selected axes + values are **derived from the product's existing variants' `attribute_values`** (now in the DTO), not from any separate store. Initial selection = the union of attribute IDs and value IDs present across active variants. This is the durability mechanism — the variants *are* the persisted subset. (A sparse set of existing combos hydrates to the full cross-product of its values; additive generate then offers to fill any genuinely-missing combos, which is the intended matrix behavior.)
- **Axis + value selection:** checking an axis reveals its values as toggle chips (fetched via `getAttributeValues(attributeId)`; all selected by default for a *new* axis, or hydrated from existing variants). Deselecting a chip excludes that value. Color-type values render a swatch from `hex_color`.
- **Live combo count:** `∏ selected value counts`, shown inline. Soft warning styling at **≥ 50**; **Generate disabled with explanatory message > 200** (shared `MAX_VARIANTS_PER_GENERATE` constant).
- **Generate = "Generate / sync matrix":** posts `{ axes }`; on success a toast reports `created`/`skipped`. Additive — existing edited rows are preserved (server is source of truth; refetch re-baselines).
- **Orphan badge:** a variant whose junction `attribute_values` is **not** a subset of the current selection shows a "not in current selection" badge; its delete button is the escape hatch (no auto-delete).
- **Inline barcode error:** on row save, map a `422 errors.barcode` to a per-row field error (not just a toast).
- **Delete confirm:** per-combination delete opens a fixed-size confirmation dialog (per `feedback_modal_fixed_size`) before calling `deleteVariant`. The dialog surfaces the backend delete-policy outcome — if the variant is referenced (§3.7), the API returns 422 and the dialog shows "this variant has stock or sales history; deactivate it instead" with a one-click **Deactivate** (`is_active=false`) action.
- Design tokens only (no hardcoded Tailwind colors); all strings via `t()`.

### 3.7 Backend — variant delete policy *(resolves Codex HIGH-3 + r2 HIGH-N1)*

**Key correction (verified against the migration set):** `variant_id` is threaded into **~15** tenant tables — `stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `document_lines`, `pos_receipt_lines`, `pos_order_lines`, `pos_receipt_line_batch_allocations`, `catalog_cart_items`, `price_list_items`, `recipe_lines` (as `component_variant_id`), `stock_transfer_lines`, `inventory_counting_items`, `channel_product_mappings` — not the four the v2 spec listed. (Codex r2's `stock_adjustments`/`purchase_order_lines` do **not** exist in the schema — that claim was discarded.)

**This reframes the policy.** The endpoint performs a **soft delete** (`SoftDeletes` → sets `deleted_at`, the row physically remains). Therefore **no FK reference is ever broken** by deletion — every one of those ~15 tables continues to join cleanly, including fiscal `document_lines`/`pos_receipt_lines`. So enumerating all referencing tables is both unnecessary (no integrity risk) and a module-boundary violation (Catalog must not read Inventory/Document/POS tables directly — CLAUDE.md rule 6).

The one genuine operational hazard is **stranded on-hand stock**: hiding a variant that still holds inventory. Policy for `DELETE product-variants/{id}` (`ProductVariantController@destroy`):
- **Block** the soft-delete (return **422**, field-agnostic message) when the variant has **non-zero on-hand stock**.
- The stock check is made through the **Inventory module's public contract/service** (a `Shared/Contracts` interface or Inventory service method), constructor-injected — **never** a direct query into `stock_levels` from Catalog.
- Steer the user to retire via `is_active=false` (already supported by the update path) — the correct way to remove a stocked or historically-referenced variant from selection while preserving all joins.
- Allow soft-delete for variants with **zero on-hand stock** (a freshly-generated combo the user never stocked/sold). Historical references survive regardless.
- *(Owner-decision flagged for review: (a) block-on-stock as above [recommended], vs (b) warn-only; and whether to additionally block when sales history exists — deemed unnecessary here since soft-delete preserves it.)*

### 3.6 Frontend — API client, hooks, types
- `generateVariantMatrix(productId, axes)` posts `{ axes }`; reads the affected variants from `data` (array, unchanged) and the `created_count`/`skipped_count`/`restored_count` from `meta` for the result toast. Because the response carries `meta`, the API client must use `api.post` + `response.data` (not `apiPost`, which unwraps `data.data` and would drop `meta`) — per the paginated-endpoint pitfall in MEMORY.
- `useAttributeValues(attributeId)` (or reuse existing) for per-axis chips; `useGenerateMatrix` updated for the new return + counts.
- `ProductVariant` type picks up `attribute_values` from regenerated DTOs (drives hydration + orphan badge).

---

## 4. Error handling
- Generate over cap: 422 client-blocked **and** server-rejected (defense in depth).
- Duplicate barcode: 422 with field error, inline on the row; race-safe via PG error capture.
- Invalid/foreign value_id or non-axis attribute: 422 from `GenerateMatrixRequest`.
- Generate is transactional: any mid-loop failure rolls back the whole batch (existing behavior retained).
- Orphans are never silently mutated.

## 5. Testing (TDD; each milestone → Codex adversarial review, Opus fallback)
**Backend (PG-aware where constraints matter):**
- Idempotent regenerate: same selection twice ⇒ `created_count=0`, `restored_count=0`, no exception, no dup rows.
- Value-subset matrix: 2 axes, partial values ⇒ only selected combos created with correct `name_suffix`/`variant_code`.
- Adding a value ⇒ exactly the new combos created; existing untouched.
- **Restore-on-regenerate:** soft-delete a combo, re-select it, regenerate ⇒ same row revived (`restored_count=1`, prior edits preserved), no new row, no `variant_code` collision.
- **Idempotency keyed by junction, not `variant_code`:** changing a value `code` (relabel) does not cause duplicate generation of an existing combo.
- Combo-count cap: selection of 201 ⇒ 422 from FormRequest **and** from the service when called directly ⇒ **zero** rows written.
- Barcode dup ⇒ 422 with `barcode` error (store **and** update); race path (catch 23505 on both create and update) ⇒ 422 not 500; conflict-name best-effort (generic fallback when ambiguous).
- `barcode`/`sku` `max:100` enforced.
- DTO includes `attribute_values` with correct pairs via the loaded relation; assert single-query (no N+1) when listing.
- **Delete policy:** delete a variant with non-zero on-hand stock ⇒ 422 (blocked, via the Inventory contract — assert no direct `stock_levels` query from Catalog); delete a zero-stock variant ⇒ 204; soft-deleted variant's `document_lines`/`pos_receipt_lines` joins still resolve.
- **Cap is gross:** select axes whose cross-product is 201 while 50 combos already exist ⇒ still 422 (cap on resulting matrix size, not net-new).
- Response shape: `data` is an array, counts live in `meta`.
- Onboarding: no axis attribute ⇒ incomplete; one created ⇒ complete; step is optional.

**Frontend (Vitest):**
- **Hydration:** opening a product with existing variants pre-selects the axes/values encoded by their `attribute_values`.
- Axis check reveals value chips; deselect updates combo count.
- Live count math; soft-warn ≥50; Generate disabled >200 with message.
- Generate posts `{ axes }` with selected value_ids; result toast reads `meta` counts (created/skipped/restored).
- Orphan badge appears for a variant outside the current selection.
- Inline barcode 422 surfaces on the row.
- Delete shows fixed-size confirm; confirm calls delete; a 422 (referenced variant) shows the deactivate-instead path; cancel does nothing.

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
Backend: scoped PHPUnit (`--filter`, never full suite), PHPStan L8, Pint. Frontend: Vitest (scoped), `pnpm typecheck`, ESLint (color-guard). `php artisan typescript:transform` after the DTO change. End-to-end: author a product with a value subset, generate, delete then re-select-and-regenerate (see restore), edit a barcode to a dup (see 422), try to delete a sold variant (see 422 → deactivate), verify onboarding step flips.

---

## 9. Codex review round 1 — resolutions (2026-06-15)

Review file: `docs/superpowers/reviews/2026-06-15-web-variant-authoring-spec-codex-review.md` (verdict: REVISE; 0 BLOCKER, 4 HIGH, 4 MED, 1 LOW). All findings adopted.

| # | Sev | Finding | Resolution | Section |
|---|-----|---------|-----------|---------|
| 1 | HIGH | Soft-deleted combos become permanent holes | **Restore-on-regenerate** for re-selected soft-deleted combos (recovery path). *Owner-decision: may instead keep-deleted + report `deleted_skipped_count`.* | §2.2, §3.1 |
| 2 | HIGH | Barcode race bypasses service on update | Centralized `23505` mapper covering **both** create + update; FormRequest rule = fast-path only | §3.3 |
| 3 | HIGH | Delete ignores stock/fiscal refs | New **delete policy** (§3.7): block when referenced/stock>0, steer to `is_active=false`. *Owner-decision: could relax to warn-only.* | §3.5, §3.7 |
| 4 | HIGH | Per-product subset not durable | **Inference-based hydration** from existing variants' junction (no new table); requirement reworded | §2.1, §3.5 |
| 5 | MED | Response shape breaking change | Keep `data` as array, counts in **`meta`** (non-breaking) | §3.1, §3.6 |
| 6 | MED | Cap not guarded at service boundary | Re-enforce cap **in the service** before `cartesian()`; FormRequest is fast-path | §3.1 |
| 7 | MED | DTO junction lacks relationship contract | Add `ProductVariant::attributeValues()`; `with`/`loadMissing`; mapper consumes loaded relation | §3.2 |
| 8 | MED | `variant_code` brittle for idempotency | Build `$excluded`/match from **junction** `(attribute_id,value_id)` pairs; `variant_code` is output-only | §3.1 |
| 9 | LOW | Conflict-name adds scope | Conflict-name is **best-effort** on top of the guaranteed 422; tenant-scoped lookup; generic fallback | §3.3 |

Two resolutions extend earlier owner decisions and are flagged inline for confirmation at the spec-review gate: **#1 restore semantics** and **#3 delete-block policy**.

---

## 10. Codex review round 2 — resolutions (2026-06-15)

Review file: `docs/superpowers/reviews/2026-06-15-web-variant-authoring-spec-codex-review-r2.md` (verdict: **APPROVE-WITH-MINOR-EDITS**; 0 BLOCKER). Round 2 confirmed HIGH-4, MED-5/6/7, LOW-9 fully resolved and raised refinements on the rest. All adopted; **claims were verified against the schema before adoption**, and one was found false.

| r2 item | Sev | Status after verification | Resolution | Section |
|---|-----|---------|-----------|---------|
| HIGH-1 restore race / multi-row | HIGH | Race valid; "multiple soft-deleted rows per combo" **false** (hard `variant_code` unique ⇒ ≤1 row/combo) | `SELECT … FOR UPDATE` on find-then-restore; documented the ≤1-row guarantee | §3.1 |
| HIGH-2 constraint names | HIGH | Valid | Named the 4 exact PG index names; only the barcode index maps to a `barcode` 422 | §3.3 |
| HIGH-3 / HIGH-N1 missing tables | HIGH | **Partially false + reframed**: named tables `stock_adjustments`/`purchase_order_lines` **don't exist**; real list is ~15 tables; but soft-delete preserves all FK refs | Reframed policy: block only on **on-hand stock**, via Inventory **public contract** (not a 15-table sweep); fixes the cross-module-boundary smell too | §3.7 |
| MED-8 code↔id gap | MED | Valid | Made the code↔id translation explicit via `$lookup`; noted no stale-rename hazard (frontend sends IDs) | §3.1 |
| HIGH-N2 gross-vs-net cap | HIGH (new) | Valid ambiguity | Cap is explicitly on **gross resulting matrix size**, with rationale | §3.1 |

**Verification note:** Codex r2's HIGH-N1 cited two tables (`stock_adjustments`, `purchase_order_lines`) that a migration grep proved absent. Trusting it verbatim would have added dead checks; verifying it instead surfaced the *real* ~15-table picture and the insight that soft-delete makes table-enumeration unnecessary — yielding a **simpler** policy than the review proposed.

**Net result:** spec is implementable. Remaining open items are the two owner-decisions (restore semantics §2.2/§3.1; delete-block policy §3.7), not correctness gaps.
