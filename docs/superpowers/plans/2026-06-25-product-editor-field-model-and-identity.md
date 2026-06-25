# Product Editor — Field Model, Identity & Controls Plan

> Companion to `2026-06-24-izipos-product-editor.md`. This refines Stage 1.7b (content parity) + Stage 2/3 into a **reuse-and-expand, no-regression** field model grounded in the actual code + industry best practice. Pixel source: `docs/handoff/mocks/IZI POS - Add Product.dc.html`.

**Guiding principles (from the product owner):**
1. **Rearrange & restyle what works** — don't rebuild. The existing `BarcodeLookupInput` *becomes* the hero barcode (same logic, moved up + restyled). Same for every field that already exists.
2. **No regressions** — every field/datum the form (or backend) supports per vertical survives into the new design. Reuse, then expand.
3. **Add genuinely-missing-but-sensible fields** as planned nullable additions (e.g. units-per-pack), never ad hoc.
4. **Two apps × multiple verticals** — base + IziPOS(parapharmacy) + Otospex(automotive); the editor conditionally surfaces each vertical's data.
5. **Ergonomic, non-blocking entry** — minimum required info always present, but never block the user (auto-derive identity).
6. **Right control for the job** — styled checkbox for single save-on-submit booleans; toggle only for "feature on/off"; radio/select for genuine multi-choice (research below).

---

## 1. Identity strategy — barcode-first, never blocking

> **FINAL DECISION (2026-06-25, owner): ship the simplest thing now (YAGNI to go-live).**
> - **Keep ONE `barcode` field** (optional, as today — no new schema, no regression). Whatever the user enters is THE barcode linked to the product. Most of the time it's a real EAN-13.
> - **No internal-barcode minting built now.** When a non-EAN value is entered it's simply stored as-is (our internal/other-subsystem code). The future dual handling (mint internal RCN when blank; lookup that inspects the **format** — EAN-13 vs internal — to resolve) is documented in §8 and built post-go-live, **minted centrally on the Synerivia platform**. Design must not block this (don't hard-assume single-format), but don't build it yet.
> - **SKU stays required + unique** for now (auto-populate-from-name/barcode is a later nicety, not a blocker now).
> - **Immediate editor work = ZERO backend identity change**: just move the existing `BarcodeLookupInput` into the hero (§4).
>
> The rest of this section is the *future* rationale, retained for when we revisit post-launch.

**Research (cited):** SKU is the *internal* key (you control format; must be unique; 8–16 chars; `-`/`_` only; avoid O/0,I/1; no dates/prices). Barcode (EAN/UPC) is the *standardized, scannable* key used at POS. Auto-generated codes are acceptable for non-blocking entry. Sources: Shopify SKUs, erplain, Onsight.

**Decision (matches owner intent):** the product is always identifiable without blocking the user.
- **Barcode = the primary scannable identity.** Required *in effect*: if the user leaves it blank, **auto-generate an internal barcode** in our own numbering scheme (e.g. prefix `IZI-` + tenant short + zero-padded sequence, or a GS1-style internal range). Stored in `barcode`. If a real EAN/UPC is later known, the user can overwrite it.
- **SKU stays the unique DB key (required, unique per tenant)** — but **auto-populate it** (from the barcode, or a slug of the name) when the user leaves it blank, and keep it **editable before save**. So the user is never blocked, and the unique-SKU invariant the system relies on is preserved.
- **Net UX:** user can create a product with just **name + sale price** (as the mock subtitle promises). SKU + barcode auto-fill, both editable.

