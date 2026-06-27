# IZI POS — Design Handoff (for Claude Code)

Anchored in the IZI POS logo: **navy + orange + white**. This package is the visual + data spec for re-skinning the dashboard/desktop app and rebuilding the product editor across a **universal CRM / ERP / POS** — so it must stay calm and legible as screens get data-dense. It does **not** touch code — it tells you exactly what to change.

Repo: `otospexsolutions/erp` · branch `dev` · web app `apps/web`.

**Chosen visual language: Direction A — "Crisp / Operational"** (see §0). The colors are unchanged from the palette below; what's settled is the *structural* language: hairline borders instead of soft shadows, tighter corners, monospaced numerics, and color spent only where it carries meaning. Discover/decide aesthetics in the mocks; let **Hallmark enforce** this already-decided direction in code — don't use it to invent the look.

---

## 0. Visual language — Direction A ("Crisp / Operational")

The rules a screen audit checks for the *look* (the palette in §6 is unchanged):

| Decision | Rule |
|----------|------|
| **Structure** | Cards and sections are **flat with a 1px hairline border** (`#E0E4E9`). **No resting drop-shadow.** Shadow is reserved for things that genuinely float — dropdowns, modals, toasts. |
| **Corners** | Tight. Inputs `6px`, buttons `8px`, cards `10px`, sheets `16px`, pills `full`. Nothing pillowy. |
| **Numerics** | Every number that must align — prices, SKUs, quantities, codes, % — is **IBM Plex Mono**. Right-align numeric table columns. |
| **Headings** | Geometric display face for section/page titles (Space Grotesk in the crisp mock; Montserrat remains the brand display — pick one and apply consistently). Tabular UI labels are uppercase 11px, `.12em` tracking. |
| **Color restraint** | Navy = structure, one orange action per screen, neutrals do the work. Semantic colors for **status only**, never decoration. No gradient fills as decoration. |
| **Density** | Designed for data-heavy screens: airy but compact, hairline row separators, KPI tiles summarize before tables. The focus ring is the one place navy gives way to orange. |

**Canonical reference:** `IZI POS - Add Product (Modern Directions).dc.html` → **Direction A** is the crisp editor; the renamed `IZI POS Design System.dc.html` now reflects this language throughout.

---

## 0b. Atomic structure (matches the codebase)

