# Line-Item Entry Standard — Design Doc

> **Status:** Revision 2 — Codex adversarial review (`docs/superpowers/audits/2026-07-02-line-item-entry-codex-review.md`) returned **REVISE**; every finding is dispositioned in the table at the end of this doc and folded into §3.1 / §3.3 / §3.4 / §3.5 / §5 / §6. No code changes proposed here — this is the spec workers implement.
> **Author:** Design/engineering pass, 2026-07-02 (Rev 1); Rev 2 after review, 2026-07-02
> **Scope:** How products are searched, added (incl. USB barcode scanners), displayed, and priced across every product line-item table in the web ERP (invoices, quotes, orders, purchase orders, stock transfers, goods receipts, counting, supplier invoices).
> **Out of scope for the standard itself:** the Tauri POS (`apps/pos`) and web POS — they already implement the target pattern and are the reference, not a consumer.

---

## 0. TL;DR (read this first)

1. **The barcode question (owner complaint c), answered definitively:** A cashier/back-office user with a USB scanner **can scan-to-add today ONLY in the POS** (web POS `POSPage` and Tauri `apps/pos`). On **documents (invoices/quotes/POs) and stock transfers it does NOT work** — scanning types the barcode into the search field but no product is auto-found and no line is auto-added; the user must still click. Evidence in §1.3. This is the single biggest gap.
2. **The good news, stated honestly (per review finding #4):** the *shape* of every primitive is proven in the POS — `useBarcodeScanner` (window-level keyboard-wedge detection), the local-first-then-exact-API lookup pattern (`useBarcodeLookup`), qty-increment-on-rescan (`POSPage.addItemToCart`), the shared `LineItemsTable` molecule, and a backend `?barcode=` exact-match filter. **But this is not "wire the parts together."** The POS `useBarcodeLookup` is shaped around `POSProduct`, imports `fetchProductByBarcode` from POS API code, only local-matches `products.barcode/sku`, and has no in-flight/stale-result guard; `POSPage.addItemToCart` carries no variant id, batch allocation, tax/discount or availability. So we **build a new line-entry resolver using the POS hook as reference** (typed domain outcomes, variant + batch aware, serialized lookups) — not a thin wrapper. `useBarcodeScanner` itself is genuinely reusable for the window listener, but needs hardening (§3.1 B scanner-compat).
3. **The standard:** every line-item table gets a **persistent "search or scan" entry bar** above the table (not a per-row picker, not a toggle-open dropdown). One field matches name+SKU+barcode. Typed → dropdown you arrow/Enter through. Scanned → auto-resolves and auto-appends a line (qty +1 if already present). A **"Browse catalog" button** opens a multi-select picker for adding many lines at once.
4. **Pricing intel (Phase 2):** add one **bulk** endpoint `POST /line-entry/pricing-context/bulk` aggregating data we already store (no new tables) — last purchase cost (`products.last_purchase_cost`, already maintained), current WAC, last sale price to this partner, margin, and a server-computed **policy** (`MarginService::canSellAtPrice`). Bulk (not per-line) to avoid a fetch storm on a 50-line invoice. Surface it as an under-field hint + popover while entering price, with a margin-below-floor **warning that reflects the backend's real block/permission semantics** (the service already returns permission-gated `allowed:false` below cost/minimum/target — the UI must not pretend it is pure warn).

---

## 1. Current state (grounded in code)

### 1.1 The shared molecule — sound foundation
`apps/web/src/components/molecules/line-items/LineItemsTable.tsx` is a generic, headless-ish table: caller supplies `columns` (each with a `Cell`), `lines`, `getLineKey`, an `addControls` slot (rendered below the table), optional drag-reorder and `renderLineDetail`. It has **no product search of its own** — every consumer bolts its own add-UI into `addControls`. Also exports `QuantityCell`. This is a good base to build the standard on; the standard adds a sibling entry component, it does not rewrite the table.

### 1.2 Two divergent add-a-line flows (the inconsistency the owner feels)

**A. `DocumentLineEditor.tsx` (invoices, quotes, orders, POs)** — a toggle-open dropdown:
- Click **"Search products"** button → `setShowProductSearch(true)` opens a 320px dropdown (`DocumentLineEditor.tsx:571-580`).
- Dropdown has product/service tabs + an `autoFocus` text input (`:606-615`) → fires `GET /products?search=` (`:166-175`).
- Click a result row (`:646-651`) → `handleAddProduct` appends a line **and closes the dropdown** (`setShowProductSearch(false)`, `:246`).
- **To add the next product you must click "Search products" again.** No keyboard selection (the input has no `onKeyDown`), no scanner handling. There is a separate **"Add blank line"** button (`:741-748`) and a "Create new product" path.
- **Interaction count:** ~3 discrete interactions per line (open · type · click), and the panel re-collapses every time. Adding 5 products ≈ 5 open-clicks + 5 selects + typing between. This is the "a bit complicated" the owner reports.

**B. `CreateStockTransferPage.tsx` (transfers)** — a picker embedded per row:
- Each line's `product` cell renders a `<ProductPicker>` (`:503-515`); you first add a blank row, then search within that row's picker. Selecting fills **that row only** — it does not append a new row.
- `ProductPicker.tsx` is a portaled combobox: debounced 250ms server search (`:143-156`), renders SKU badge + name + sale_price (`:297-334`), keyboard Arrow/Enter/Escape (`:179-204`).

Result: two different mental models for the same task. Neither supports fast multi-line entry or scanning.

### 1.3 Barcode reality check — the definitive trace

**Backend is ready.** `ProductController` `index()` exposes two params (confirmed):
- `?search=` → case-insensitive partial `LIKE` across **name, sku, barcode** (`FiltersAndSorts` trait).
- `?barcode=` → **EXACT** match on `barcode` OR `sku` (`ProductController.php:238-245`).

**POS — works.** `POSPage.tsx`:
- `useBarcodeScanner({ onScan: lookup })` (`:313-316`) listens on `window` (capture phase), detects scanner input by inter-keystroke timing (<50ms) terminated by Enter, min length 3 (`useBarcodeScanner.ts:52-96`).
- `useBarcodeLookup` (`useBarcodeLookup.ts:56-100`): **local exact match first** (barcode or SKU against loaded grid), else `GET /products?barcode=` exact API call; resolves to single / multiple (chooser modal) / none (toast).
- Single match → `addItemToCart` → if the product line already exists, **`newQty = item.quantity + 1`** and the line total recomputes (`POSPage.tsx:154-173`) — increment, not duplicate.
- `ProductGrid` also has a manual barcode field with `onKeyDown` Enter → `onBarcodeSubmit` (`ProductGrid.tsx:86-92`).

**Documents & transfers — does NOT work (answer to complaint c):**
- `DocumentLineEditor`'s search input has **no key handler**. A scanner types the full barcode then emits Enter; nothing auto-selects. Worse, an unhandled Enter inside a `<form>` risks submitting the document. The user must visually locate and click the row. `grep barcode` over `src/features/documents` returns **zero** hits.
- `CreateStockTransferPage`'s `ProductPicker` has Arrow/Enter nav, but `activeIndex` starts at `-1` (nothing highlighted), so the scanner's Enter hits `results.find(idx === -1)` → `undefined` → **no-op**; and the 250ms debounce means results usually haven't loaded when Enter fires anyway. No scanner hook is mounted.

**Verdict:** scan-to-add is a solved problem in the POS and an unsolved problem everywhere else. The fix is to lift the POS pattern into the shared line-entry component.

### 1.4 Data we already have for pricing intel (no new tables needed)
- **Product list payload** (`ProductData::fromModel`) already carries per product: `sale_price`, `cost_price`, `purchase_price`, `tax_rate`, `barcode`, `quantity_decimals`, `primary_image_url` + `media[]`, `target_margin_override`, `minimum_margin_override`, `brand`. **Not** included: stock-on-hand (separate `GET /products/{id}/stock-levels`; `?has_stock=` filter).
- **Last purchase cost:** `StockMovement.unit_cost` on `MovementType::Receipt` rows (plus `avg_cost_before/after`).
- **Current WAC:** `products.cost_price`, maintained by `WeightedAverageCostService`.
- **Last sale price to this partner:** `document_lines` (`product_id`, `unit_price` net/HT) → `documents` (`partner_id`), order by date. B2B only — POS `SALE_RECEIPT` fiscal events are not in `documents`.
- **Margin:** `MarginService` (`calculateMargin`, `getEffectiveMargins` → target/minimum, `getSuggestedPrice` = cost×(1+target), `getMarginLevel` green/red, `canSellAtPrice`). Real-time endpoint exists: `POST /pricing/check-margin` → `{cost_price, margin_level, can_sell, suggested_price, margins}`.
- **No** `pricing-context` / price-history endpoint exists today.

### 1.5 Reusable primitives already in the tree
`useBarcodeScanner` (`src/hooks/useBarcodeScanner.ts`), the POS lookup pattern (`features/pos/hooks/useBarcodeLookup.ts`), `ProductPicker`, `LineItemsTable` + `QuantityCell`, `MoneyInput`/`QuantityInput`, `BarcodeHero` + `useCatalogBarcodeLookup` (external-catalog enrichment, not internal search — different concern). **No `ProductCell` component exists yet** (this doc defines it).

### 1.6 Legacy
`src/components/ui/ProductSearchSelect.tsx` is **still used** by `RecipeLineEditor`, `ModifierGroupFormPage`, `BatchForm` — not dead code. It is single-select, no scan. Folded into the rollout (§4), not deleted immediately.

---

## 2. Industry research — what the standard clearly is

Researched: Odoo 17/18, ERPNext, Dynamics 365 Business Central (BC), SAP Business One (B1), NetSuite, Lightspeed Retail, Square, Cin7 Core, inFlow.

1. **One smart field matching name + SKU + barcode** is where the market converges. Native in Odoo and Lightspeed X-Series; the big-3 ERPs split barcode into a separate reference column and patch with add-ons/AI. Shipping it natively beats the incumbents.
2. **A dedicated, persistent scan field above the lines table (ERPNext model)** is the robust answer to the scanner Enter-suffix problem: scan → resolve → add row or **increment existing row qty**; the field is *expected* to consume Enter, so it never collides with document save. SAP B1's cautionary tale: scanning there duplicates rows and Enter posts the whole document — users must reprogram the scanner suffix to Tab. Don't repeat that mistake. inFlow's rule is the settled convention: **re-scan increments qty; a duplicate line is an explicit action.** Handle barcode-per-UoM (SAP B1, ERPNext).
3. **A multi-select catalog picker is table stakes** (7 of 9 tools). Best-of-breed = Odoo's visual grid (thumbnail, code, price, qty-on-hand, filters, per-card qty steppers) merged with NetSuite's "type a quantity per item before commit," and inFlow's "picker stays open across selections."
4. **Keyboard-first entry is the open differentiator.** Only BC has a real design — **Quick Entry**: Enter confirms a field and jumps along a curated path that wraps to a new line; F8 copies the cell above; Ctrl+Insert/Delete add/remove lines. Retail SaaS documents none of this. Worth stealing incrementally.
5. **Inline decision support is expected:** stock available (+ on order), last cost / last price auto-fill (Cin7 auto-fills line price from the last PO to that supplier — the retail standard), margin % as an optional live column (Odoo/NetSuite).
6. **Thumbnail + matched-barcode confirmation inline per row is a universal gap** — every incumbent is text-only at the row level. A small thumbnail + matched SKU/barcode on each entered line would exceed all nine tools and directly serves error-prevention.

---

## 3. The design (one recommendation each)

### 3.1 Standardized line-entry model — `<LineItemEntryBar>` + `LineItemsTable`

**Decision: a single persistent "search or scan" entry bar rendered above the table, feeding the existing `LineItemsTable`.** Not a per-row picker (transfers model — retire it), not a toggle-open dropdown (documents model — retire it).

```
┌──────────────────────────────────────────────────────────────────────┐
│  🔍/⌫  Search or scan a product…                     [ Browse catalog ]│  ← always visible
└──────────────────────────────────────────────────────────────────────┘
      ▼ (autocomplete dropdown while typing)
```

New shared components (live under `src/components/molecules/line-items/`):
- **`LineItemEntryBar`** — the persistent search/scan input + "Browse catalog" button. Emits a **typed** add outcome (see resolver contract below), not a bare `product`.
- **`useProductLineLookup`** — a **new** line-entry resolver built with the POS `useBarcodeLookup` as reference, **not** a wrapper over it (finding #4). It calls a new server code-resolver (below), **not** `GET /products?barcode=` directly, because that filter only matches `products.barcode/sku` and is blind to variant barcodes (finding #2). It checks product AND active-variant identifiers, serializes concurrent scans, and returns a typed add outcome: `product | variant | requires_variant | batch_required | multiple | not_found`. Reused by both the scan path and the bulk picker.
- **Server code-resolver** — new endpoint `GET /line-entry/resolve-code?code={code}[&context=document|transfer&source_location_id=]` returning `{ kind: "product"|"variant"|"multiple"|"not_found", product?, variant?, matched_code_type: "product_barcode"|"product_sku"|"variant_barcode"|"variant_sku", candidates? }`. **Precedence (already the established convention — `VariantLabelService::collidesWithProductCode` documents "Spec A resolves a scanned code against product barcode/sku in Tier 1, BEFORE the variant tier"):** (1) exact `products.barcode`, (2) exact `products.sku`, (3) exact active `product_variants.barcode`, (4) exact active `product_variants.sku`, (5) if >1 candidate across tiers → `multiple`. Variant barcodes are guaranteed by `VariantLabelService` to not collide with product codes, so product-first precedence still resolves a variant's own barcode straight to that variant. Server-side lookup already exists (`EloquentProductVariantLookup::findByBarcode/findBySku`); this endpoint composes the two tiers into one typed result. The frontend never infers variant identity from `/products?barcode=`.
- **`ProductCatalogPickerDialog`** — the bulk multi-select browser (§3.1 D).
- Reuse `useBarcodeScanner` for the window-level wedge listener, **with the hardening in §3.1 B** (it is not correct as-is for AZERTY / no-Enter / in-flight scans).

The consumer keeps ownership of its line array and its `columns`; it just renders `<LineItemEntryBar>` in the `addControls` slot (or directly above the table) and appends to its lines in the `onAddProduct` handler. This preserves each surface's domain columns (variant/batch for transfers, tax/discount for documents).

#### A. Typed search (mouse or keyboard)
- Debounced (250ms) `GET /products?search=` (name+SKU+barcode). Dropdown shows `ProductCell` rows (thumbnail, name, SKU, price; stock badge when available).
- **Keyboard:** ArrowUp/Down moves highlight (default highlight = first result, so Enter is never a no-op — fixes the transfers bug); **Enter appends the highlighted product as a new line and keeps the field focused and cleared** for the next entry; Escape closes. This is the ERPNext-style loop for keyboard users.
- Focus stays in the entry bar after every add — you can add 10 products without touching the mouse.

#### B. Scan-to-add loop (USB scanner) — the fix for complaint (c)
- Mount `useBarcodeScanner({ onScan, enabled })` at the entry-bar level so a scan is captured even if the field isn't focused (matches POS ergonomics).
- On scan: `useProductLineLookup` resolves the code through the **server code-resolver** (local list first only for `product`-tier exact matches already loaded; variant tier always goes to the server since variant barcodes are not in the loaded product list). The resolver returns a **typed result** and the entry bar runs this **scan result state machine** (finding #1 / #2):

  | Resolver `kind` (+ condition) | Behavior |
  |---|---|
  | `variant` (exact active variant barcode/SKU) | Add/increment the line keyed by `{product_id, variant_id}`. Direct add — no chooser. |
  | `product` **with no active variants** | Add/increment keyed by `{product_id, null}`. |
  | `product` **that has active variants** (`requires_variant`) | **Do not append.** Open a required variant chooser and keep the scan pending; the line is only created once a variant is chosen. A parent match can never silently increment an existing line — variant identity is unknown. |
  | `batch_required` (product has `requires_batch_tracking`) | See §3.1 B-batch below — behavior differs by surface. |
  | `multiple` | Open the disambiguation chooser (reuse the POS multi-match modal) showing `ProductCell` rows with product/variant/batch context. |
  | `not_found` | Toast `productNotFound {code}`; documents/POs offer **"Create product from this barcode"** (`AddQuickProductModal` pre-filled — existing create path, barcode-seeded). |

- **Increment key is `{product_id, variant_id, batch_allocation_state}`**, not `product_id` alone — a batch-tracked line whose allocations are already set must not silently merge with a fresh scan (mirror `POSPage.addItemToCart:154-173` for the money math, but with the richer key).

##### B-batch. Batch / FEFO auto-add contract (the parapharmacy differentiator — finding #1)

This is the section the review flagged as the make-or-break gap. Batch behavior is **surface-specific** because the two surfaces have structurally different UIs. Ground truth in `CreateStockTransferPage.tsx`: a batch-tracked line is invalid at submit unless `batchAllocations.length > 0` (`:443-445`); a variant-bearing product is invalid unless `variantId` is set (`:447-452`); FEFO is built **only when the user expands the batch panel** (`BatchToggleCell.handleToggle` → `buildFefoAllocations`, `:214-218`); any quantity change **clears** allocations (`:549-551`); the whole batch column is disabled until a **source location** is chosen (`BatchToggleCell` `disabled={sourceLocationId === ''}`, `:227`). So a naive "scan → append line" produces a line that *looks* added but is unpostable — **false completion**, which for expiry-critical parapharmacy is worse than today's explicit picker.

Contract, per surface:

**Transfer surface (`CreateStockTransferPage` — `BatchToggleCell` / `BatchAllocationPanel`):**
1. **Source location must be set first.** If a scan arrives while `sourceLocationId === ''`, **reject** with toast `create.batch.selectSourceFirst` — do NOT append a line. (Matches the existing gate that disables the batch column.)
2. On a `batch_required` scan with source set: append the line **and immediately** run `buildFefoAllocations(batches, sourceLocationId, qty=1)` (the existing FEFO helper, `:69-96`) — do not wait for a manual panel-expand — **and auto-expand the `BatchAllocationPanel` (`renderLineDetail`) for that line** so the allocation is visible and editable in the same gesture. The line therefore arrives already-postable, with a review affordance, not silently.
3. **If FEFO cannot cover qty 1** (no eligible in-date stock at source): append a **blocked "needs allocation" line** flagged with `create.batch.cannotAllocate` and the panel open, rather than a green line that fails at submit. The submit-time guard (`:443-445`) stays as the backstop.
4. **Re-scan of an already-allocated batch line increments qty by 1** and **re-runs FEFO for the new total** (because a qty change clears allocations by existing design, `:549-551`) — then re-expands the panel. The increment key's `batch_allocation_state` component ensures we target the same line.
5. Auto-allocation is **FEFO-silent-with-review**: allocate immediately (fast for the common single-batch case) but always leave the editable summary visible. This is the resolution of open question #4 unless the owner overrides to "always require explicit confirmation."

**Document/PO surface (`DocumentLineEditor`):** documents do **not** have a batch-allocation UI today and are **not** in scope to grow one in Phase 1. A `batch_required` scan on a document simply appends a normal line (batch selection, if ever needed here, is a separate deferred workstream) — so batch complexity is confined to the transfer surface, which is exactly why transfers move to **Phase 1B** (§3.5).

- **Enter-suffix safety:** the scanner hook consumes Enter (capture phase, `preventDefault`) for buffered scans ≥3 chars, so Enter never reaches document save. For manually typed Enter in the field, we call the same add handler — also safe.

##### B-scanner. Scanner compatibility contract (finding #3)

The current `useBarcodeScanner` **only** completes a scan on `Enter` (`:68-80`) and accumulates raw `e.key` printable characters with **no layout normalization** (`:83-87`); `useBarcodeLookup` is **async with no in-flight guard, abort, queue, or stale-result token** (`:56-100`). Before scan-to-add ships beyond the demo document surface, the resolver + hook must satisfy:

- **In-flight / ordering (must-have for Phase 1):** serialize lookups through a FIFO **scan queue** with a monotonic **stale-result token** — a scan that resolves after a newer scan started must be discarded, never applied out of order or double-added. **Never drop a scan** fired during an in-flight lookup: enqueue it. Contract: rescans of the same code during an in-flight lookup are **coalesced into an increment applied once after resolution** (not N separate lookups).
- **Read from input value, not raw key events, for layout safety:** HID keyboard-wedge scanners emit OS scancodes; whether digits arrive verbatim depends on the scanner emulating the host layout. On **AZERTY** (Tunisia sites) a scanner in the wrong mode yields shifted/wrong digit characters from `e.key`. The design must **not depend on keyboard layout** — capture the completed token from the input's `value` (which the OS has already mapped through the active layout) rather than reconstructing it from `e.key`, and document tested paths: QWERTY, AZERTY, numpad, HID-numeric.
- **Suffix modes — configurable, not Enter-only:** support `Enter`, `Tab`, and **timeout-complete** (idle-timeout heuristic: buffer completes after ~40ms of no keystrokes even with no terminator) suffixes, selectable per tenant/device. Enter-only is the SAP B1 trap. Timeout-complete covers no-suffix scanners; make the threshold config, not a magic constant.
- **GS1 / EAN-13 embedded price/weight barcodes (prefix `2X`):** **explicit non-goal for v1** (see §5). The resolver treats them as opaque codes → they will `not_found` until a parser is added. Add a `parseEmbeddedCode` hook point before exact lookup so Phase 2+ can slot in GS1/weighted parsing with its own tests; **do not claim weighted/scale barcode support in any demo** until that parser exists and is tested.

##### B-duplicate. UoM/variant note
If the matched product has active variants and the scan resolved only the parent, the `requires_variant` path above opens the variant chooser before appending (Lightspeed "quick scan binds to variant" is the ideal, reached directly when the scanned code is a variant barcode; parent→variant prompt is the acceptable fallback).

#### C. Duplicate-scan / duplicate-add behavior
- **Default: re-scan or re-search of the same product+variant increments qty** (industry convention, POS-consistent).
- A **"+ Add as separate line"** affordance in the row's overflow menu covers the rare need for two lines of the same product (different price/discount). Explicit action, per inFlow.

#### D. Bulk "Browse catalog" picker (`ProductCatalogPickerDialog`)
- Full-height dialog: searchable/filterable grid of `ProductCell` cards (thumbnail, name, SKU, price, stock-on-hand badge, category/brand filters).
- Each card has a qty stepper; a running "Selected (N)" tray; **"Add N products"** commits all as lines in one action. Dialog **stays open** until explicitly done (inFlow) so you can browse categories and keep adding.
- This is the "easy multi-line adding" the owner asked for, for the browse-not-scan case.

#### E. Focus & keyboard summary (contract)
| Event | Behavior |
|---|---|
| Type in entry bar | debounced search, dropdown opens, first result highlighted |
| ArrowUp/Down | move highlight |
| Enter (dropdown open) | append highlighted as line; clear field; keep focus |
| Enter (empty field) | no-op (never submits the form) |
| Scan (anywhere on page) | resolve + append/increment; field stays clear |
| Escape | close dropdown |
| Tab from a line cell | move to next editable cell in the row (unchanged) |
| "Browse catalog" | open bulk picker |

*Deferred (Phase 3, BC "Quick Entry" inspiration):* F8 copy-down, Ctrl+Insert/Delete line ops. Not launch-critical.

### 3.2 Display standard — columns + `ProductCell`

**Decision: define one `ProductCell` component and one canonical column set; each surface shows the domain columns it needs at defined breakpoints.** This subsumes the previously-planned standalone ProductCell task — build it here.

**`ProductCell`** (`src/components/molecules/line-items/ProductCell.tsx`) — the product-identity cell used in line rows, dropdown results, and picker cards:
- **Thumbnail** (`primary_image_url`, 32–40px, rounded, `ImageIcon` placeholder — matches `BarcodeHero`'s 52px hero treatment scaled down).
- **Name** (primary text) + **SKU** mono badge (reuse `tokens.table.cellMonoBadge`).
- Optional **barcode** (mono, muted) — shown in the scan-confirmation context to close the industry-wide "did I scan the right thing?" gap.
- Optional **stock badge** when stock data is present.
- Tokens only (`textColors`, `borderColors`, `tokens`), i18n via `t()`, RTL-safe (`ps/pe`, `text-start`).

**Canonical column set** (a surface renders the subset it needs):

| Column | Sales docs (invoice/quote/order) | Purchase (PO/GR/supplier inv) | Stock transfer | Breakpoint |
|---|---|---|---|---|
| Product (`ProductCell`: thumb+name+SKU) | ✓ | ✓ | ✓ | always |
| Barcode | on scan-confirm | on scan-confirm | on scan-confirm | ≥lg (else in ProductCell) |
| Variant | if applicable | if applicable | ✓ | always when present |
| Description / designation | ✓ | ✓ | — | ≥md |
| Available @ source/on-hand | — | — | ✓ | ≥md |
| Qty | ✓ | ✓ | ✓ | always |
| UoM | ✓ (when multi-UoM) | ✓ | ✓ | ≥md |
| Unit price | ✓ | ✓ (cost) | — | always |
| Pricing-intel hint (§3.3) | ✓ | ✓ | — | ≥lg |
| Discount | ✓ | — | — | ≥md |
| Tax | ✓ | ✓ | — | ≥md |
| Line total | ✓ | ✓ | — | always |
| Row actions (remove, add-separate-line) | ✓ | ✓ | ✓ | always |

On narrow screens, secondary columns collapse into a stacked sub-line under the product name (the table already wraps in `overflow-x-auto`; prefer stacking over horizontal scroll for the primary fields).

### 3.3 Contextual pricing intel

**Decision: add one BULK endpoint `POST /line-entry/pricing-context/bulk` that aggregates existing data; surface it as an under-field hint + popover on the price cell, with a margin policy banner whose warn/block semantics come from the server, not the client. No new historical tables.** (Rev 2: the singular `GET /products/{id}/pricing-context` in Rev 1 contradicted its own "fetch strategy: batch" line and invited a per-line fetch storm on a 50-line invoice — finding #5.)

Request:
```jsonc
{ "partner_id": "…", "lines": [ { "product_id": "…", "variant_id": null, "unit_price": "16.250" } ] }
```
Response (all money as strings, precision-contract compliant), keyed by a stable line key (`product_id` or `product_id:variant_id`):
```jsonc
{
  "items": {
    "<product_id[:variant_id]>": {
      "currency": "TND",
      "cost_wac": "12.500000",         // products.cost_price (decimal:6 at rest)
      "last_purchase_cost": "11.900000", // products.last_purchase_cost — READ THE COLUMN, do not recompute
      "last_purchase_at": "2026-06-…",  // optional: StockMovement Receipt lookup, audit detail only
      "last_sale_to_partner": {         // document_lines⋈documents by partner_id+product_id, latest
         "unit_price": "18.000", "at": "2026-05-…", "document_no": "INV-…"
      } | null,
      "suggested_price": "16.250",      // MarginService.getSuggestedPrice (cost×(1+target))
      "target_margin_pct": "30.00",
      "minimum_margin_pct": "15.00",    // effective floor (product override → company default)
      "policy": {                        // ← from MarginService::canSellAtPrice(user, product, unit_price)
         "level": "orange",              // green|yellow|orange|red (below target|min|cost)
         "allowed": false,               // the SERVER's real answer for THIS user's permissions
         "requires_permission": "pricing.sell_below_minimum_margin" | null
      }
    }
  }
}
```
Rev 2 corrections (finding #5):
- **`last_purchase_cost` is a real column** on `products` (`decimal:6`, maintained by `WeightedAverageCostService`). Read it directly. A `StockMovement` lookup is only for `last_purchase_at` / audit detail, **not** the cost value (Rev 1 wrongly made it the source).
- **`policy` is computed server-side via `MarginService::canSellAtPrice`.** That service does **not** merely warn: below cost / below minimum / below target it returns `allowed:false` with a `requires_permission` unless the user holds `pricing.sell_below_*`. The UI must render exactly that — the client must not invent a "pure warn" that contradicts backend enforcement.
- Reuse `MarginService` for all margin fields (do not reimplement on the client). Mirror the existing `POST /pricing/bulk-prices` batching pattern.

**Surface:**
- **Under-field hint** on the unit-price cell (sales) / cost cell (purchase): e.g. `Cost 12.500 · Last buy 11.900 · Margin 30% ▸`. Muted, `helperText` token.
- **Popover** (▸) on demand: full breakdown incl. last sale to this partner and suggested price, with a **"Use suggested"** button.
- **Margin policy banner:** driven by `policy` from the response. When `policy.allowed === false`, show the price input in the error token + inline message naming `requires_permission`; when `allowed === true` at a sub-target level, show a warning token. **The block-vs-warn decision is the server's, per this user's permissions** — the UI reflects it, never overrides it. Live re-evaluation on edit reuses `POST /pricing/check-margin` (per product+price) for the single edited line; the bulk call seeds all lines on add.
- **Purchase context:** on POs/GR/supplier invoices the relevant intel is `last_purchase_cost` + `cost_wac` (Cin7's "auto-fill from last PO price" pattern) — prefill the cost field with `last_purchase_cost` when adding a purchase line, editable.

Fetch strategy: **one bulk call per batch of added lines** (and a debounced bulk refresh when `partner_id` changes), keyed by `product_id`+`partner_id`; cache per document session. Never per dropdown result, never per line. Live per-edit margin re-check is the only per-line call, and it is the already-debounced `check-margin`.

### 3.4 Rollout map — which surfaces adopt, what gets deleted

Adopt the standard, in order:
1. **`DocumentLineEditor`** (invoices, quotes, orders) — highest visibility, worst current UX. Replace the toggle dropdown with `LineItemEntryBar`; keep tax/discount columns.
2. **Purchase orders / Goods receipt / Supplier invoice lines** — same entry bar, purchase column set, purchase pricing intel (last cost prefill).
3. **`CreateStockTransferPage`** (**Phase 1B, gated**) — adopt the entry bar **only after the batch/variant scan state machine in §3.1 B / B-batch is implemented and tested.** Keep variant/batch/availability columns and the `renderLineDetail` batch panel. Until the state machine lands, either keep the existing per-row `ProductPicker` for batch/variant transfer lines, or restrict entry-bar scan-to-add to products with **no active variants and no batch tracking**. Rationale (finding #6): this is the surface with the hardest correctness constraints (source-location gate, mandatory FEFO allocations, mandatory variant selection) — a slick add followed by a submit failure is worse than the current explicit picker.
4. **Inventory counting** — entry bar (scan-heavy surface; big win).
5. **Recipe/modifier/batch editors** (`RecipeLineEditor`, `ModifierGroupFormPage`, `BatchForm`) — migrate off `ProductSearchSelect` to the shared lookup where a full line table isn't needed (may keep single-select mode of the new component).

**Deletions/retirement:**
- Retire the `DocumentLineEditor` inline search dropdown and the per-row `ProductPicker` add-model (ProductPicker can remain as an internal single-select primitive if still convenient, but not as the line-add pattern).
- **`ProductSearchSelect` (legacy)** — retire only after step 5 migrates its three consumers; until then it stays.

### 3.5 Implementation phasing (agent-hours)

**Phase 1 — Demo-critical, `DocumentLineEditor` only (target: tomorrow's demo). ~10–13 agent-hours.**
Goal: the owner sees easy search + easy multi-line adding + **working scan-to-add** on the document editor. Transfers, variant direct-add, and batch/FEFO are explicitly **out of Phase 1** (finding #6) — they are Phase 1B/2. Restated as an implementer checklist (no open questions inside it):

- [ ] **`ProductCell`** component (`src/components/molecules/line-items/ProductCell.tsx`) + tests: thumbnail, name, SKU mono badge, optional barcode, tokens/i18n/RTL-safe. (~2h)
- [ ] **Server code-resolver** `GET /line-entry/resolve-code?code=` returning the typed `{kind, product?, variant?, matched_code_type, candidates?}` with the precedence in §3.1 (product barcode → product sku → variant barcode → variant sku → multiple). Compose existing `EloquentProductVariantLookup` + product exact filter. TDD. (~2h) — *this is the one backend change Phase 1 needs; Rev 1 wrongly said "no backend change" because it planned to (incorrectly) reuse `/products?barcode=`, which is variant-blind (finding #2).*
- [ ] **`useProductLineLookup`** — new resolver built from POS `useBarcodeLookup` as reference (finding #4), calling the code-resolver, with the **FIFO scan queue + stale-result token** (finding #3) and typed outcomes. Tests required for: scan suffix (Enter/Tab/timeout), stale/out-of-order lookup ordering, product-vs-variant resolution, duplicate-increment key `{product_id, variant_id, batch_allocation_state}`, not_found. (~4h)
- [ ] **`LineItemEntryBar`** — persistent search/scan input; typed-search keyboard loop (first-result highlight, Enter appends + keeps focus, empty-field Enter no-op); scan path wired to the resolver state machine (§3.1 B) for the `product`/`requires_variant`/`multiple`/`not_found` outcomes; qty-increment on rescan; not-found create-product prompt. Mount hardened `useBarcodeScanner` (read from input value, layout-agnostic; §3.1 B-scanner). (~3h)
- [ ] Integrate into **`DocumentLineEditor`** (invoices/quotes/orders), replacing the toggle dropdown; keep tax/discount columns. Verify scan-to-add end-to-end with a real/simulated USB keyboard-wedge scanner. (~2h)
- **Explicitly excluded from Phase 1:** stock transfers, variant barcode direct-add on transfers, batch/FEFO auto-allocation, GS1/weighted barcodes, bulk catalog picker, pricing-context intel.
- **Verification gate:** in a draft invoice — scan 3 distinct product barcodes → 3 lines; rescan item 1 → qty 2; keyboard-only add of 5 products with no mouse; scan an unknown code → not-found toast with create prompt; fire 5 rapid scans back-to-back → 5 correct lines in order (proves the scan queue).

**Phase 1B — Transfers scan-to-add (gated on the batch/variant state machine). ~5–7 agent-hours.**
- [ ] Implement the **transfer scan result state machine** (§3.1 B-batch): source-location-first gate, FEFO auto-allocate + auto-expand panel on `batch_required`, blocked "needs allocation" line when FEFO can't cover, re-scan re-runs FEFO on new total, required-variant chooser. Reuse the existing `buildFefoAllocations`. (~3–4h)
- [ ] Integrate the entry bar into **`CreateStockTransferPage`** (also fixes the current `activeIndex === -1` Enter no-op); keep variant/batch/availability columns and `renderLineDetail`. (~2–3h)
- **Verification gate:** with source set, scan a batch-tracked parapharmacy item → line appears **already-allocated** (FEFO) with panel open and passes submit; scan with no source set → "select source first", no line added; scan a variant barcode → variant line direct; scan a parent-with-variants → variant chooser, no line until chosen.

**Phase 2 — Pricing intel + scanner robustness. ~10–12 agent-hours.**
- Backend `POST /line-entry/pricing-context/bulk` (TDD; read `products.last_purchase_cost` + `cost_price`, `document_lines⋈documents` for last sale, `MarginService::canSellAtPrice` for `policy`). (~4h)
- `typescript:transform` types + under-field hint + popover + server-driven margin policy banner + "Use suggested" + purchase last-cost prefill. (~4–5h)
- Scanner-compat hardening beyond the Phase 1 queue: per-tenant/device suffix config (Enter/Tab/timeout), documented AZERTY/numpad/HID test matrix, and the `parseEmbeddedCode` hook point (GS1/EAN-13 weighted parser itself stays a Phase 2+ opt-in — see §5). (~2–3h)

**Phase 3 — Full rollout + cleanup + power keyboard. ~10–12 agent-hours.**
- Roll the entry bar into PO/GR/supplier-invoice and counting (purchase column set + last-cost prefill). (~4h)
- `ProductCatalogPickerDialog` bulk multi-select. (~4h)
- Migrate `ProductSearchSelect` consumers; delete legacy. (~2h)
- Optional BC-style Quick-Entry keys (F8 copy-down, Ctrl+Insert/Delete). (~2h)

---

## 4. ASCII mockup — standardized document line table

```
 Line items
┌───────────────────────────────────────────────────────────────────────────────────────┐
│ 🔍 Search or scan a product…                                          [ Browse catalog ] │
│    ┌─────────────────────────────────────────────────────────────┐                      │
│    │ [img] Crème solaire SPF50   SKU CS-050   6194000123456  ·12.5 │  ← highlighted      │
│    │ [img] Crème mains karité    SKU CM-KAR   6194000998877  · 8.0 │                      │
│    └─────────────────────────────────────────────────────────────┘                      │
├──────┬───────────────────────────────┬──────┬─────────┬───────────┬──────┬──────┬───────┤
│      │ Product                        │  Qty │  Unit € │  Discount │ Tax  │ Total│       │
├──────┼───────────────────────────────┼──────┼─────────┼───────────┼──────┼──────┼───────┤
│ ⠿    │ [img] Crème solaire SPF50      │  2   │ 16.250  │    0 %    │ 19%  │38.68 │  🗑    │
│      │       CS-050 · 6194000123456   │      │ ⓘ Cost 12.500 · Last buy 11.900 · 30% ▸  │
├──────┼───────────────────────────────┼──────┼─────────┼───────────┼──────┼──────┼───────┤
│ ⠿    │ [img] Crème mains karité       │  1   │  8.000  │    0 %    │ 19%  │ 9.52 │  🗑    │
│      │       CM-KAR · 6194000998877   │      │ ⚠ Margin 8% below floor 15%              │
├──────┴───────────────────────────────┴──────┴─────────┴───────────┴──────┴──────┴───────┤
│                                                        Subtotal  40.00   Tax 8.20        │
│                                                        Total    48.20                    │
└─────────────────────────────────────────────────────────────────────────────────────────┘
   ⠿ drag to reorder   ⓘ pricing popover   ⚠ margin-below-floor warning
```

Scan flow: cashier scans `6194000123456` → row 1 appears; scans it again → qty becomes 2; scans an unknown code → toast "Product not found — create it?". No mouse required.

---

## 5. Explicit non-goals

- **Not** re-architecting `LineItemsTable` — the standard is a sibling entry component + `ProductCell`, reusing the existing table.
- **Not** touching the POS (web or Tauri) — it is the reference implementation, not a consumer.
- **Not** creating any new historical/price-history tables — pricing intel is aggregated from existing `StockMovement`, `products.cost_price`, `document_lines`, `MarginService`.
- **Not** capturing B2C POS sale history in `last_sale_to_partner` (POS sales are fiscal `SALE_RECEIPT` events, not `documents`) — B2B document history only, in v1.
- **Not** building offline scan for web documents — web ERP is online; offline scanning stays a POS concern.
- **Not** designing full WMS/warehouse mobile scanning workflows.
- **Not** deleting `ProductSearchSelect` before its three consumers are migrated (Phase 3).
- **GS1 / EAN-13 embedded price/weight barcodes (prefix `2X`) are an explicit NON-GOAL for v1** (finding #3). They resolve as `not_found` until a Phase 2+ parser is added at the `parseEmbeddedCode` hook point. **No demo may claim weighted/scale-barcode support** before that parser exists and is tested.
- **Not** deciding the block-vs-warn margin policy in the client — the server (`MarginService::canSellAtPrice`) decides per user permission (below cost/minimum/target each return `allowed:false` + a `requires_permission` unless the user holds `pricing.sell_below_*`); the UI reflects it, never overrides it. The remaining *business* choice (which permissions to grant which roles) is for the owner (§6).
- **Not** growing a batch-allocation UI on `DocumentLineEditor` — batch/FEFO auto-add is a **transfer-surface** contract only (§3.1 B-batch); documents append batch-tracked products as plain lines.

---

## 6. Open questions for the owner (answer once, then build)

1. Margin policy: the backend already enforces per-permission block below cost/minimum/target. Which **roles** should hold `pricing.sell_below_minimum_margin` / `_below_cost` / `_below_target_margin`? (This is the only remaining margin decision — the block-vs-warn mechanism is fixed by the server, per §5.)
2. Bulk catalog picker: needed for the demo, or Phase 3 is fine (scan + keyboard search cover the demo)?
3. Variant-on-scan: acceptable to prompt for variant when a scanned *parent* barcode has variants (v1)? (A variant's own barcode already resolves directly — this is only about parent scans.)
4. **Transfer batch scan behavior:** should batch-tracked products **auto-allocate FEFO on scan** with an editable review panel (the §3.1 B-batch default), or should a scan **open the batch panel and require explicit confirmation** before the line counts as allocated?
5. **Scanner estate (Tunisia sites):** what **suffix** (Enter / Tab / none-timeout) and **keyboard layout** (AZERTY keyboard-wedge vs HID-numeric) are actually deployed? This sets which §3.1 B-scanner modes must be tested for the launch, and whether timeout-complete is required for v1.

---

## Revision 2 — Codex review disposition

Review: `docs/superpowers/audits/2026-07-02-line-item-entry-codex-review.md` (verdict **REVISE**). All findings verified against code before dispositioning.

| # | Finding (severity) | Disposition | Change made |
|---|---|---|---|
| 1 | Batch/FEFO + variant scan auto-add underspecified for transfer rollout (Critical) | **Accepted** | Added §3.1 **B (scan result state machine)** + **B-batch (transfer FEFO auto-add contract)**: source-location-first gate, FEFO auto-allocate + auto-expand panel, blocked "needs allocation" line, re-scan re-runs FEFO, required-variant chooser. Verified against `CreateStockTransferPage.tsx:214-218,443-452,549-551` and the `sourceLocationId===''` disable gate. |
| 2 | Lookup contract ignores existing variant-barcode infrastructure (High) | **Accepted** | Replaced the `useProductLineLookup`/`/products?barcode=` plan with a typed **server code-resolver** `GET /line-entry/resolve-code` (product barcode → sku → variant barcode → sku → multiple). Verified: `product_variants.barcode` unique index (migration `:37-41`), `EloquentProductVariantLookup::findByBarcode/findBySku`, and `VariantLabelService` documenting the exact product-first precedence. |
| 3 | Scan-loop hardening missing: in-flight, no-Enter, AZERTY, GS1 (High) | **Accepted** | Added §3.1 **B-scanner** contract: FIFO scan queue + stale-result token (Phase 1), read-from-input-value layout-agnostic capture (AZERTY-safe), Enter/Tab/timeout suffix modes, `parseEmbeddedCode` hook with GS1/EAN-13 as an explicit v1 non-goal (§5). Verified `useBarcodeScanner.ts:68-87` (Enter-only, raw `e.key`) and `useBarcodeLookup.ts:56-100` (no in-flight guard). |
| 4 | POS primitives useful but not "already proven" for this surface (Medium-High) | **Accepted** | Corrected TL;DR #2 and the components list: `useProductLineLookup` is a **new resolver built with the POS hook as reference**, not a wrapper; enumerated required tests (suffix, stale ordering, product-vs-variant, increment key, transfer preconditions). Verified `useBarcodeLookup.ts:1-24,62-67` POS-coupling. |
| 5 | Pricing-context needs a bulk API + stricter margin-policy contract (Medium) | **Accepted** | Replaced singular `GET …/pricing-context` with **`POST /line-entry/pricing-context/bulk`**; `policy` now comes from `MarginService::canSellAtPrice` server-side (not client warn/block). Corrected cost source to `products.last_purchase_cost` (real `decimal:6` column). Verified `MarginService.php:320-347`, `Product.php` column + cast, existing `POST /pricing/bulk-prices`. |
| 6 | Phase 1 scope too broad; transfers are the risky cut (Medium) | **Accepted** | Split into **Phase 1 (`DocumentLineEditor` only)** restated as an executable checklist, and **Phase 1B (transfers, gated on the state machine)**. §3.4 step 3 re-gated. Noted Phase 1 *does* need one backend change (the code-resolver) — correcting Rev 1's "no backend change." |
| 7 | Add two owner decisions (transfer batch behavior, scanner estate) (doc change) | **Accepted** | Added §6 questions 4 (FEFO auto-allocate vs explicit confirm) and 5 (Tunisia scanner suffix/layout). Reframed the margin question to the real remaining decision (role→permission grants), since the mechanism is server-fixed. |

No findings rejected. One clarification beyond the review: the resolver precedence is not a new invention — `VariantLabelService::collidesWithProductCode` already documents "Spec A resolves a scanned code against product barcode/sku in Tier 1, BEFORE the variant tier," so product-first-then-variant is the established convention and safely resolves a variant's own (guaranteed non-colliding) barcode straight to the variant.