**Backend work this implies (Stage I — Identity):**
- A `BarcodeGenerator` service (Application layer, constructor-injected) producing a unique internal barcode; wire into product-create when `barcode` is empty. Add a per-tenant sequence or collision-checked random.
- An SKU auto-populate rule (FE first: derive from barcode/name on blur if empty; BE fallback: generate if still empty at create, guaranteeing uniqueness with a suffix on collision).
- **OPEN DECISION (owner):** barcode is currently **non-unique by design** (variants/imports can share). Do we (a) keep it non-unique but always-populated (safest, recommended), or (b) add a per-tenant unique index on `barcode` (cleaner identity, but risks colliding with existing duplicate/imported/variant data — needs a data audit + migration)? Recommend **(a)** now, revisit (b) after a duplicate audit.
- Keep `BarcodeLookupInput`'s debounce/scanner/enrichment exactly as-is; the generator only fills the gap when the user never enters one.

---

## 2. Units model — per-product override + packaging

**Reality:** full `units` table (code/name/symbol/conversion_factor/decimal_places/rounding/is_base/is_system) + `unit_categories`, managed in `UnitsSettingsPage`. Products have legacy `unit` (string) AND nullable `unit_id` (FK). The form only uses the free-text string today.

**Decision:**
- **"Unit of measure" → a `unit_id` select** sourced from the tenant's configured units (the per-product override the owner described). Build a `UnitSelect` atom (searchable select over `GET /units`, grouped by category). On save, send `unit_id`; **keep writing the `unit` string too** (mirror the selected unit's `code`/`symbol`) so legacy readers + existing products don't regress during the transition.
- **No-regression:** products that only have the legacy string still display it; the select pre-selects the matching unit by `code` when possible, else shows the raw string as a fallback option.
- **Packaging (mock "Units per pack" / "Sold as") — NEW, nullable:**
  - `units_per_pack` — nullable integer (e.g. 24 tablets per box). Add migration + DTO + validation (`nullable|integer|min:1`).
  - "Sold as" — a nullable second `sold_as_unit_id` FK (the selling unit when it differs from the stock unit, e.g. stock in "tablet", sold as "box of 24"). Add nullable FK. **OPEN DECISION (owner):** include "Sold as" now, or just `units_per_pack` for v1? Recommend `units_per_pack` now (simple, clearly useful) and defer `sold_as_unit_id` unless you confirm the dual-unit selling model is needed at launch.

---

## 3. Controls — checkbox vs toggle vs radio

**Research (cited):** for a **single** on/off that applies **on form submit**, a **checkbox is correct**; a **toggle** implies an *instant* effect; **radio** is only for 2+ *mutually exclusive* options. Sources: Sparkbox, UXtweak, Helsinki DS.