Integrate by atomic level — this maps the system onto how the repo is already organized. (In *mockups* we name and extract by level but don't shatter a screen into 30 files; the codebase stays the canonical home for the atoms.)

- **Atoms** — color/type/spacing tokens, button, input, label, badge/chip, checkbox, toggle, icon.
- **Molecules** — form field (label + input + helper/error), nav item, search box, KPI/stat tile, segmented control, "before-publish" check row.
- **Organisms** — sidebar shell, top bar, section card, data table, media gallery, right-rail card, modal, toast.
- **Templates / Pages** — dashboard shell, the Add-Product editor.

Keep `lib/designTokens.ts` as the atom layer (already pointed at the theme vars); build molecules/organisms from those tokens, never hardcoded hex.

---

## 1. Mockups in this package (open the `.dc.html` files)

| File | What it is |
|------|-----------|
| `IZI POS - Add Product (Modern Directions).dc.html` | **Visual-language reference.** Two modern directions side-by-side; **Direction A (Crisp / Operational) is chosen** — hairline structure, tight corners, mono numerics, flat surfaces. This is the look to build toward. |
| `IZI POS - Add Product.dc.html` | **Primary layout spec — build this.** The redesigned product editor inside the *real* dashboard shell (faithful sidebar groups, TopBar, breadcrumb), themed IZI POS. **Chosen layout: scroll + sticky section nav** (Variation B), slimmed barcode-first hero, opening-balance inventory, related-operations shortcuts. (Apply Direction A's crisp treatment over this layout.) |
| `IZI POS Product Page.dc.html` | Standalone A/B study of the editor (A = tabs, B = scroll). Reference only — the decision (B) is already folded into the Add-Product file above. |
| `IZI POS Design System.dc.html` | Full token reference: color scales, type, spacing, components, dark mode, UX principles — now reflecting Direction A (hairline cards, tight corners). |
| `Easy Pulse Dashboard.dc.html` | Earlier dashboard exploration (simple nav). Superseded by the Add-Product mock for shell fidelity. |
| `handoff/izipos-theme.css` | **The keystone.** Drop-in theme block (see §2). |

---

## 2. Theme — the one change that re-anchors everything

`apps/web/src/index.css` has an `@theme` bridge mapping `blue-*`/`gray-*`/`primary-*`/`secondary-*` onto `--theme-*`/`--color-*`. So a palette block is all that's needed — **no component edits**.

**Action:** paste `handoff/izipos-theme.css` into `index.css` after the `[data-product="otospex"]` block, and ensure `ProductConfigContext` resolves the IziPOS vertical to `product = "izipos"` (DashboardLayout already sets `data-product={product}`). To make IZI POS the default instead, move the vars into `:root` (replacing "Deep Ocean").

- **Primary = navy** (`--theme-primary-600 #1E3A5F`): default buttons, focus rings, links, active nav. `tokens.button.primary` (`bg-blue-600`) becomes navy automatically.
- **Secondary = orange** (`--theme-secondary-500 #EA661A`): the single hero CTA per screen, highlights, logo. Use `secondary-*` utilities; don't make orange the default button.
- `gray-900 = #14283F` so the dark IziPOS sidebar reads as brand navy.

This resolves the orange-vs-navy question: navy carries the work, orange is the accent — calmer for all-day, data-dense use, and consistent with the Otospex vertical's primary/secondary split.

> Per CLAUDE.md rule 18, keep using `lib/designTokens.ts` tokens — they already point at these vars; no hardcoded hex in components.

---

## 3. Product editor — what changes vs. today

Lives under **Catalog → Products** (`/inventory/products` → new/edit). Today there's only `AddQuickProductModal` (name/sku/price/tax) + a fuller form.

**Chosen layout: Variation B — long scroll with a sticky left section nav** (`IZI POS Product Page.dc.html`, default on load). One auditable form, no second left rail competing with the dashboard sidebar; sticky nav + scrollspy + a completeness meter guide non-technical users. (Variation A / tabs is kept in the file for reference only.)

1. **Barcode-first hero (slimmed).** A compact single bar: barcode (EAN/UPC) first, then product name. Enrichment is **automatic** — debounce ~300ms on the **barcode**, falling back to **product name** when there's no barcode; the refresh icon is an optional manual re-fetch, not the trigger. Shows a small "Synerivia · N fields" status. The earlier tall hero was cut to ~64px.
2. **Sections:** General · Pricing & Tax · Inventory & Units · Media & Files · Pharmacy · Suppliers.
3. **Inventory = opening-balance model (traceability).** Only an **opening stock** (+ opening unit cost + as-of date) is editable, and only at creation. It **locks once the item has any movement**. All later changes flow through traceable operations — stock adjustment, purchase, sale, return — never a free-typed "stock on hand". Reorder point/qty, units, shelf, batch/expiry live here too.
4. **Related operations (right rail).** Shortcuts that deep-link with **this product preselected**: New stock adjustment, Start a purchase, Start a sale (B2B/quote), and View stock movements. Cleaner than navigating to each module and re-finding the item.
5. **Media gallery** = `ProductImageGallery` + `ProductMediaItem` (images, primary flag) extended to PDFs & video via `role`.
6. **Right rail also:** live POS tile preview + "before publish" checklist.
7. **Pharmacy section** = existing `ParapharmacyMetadataFields` (already complete — see §4).

Reused real components: `Sidebar`, `DashboardLayout`, `ProductImageGallery`/`ImageGalleryModal`/`ProductImageUpload`, `ParapharmacyMetadataFields`, `ProductPricingCard`, `TaxConfigurationField`, `AddQuickProductModal` (keep for in-flow quick-add).

---

## 4. Data gaps — what to add to the model

Checked against `features/products/types.ts` (`Product`) and `ParapharmacyMetadataFields`.

### Already supported — no change
- Base `Product`: `name, sku, type, is_physical, description, sale_price, purchase_price, tax_rate, unit, barcode, is_active, is_active_for_ecommerce, oem_numbers, cross_references, images[]`.
- **All pharmacy fields already exist** in `parapharmacy_metadata`: `category*, dosage_form, active_ingredients[]{name,concentration}, usage_instructions, warnings, contraindications, minimum_age, age_restriction, requires_consultation, regulatory_code, storage_requirements`. The mock's "Pharmacy" tab is just these, re-laid-out.

### New fields to add (tagged `NEW` in the mock)
| Field | Where it belongs | Type | Notes |
|-------|------------------|------|-------|
| `brand_id` | `products` → **brands** table | FK | **Decided: normalized, not free-text.** Needs a brands lookup + CRUD. Synerivia-enrichable (match/create by name). |
| `manufacturer_id` | `products` → **manufacturers** table | FK | **Decided: normalized, not free-text.** Lookup + CRUD. Synerivia-enrichable. |
| `country_of_origin` | `products` | string (ISO-3166) | Fixed enum list, not free-text. Synerivia-enrichable. |
| media `role` = `leaflet`/`video` | `ProductMediaItem.role` | enum | Role exists; add PDF/video roles + viewer. |

> **Brands & manufacturers are lookup tables** (the form fields become searchable selects with "＋ create new"), so reporting and filtering stay clean.

### Synerivia enrichment (clarified)
- The **enrichment page** already shows the **queue of unrecognized products** to enrich — keep that.
- On the product form, enrichment runs **inline**: debounce ~300ms on the **barcode** after typing stops; **fall back to product name** when there's no barcode. The refresh icon is a manual re-run, not the trigger.
- Open: confirm whether a Synerivia **match id** is persisted on the product (for re-sync/audit), or only used transiently. If not stored and you want re-sync, add a nullable `synerivia_ref`.

### Belongs to other modules (not the product table) — surface read-only / linked
| Concept in mock | Owning module |
|-----------------|---------------|
| On-hand, reorder point, reorder qty, shelf/bin | **Inventory** (per product × location) |
| Default supplier, last cost | **Purchases** (supplier↔product) |
| Loyalty points | **Loyalty** (program rules; optional per-product override) |
| Margin % | **Computed** (`sale_price` vs `purchase_price`) — don't store |
| **Live on-hand** | **Inventory ledger** — never a writable product field; derived from movements |

### Stock & traceability (decided)
- The product form writes an **opening balance only**: `opening_qty`, `opening_unit_cost`, `opening_as_of_date` → one initial `StockMovement` (type `opening`). Lock these inputs once the item has ≥1 movement.
- Per-product reorder rules (`reorder_point`, `reorder_qty`, `shelf/bin`) live in **Inventory** (per location).
- **Deep-link routes** the rail shortcuts need (open with `?productId=` preselected): stock adjustment create, purchase create, sale/quote create, and a filtered stock-movements view for the product. Confirm/define these routes.

> Money/qty fields must follow CLAUDE.md rule 19 (decimal strings, `MoneyInput`/`QuantityInput`, no `parseFloat`). `sale_price` here is **tax-inclusive (TTC)** for B2C POS.

---

## 5. Finalize → build (suggested order)

1. **Confirm the palette** (navy primary / orange secondary). Tweakable live in the design-system mock.
2. **Land the theme:** paste `izipos-theme.css`, wire `product="izipos"`, screenshot a few screens to confirm the re-skin.
3. **Layout decided: Variation B** (scroll + sticky section nav). Build the editor as one scrolling form; keep the slimmed barcode-first hero.
4. **Wire enrichment:** debounce ~300ms on barcode (fallback to name), auto-fetch (no required button); manual refresh icon optional. Confirm `features/enrichment` persists the Synerivia match id.
5. **Stock model:** implement opening-balance → initial `StockMovement`, lock-after-movement, and the deep-link routes for adjustment / purchase / sale / movements (§4).
6. **Add the new fields** (§4) — backend DTO first, then `php artisan typescript:transform` (rule 7), FormRequest + regex ceilings (rule 19), i18n keys (rule 11), module-gate pharmacy/enrichment fields (rule 12).
7. **Rebuild the editor** to the mock using `tokens.*`; keep `AddQuickProductModal` for in-flow adds. TDD + `./scripts/preflight.sh` before commit.

Open questions for you:
- ~~`brand`/`manufacturer`: free-text or normalized?~~ **Resolved: normalized lookup tables.**
- Synerivia match id persisted on the product? — confirm during integration; add nullable `synerivia_ref` if re-sync is wanted.
- Deep-link create routes (adjustment / purchase / sale) accepting a preselected `productId` — **to verify when integrating** (the rail shortcuts assume `?productId=` is honored; if not, add it to those route handlers).