**Decisions:**
- Build a small **`Toggle` (switch) atom** in `components/atoms` (none exists) — styled like the mock's batch toggle (track `success` when on, knob, label).
- **Use `Toggle`** for "feature on/off" semantics that read as a mode: `requires_batch_tracking` ("Track batches & expiry" — matches the mock toggle), `is_universal_fit` (automotive).
- **Keep styled `Checkbox`** (restyled to the mock's checked-square) for save-on-submit attributes: `is_active` (Active/sellable), `is_active_for_ecommerce`, `requires_consultation`, "Eligible for discounts". (Per research these are *not* toggles.)
- **`type` / physical:** replace the bare "Physical Product" checkbox with the mock's **Type select** (Storable/Consumable/Service/Part), and derive `is_physical` from the chosen type (service ⇒ non-physical) so we keep the fiscal-workflow flag without a redundant control.
- **Radio:** reserve for true 2–3 mutually-exclusive cases only; everything multi-option in the mock is already a `Select` (Age restriction, Dosage form) — keep selects. (We will NOT convert single booleans to radios — that's the anti-pattern the owner's idea would hit.)

---

## 4. Barcode-lookup → hero (rearrange, don't rebuild)

- The hero's plain barcode `<Input>` is replaced by the **existing `BarcodeLookupInput` logic**, restyled for the dark band (mono, dark surface). Move `onProductData`/`onLookupStateChange`/prefill-highlight wiring up with it. The "Synerivia · N fields" pill = the `found` lookup state's field count; the helper line + refresh = manual re-run (this is also where Stage-4 inline enrichment lands).
- Remove the duplicate `BarcodeLookupInput` from General (resolves the "two barcodes"). The enrichment opt-in (shown on `not_found`) moves to the hero (small inline row under the band) so it's not lost.
- Risk to handle: the hidden `register('barcode')` backing field must stay wired through the hero so submit still sends `barcode`.

---

## 5. Full field model (reuse + expand, per section, per vertical)

Legend: **REUSE** = field/logic already exists, just rearrange+restyle · **WIRE** = in backend schema but not in form yet (add the control) · **NEW** = needs a nullable migration + DTO + validation.

### Hero (all verticals)
| Field | Status | Notes |
|---|---|---|
| barcode (+ lookup/scanner/enrichment) | REUSE | move `BarcodeLookupInput` up; auto-gen internal if blank (§1) |
| name | REUSE | hero name input (single source) |
| enrichment status pill / refresh | REUSE/Stage-4 | from lookup state |

### 01 General
| Field | Status | Control | Notes |
|---|---|---|---|
| SKU | REUSE | Input + help | auto-populate if blank, editable (§1); keep required+unique |
| Type | WIRE | Select | Storable/Consumable/Service/Part; derives `is_physical` |
| Description | REUSE | Textarea | |
| Brand | NEW (Stage 2) | BrandSelect | normalized lookup + inline create |
| Manufacturer | NEW (Stage 2) | ManufacturerSelect | normalized lookup + inline create |
| Unit of measure | WIRE | UnitSelect (`unit_id`) | §2; mirror legacy `unit` string |
| Country of origin | NEW (Stage 2) | CountrySelect | ISO-3166 |
| Category | REUSE | CategorySelect | keep (real functionality the mock omits — place in General) |
| Active (sellable) | REUSE | Checkbox (styled) | `is_active` |
| Active for e-commerce | WIRE | Checkbox (styled) | `is_active_for_ecommerce` |

### 02 Pricing & Tax
| Field | Status | Control | Notes |
|---|---|---|---|
| Purchase price | WIRE | MoneyInput | editable at create (seeds cost); help "Ex-tax, from supplier" |
| Margin | NEW (computed, not stored) | read-only | from sale vs purchase/cost |
| Sale price * | REUSE | MoneyInput | TTC (tax-inclusive), per precision rule 19 |
| Cost (WAC) | REUSE | read-only (edit mode) | keep as-is |
| Tax rate / class * | REUSE | TaxConfigurationField | |
| Loyalty points | NEW (defer/placeholder) | Input | belongs to Loyalty module — placeholder now, wire later or gate |
| Eligible for discounts | NEW (defer/placeholder) | Checkbox | confirm ownership (pricing vs promotions) |

### 03 Inventory & Units
| Field | Status | Control | Notes |
|---|---|---|---|
| Opening stock (ONCE) | Stage 3 | QuantityInput | inline opening balance — **gated on the parallel StockMovement reason work** |
| Opening unit cost | Stage 3 | MoneyInput | |
| As-of date | Stage 3 | date Input | |
| Units per pack | NEW | Input(int) | §2 nullable |
| Sold as | NEW (decision) | UnitSelect | §2 — defer unless confirmed |
| Shelf / aisle | NEW (decision) | Input | inventory location; per-location really lives in Inventory module — confirm whether product-level default is wanted |
| Reorder point / qty | WIRE? | Input | per-location in Inventory module; product-level default = NEW nullable if wanted — confirm |
| Track batches & expiry | WIRE | **Toggle** | `requires_batch_tracking` (+ `default_shelf_life_days` when on) |
| Lock-after-movement note | Stage 3 | info banner | |

### Media & Files
| Status | Notes |
|---|---|
| DEFERRED (decision earlier) | gallery is visual chrome only until the parallel media-unification lands; build the visual grid (featured/PRIMARY, thumbs, leaflet/PDF, video, add-tile) as placeholders, wire to `ProductImageSection`/MediaRole after. |

### Pharmacy (IziPOS / parapharmacy gate) — all REUSE
category*, dosage_form, age_restriction, active_ingredients[], usage_instructions, warnings, contraindications, minimum_age, requires_consultation (checkbox), regulatory_code, storage_requirements — re-lay-out the **already-atom-refactored** `ParapharmacyMetadataFields` to the mock's 3-col grid. **Expansion (optional):** `key_components`, `health_claims`, `certifications` exist in schema but aren't rendered — add array builders if wanted (not in mock; confirm).

### Automotive (Otospex gate) — REUSE + big WIRE
Currently only `oem_numbers` + `cross_references` render. Backend supports far more. This is **not a regression** (never in form) but is the biggest expansion: article_number, supplier_brand, product_group_name, brand_quality_tier, article_status, weight_kg, dimensions, is_universal_fit (Toggle), tire specs (width/aspect/rim/speed/load/season), glass specs (type/tinting), vehicles[], criteria[], superseded_by_product_id. **Scope decision:** the live mock is IziPOS/pharmacy; build base+IziPOS parity first, then a dedicated **Automotive section** pass for Otospex (own task wave). Flagged so it's not forgotten.

---

## 6. Revised task sequence (supersedes the simple Stage 1.7b)

1. **`Toggle` atom** (+ test) — needed by Inventory/automotive.
2. **`UnitSelect` atom** over `GET /units` (+ test) — for Unit of measure.
3. **General parity** (1.7b-1, retry): SKU(+auto-populate help), Type select (derive is_physical), Description, Brand/Mfr/Country placeholders, UnitSelect, Category (kept), Active + Active-for-ecommerce — remove the General barcode/name duplication; restyle checkboxes.
4. **Barcode-into-hero** (1.7b-1b): move `BarcodeLookupInput` into the hero; remove from General; preserve lookup/scanner/enrichment + hidden barcode register.
5. **Pricing parity** (1.7b-3a): Purchase price (wire), computed Margin, Sale price, Tax, (Loyalty/Discounts placeholders).
6. **Inventory parity** (1.7b-3b): Units-per-pack (NEW), Track-batches Toggle (wire `requires_batch_tracking`+`default_shelf_life_days`), placeholders for shelf/reorder pending decisions; opening-balance block stubbed (real impl = Stage 3, gated).
7. **Pharmacy re-lay-out** (1.7b-4): 3-col grid on existing fields.
8. **Media visual** (1.7b-5): gallery placeholders (deferred backend).
9. **Backend — Identity** (Stage I): BarcodeGenerator + SKU auto-populate; tests.
10. **Backend — new nullable fields** (Stage F): `units_per_pack` (+ `sold_as_unit_id`/shelf/reorder if confirmed); DTO + `typescript:transform` + validation; wire the WIRE fields (`type`, `unit_id`, `is_active_for_ecommerce`, `requires_batch_tracking`, `default_shelf_life_days`, `purchase_price`) through Create/Update requests + ProductData.
11. **Stage 2** (brands/manufacturers/country) — makes the General placeholders real.
12. **Automotive section** (Otospex) — separate wave.
13. **Stage 3** (opening balance) — last, gated on StockMovement reason work.

Each is a TDD task; no-regression guard = the existing ProductForm test suites must stay green throughout.

---

## 7. Decisions (resolved 2026-06-25) + remaining
- **Barcode uniqueness** — RESOLVED: **no DB uniqueness constraint** (variants share a parent product's barcode; variants live underneath the product). Internal barcodes are minted unique-per-product (see §8). Full barcode model is its own decision below.
- **Packaging "Sold as"** — RESOLVED: **units_per_pack only** for v1; defer `sold_as_unit_id`.
- **Shelf/aisle + reorder point/qty** — RESOLVED: **product-level nullable defaults** surfaced in the Inventory section (per-location Inventory module can override).
- **Loyalty points / Eligible-for-discounts** — RESOLVED: **integrate visually now, but gate on the Loyalty add-on module** — the field renders ONLY when the Loyalty module is active; all logic is owned by the Loyalty module. A separate session will design that ownership (see `docs/handoff/HANDOFF-loyalty-product-fields.md`).
- **Pharmacy expansion** (`key_components`/`health_claims`/`certifications`) — defer (not in mock); revisit.
- **Automotive section** — follow-on wave after IziPOS parity.
- **OPEN (needs owner pick): the barcode data model** — see §8.

---

## 8. Barcode identity — deep-research findings & options

**Owner intent:** (a) when a known barcode is entered → fetch/populate the product (enrichment), variants may share it; (b) when none is entered → mint a **unique internal barcode** so the product is recognizable later; minting ideally on the **Synerivia platform** so the same code populates the product next time anyone scans it; and when a real EAN-13 later exists, the product should carry it — possibly **alongside** the internal/old barcode (so we must decide one-field-replace vs two-codes).

**Research (cited):**
- **GS1 RCN** — prefixes **02 and 20–29** are *Restricted Circulation Numbers*, the standard range for **in-store/internal items without a manufacturer GTIN**. Minting an internal barcode in an RCN range (EAN-13 with valid check digit) is scannable AND guaranteed not to collide with real GTINs. ([GS1 General Specs](https://www.gs1.org/docs/barcodes/GS1_General_Specifications.pdf), [GS1 prefixes 20–29](https://www.gs1.org/docs/barcodes/SummaryOfGS1MOPrefixes20-29.pdf))
- **GTIN/UPC/EAN** are the *external, standardized* scannable keys (UPC-12 NA, EAN-13 intl); **SKU is not a barcode** (internal id). Retailers with global supply often hold **both UPC and EAN**, and a product commonly has **multiple barcodes** (packaging levels, variants, supplier dup). ([inFlow GTIN vs UPC](https://www.inflowinventory.com/blog/gtin-vs-upc/), [Shopify barcode FAQ](https://www.shopify.com/blog/barcode-faq), [GS1 GTIN/EAN/UPC](https://support.gs1.org/support/solutions/articles/43000734124-))

**Options for the data model:**

- **Option A — single `barcode`, replace internal with EAN when known.** Mint RCN if blank; overwrite with the EAN later. Simplest. ✗ Loses the internal code once an EAN arrives → already-printed internal labels stop resolving; no history.

- **Option B — two fields: `barcode` (primary, shown/scanned) + `internal_barcode` (permanent minted RCN).** Hero shows the primary (EAN if present, else internal). Internal is always minted + kept forever. When an EAN is added it becomes primary; internal still resolves old labels. Low complexity; covers the "internal/old vs EAN-13" case the owner raised. ✗ Caps at two codes (no multi-supplier/packaging codes).

- **Option C — `product_barcodes` table (1-to-many), the future-proof target.** Each product has N barcodes: `{ id, product_id, value, type (ean13|upc|internal_rcn|other), is_primary, source (manual|synerivia|scanner) }`. Scanner/lookup matches ANY row → resolves to the product (variants/packaging/supplier dups all coexist; no uniqueness constraint on the product). Hero shows/edits the primary; an "additional barcodes" affordance manages the rest. The minted internal RCN is just a row (`type=internal_rcn`, permanent). Cleanly supports EAN-added-later (new primary row, internal row stays). ✗ More schema + UI.

**Synerivia-platform minting (owner's preference):** the internal RCN should be allocated **centrally by the platform** (a `POST /barcodes/allocate` returning the next RCN in Synerivia's reserved range), tied to `platform_product_id`, so (1) internal codes are unique across the whole Synerivia universe, and (2) scanning that internal code elsewhere hits the enrichment catalog and populates the product. This extends the existing platform-integration/enrichment path. Tenants stay offline-safe by pre-allocating a small RCN block per device/tenant (POS is offline-first), reconciled on sync.

**Recommendation:** target **Option C** (it's the only one that holds up for variants + packaging + EAN-added-later + multi-supplier and matches how POS/ERP systems model this), but **phase it**: Phase 1 ships the primary `barcode` + a permanent `internal_barcode` mint (≈ Option B behaviour) behind a `product_barcodes`-shaped service so the UI/contract don't change when the table lands; Phase 2 promotes to the full 1-to-many table + "additional barcodes" UI. The hero always edits the *primary* barcode; the internal RCN is minted on blank and shown as a read-only "internal code" chip.

**DECIDED (2026-06-25, owner):** **single `barcode` field now** (closest to Option A, but *designed not to block* C) — YAGNI until go-live. Lookup resolves internal-vs-EAN **by format** (EAN-13 vs other) when the full logic is built; minting is **central on the Synerivia platform** (parts may already exist here/on the platform — verify before building). No minting, no extra columns, no uniqueness constraint now. Post-go-live we can promote to B or C without a UI/contract change since the hero edits a single "primary barcode" either way. Action now: **move the existing `BarcodeLookupInput` into the hero; nothing else.**

---

## 9. Codex adversarial-review corrections (2026-06-25) — APPLY THESE
Full review: `docs/superpowers/reviews/2026-06-25-product-editor-plan-codex-review.md`. Amendments to this plan:

1. **[HIGH] Preserve edit-mode media & variants.** "Media & Files **section** deferred" means only the NEW mock gallery is deferred. The EXISTING edit-mode `ProductImageSection` (`ProductForm.tsx:847`) and `ProductVariantMatrixEditor` (`:855`) MUST remain rendered in edit mode (Task 1.4 already keeps them — state it explicitly; the no-regression guard is that those sections still render on edit).
2. **[HIGH] Hero must embed REAL lookup.** The barcode-into-hero task moves the actual `BarcodeLookupInput` logic (debounce/scanner/enrichment, `BarcodeLookupInput.tsx`) into `BarcodeHero` and DELETES the General instance — not a second visual input. Until done, the two-barcode bug stands.
3. **[HIGH] Units mirror is server-side.** Backend create/update must mirror the selected unit's code/symbol into legacy `unit` whenever `unit_id` is set (so `Product::getSellableUnit()` stays correct); add `unit_id` to `ProductData` (+ `typescript:transform`) so the select can preselect; tests for create/update/PATCH; surface unmapped legacy strings as a fallback option.
4. **[HIGH] Type enum.** `ProductType` is **part | service | consumable** only — there is NO `storable`. Either drop "Storable" from the Type select OR add `storable` to the enum (backend change) if the business wants it. Enforce `type`↔`is_physical` consistency server-side (reject contradictory payloads); don't let the FE silently diverge.
5. **[HIGH] Remove `parseFloat`** from the editor's WAC display (`ProductForm.tsx:649`) — use `formatCurrency`/`bcformat` per rule 19. Add to the General/Pricing task.
6. **[MED] Stage 1 split.** Pure layout (chrome) is shippable; field-WIRING (`unit_id`, `purchase_price`, `is_active_for_ecommerce`, `units_per_pack`) is NOT "no schema/contract change" — it needs FormRequest + DTO + `typescript:transform`. Treat wiring as its own backend task, not part of "layout only".
7. **[MED] Toggle rationale fix.** Use a Toggle for a **mode/feature-flag-with-dependent-fields** (e.g. batch-tracking reveals shelf-life), NOT because of "instant effect" (these still save on submit). Correct §3's wording so future reviewers don't convert ordinary save-on-submit flags to toggles.
8. **[MED] Automotive launch scope.** If Otospex ships at go-live, the automotive section is NOT a safe follow-on (the backend supports many fields the form omits) — decide: include it, or declare it an explicit launch exclusion. (Owner decision.)
9. **[MED] Loyalty gating.** Keep the loyalty fields HIDDEN until the Loyalty-owned read/write API exists; if shown as placeholders, add a test proving no loyalty payload is submitted. (See loyalty handoff.)
10. **[LOW] Verify externally:** the parallel StockMovement "reason" contract (note: `stock_movements.reason` + `MovementReason::OpeningBalance` already exist), and parapharmacy CIP/ACL data mappings.
