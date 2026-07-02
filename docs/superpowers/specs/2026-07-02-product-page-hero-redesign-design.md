# Product Page Hero Redesign — Design Spec

> **Date:** 2026-07-02 · **Author:** design session (code-grounded) · **Status:** decision-ready
> **Scope:** izipos product EDIT page hero (`BarcodeHero` + surrounding `ProductForm` composition) + read-only VIEW variant (`ProductDetailPage` Details tab). No code in this doc.
> **Feeds:** tonight's Codex implementation handover (`docs/handoff/CODEX-demo-product-ui-chunk.md`, Task 2) — see §9 for exact deltas.

---

## 1. Current state (cited)

- **Hero** — `apps/web/src/features/products/editor/components/BarcodeHero.tsx`: dark navy band, single flex row. Image placeholder is **52×52px, decorative, `aria-hidden`, no upload affordance** (lines 102–107). Then barcode input `w-60` with scanner-aware lookup via `useCatalogBarcodeLookup` (lines 87–92, 110–126), name input `flex-1` (129–141), enrichment pill (145–170), 38×38 refresh button (173–183), helper line (186–188). Props at lines 59–71 include `status?: EnrichmentStatus`, `onManualRefresh?`.
- **Composition** — `apps/web/src/features/inventory/ProductForm.tsx`: hero rendered at lines 683–691 (outside the `<form>`); **`status` prop is never passed → the enrichment pill is dead today**; only refresh is wired (`handleManualRefresh` 530–535, `useEnrichmentRefresh` at 162). Three-column grid `lg:grid-cols-[188px_minmax(0,1fr)_300px]` (line 725): `SectionNav` left (730–737), hardcoded `EditorSectionCard` stack centre (740–1354), right rail `LivePosTile`/`BeforePublishChecklist`/`RelatedOperationsRail` (1358–1362).
- **Section config is HALF data-driven** — `sectionDefs: Array<{id, labelKey}>` (ProductForm.tsx:584–596) drives only the nav rail + scroll-spy; card JSX is hand-placed and gating (`isParapharmacy`, `hasModule('Loyalty')`) is duplicated between the array and the JSX. `section-opening` (1057–1171), Automotive (1176–1268) and Variants (1332–1353) render with **no nav entry**. `SectionNav` is purely presentational (`EditorSection {id, label}`, SectionNav.tsx:11–26). `EditorSectionCard` props `{id, title, children, contentClassName?}` (EditorSectionCard.tsx:5–14).
- **Completeness** — 4 hardcoded checklist items (name/sku/salePrice/tax) in ProductForm.tsx:572–579 feed both `CompletenessMeter` (percent-only, CompletenessMeter.tsx:5–7) and `BeforePublishChecklist`.
- **Opening balance (already built — REUSE, do not duplicate)** — RHF fields `opening_quantity`/`opening_unit_cost`/`opening_date` (ProductForm.tsx:96–101); gate `showOpeningSection = !isEditing || (product?.movement_count ?? 0) === 0` (line 555); editable/locked branches at 1057–1171; post-save `submitOpening` (432–471) → `POST /products/{id}/opening-balance` (`openingBalanceApi.ts:10–24`; backend `InventoryOpeningService::createOpeningBalance`, InventoryOpeningService.php:38–52, conflict guard 61–68 → 409 `movement_exists`). Locked display already shows `stock_quantity` + `cost_price` (WAC) + adjust link. `movement_count` comes from `ProductResource` `withCount`.
- **Pricing today** — editor has **no bidirectional calc and no HT/TTC pair**: `purchase_price` + `sale_price` MoneyInputs (ProductForm.tsx:862–897; `sale_price` is stored **TTC**), read-only indicative margin computed **on sale price** with bc* string helpers (548–569), read-only WAC (916–931), `TaxConfigurationField` sets `tax_configuration_id` + `tax_rate` (933–942) but `tax_rate` is **never used for price math** and is stripped from the payload (`productPayload.ts:7–12,35`). Backend `MarginService::calculateMargin` = `(sell − cost) / cost` — **margin off COST** (MarginService.php:214–230). The bidirectional `components/molecules/PriceInputWithMargin` is dead code, float-based, margin-on-price — do not resurrect. `features/inventory/components/pricing/PriceInputWithMargin.tsx` is server-check-driven, not bidirectional, and has parseFloat violations — not a base either.
- **Enrichment writes** — accept flow writes ONLY `name`, `description`, `barcode`, `brand_id`+`brand_source=Enriched` (`EnrichmentReviewService.php:75–145`, match at 96–101). **Category, images, classification, ingredients are stored in `enrichment_results.enriched_data` but never applied. Parapharmacy merchandising (skin types, routines, equivalents, complements — `ParapharmacyProductMetadata.php`) is a separate subsystem enrichment never touches.** Status = `products.enrichment_status` (`Shared/Enums/EnrichmentStatus.php:7–14`: pending/enriching/completed/failed/rejected/not_enrichable; `null` = never submitted) + review state `EnrichmentReviewStatus` (pending_review/accepted/rejected). Manual refresh confirmed: `POST /products/{productId}/enrichment/refresh` (Product/routes.php:126–128 → `EnrichmentRefreshController.php:20–52`; 422 `no_pending_submission`, 502 `platform_unavailable`).
- **Media** — primary image = `MediaAttachment.role === MediaRole::Primary` (partial unique index; `ProductMediaController.php:104–118, 355`), served via short-lived signed URL (`MediaUrlResolver.php:52–89`) on `GET /media/{tenant}/{attachment}/serve`. Renditions: THUMBNAIL 150 / SMALL 400 / WEB 1000 / ZOOM=original (`RenditionService.php:33–37`); serve route accepts only `?variant=sm|md` (sm→THUMBNAIL, md→SMALL — `MediaStorageAdapter.php:40–43`); the default `url` in `formatOne` (no variant) serves the **original**. Display: `ProductPrimaryImageDisplay.tsx` (`{productId}` prop, primary pick at 37–40). Upload: `ProductImageUpload.tsx` → `POST /products/{id}/images` (first upload auto-Primary); create-mode buffer exists (`CreateModeImageBuffer.tsx`).

---

## 2. Hero layout (edit mode)

Keep the dark band and its language (mono barcode, navy inset inputs). Replace the 52px placeholder with a real **176×176 image slot** and stack the right column. The band grows from one row (~64px) to ~200px — acceptable: it now carries identity + enrichment + the sell-readiness strip, i.e. the whole "can I sell this?" answer above the fold.

```
┌────────────────────────────────────────────────────────────────────────────────┐
│  DARK HERO BAND                                                                │
│ ┌───────────────┐  Nom du produit ________________________________  [state]    │
│ │               │                                                              │
│ │   PRODUCT     │  ▐barcode▌ 3401399999999_____  [scan●] [⟳ refresh]           │
│ │    IMAGE      │                                                              │
│ │   176×176     │  ┌ enrichment ────────────────────────────────────────────┐  │
│ │  (md/400px    │  │ ◉ Enrichi · Synerivia   [Marque: Avène ✦] [Cat.: Soin] │  │
│ │   rendition)  │  │ [Peau sensible] [+2 équivalents] [Routine: 3 étapes]   │  │
│ │ [📷 replace]  │  └────────────────────────────────────────────────────────┘  │
│ └───────────────┘  helper: scannez ou saisissez un code-barres…                │
├────────────────────────────────────────────────────────────────────────────────┤
│  READY-TO-SELL STRIP (light surface, same card, 5 cells)                       │
│  Qté initiale   Coût (HT)     Marge %      Prix de vente HT   Prix de vente TTC│
│  [   24    ]    [  8.500  ]   [ 41.2 % ]   [  12.000  ]       [  14.280  ]     │
│  (locked mode:  En stock 24 · CMP 8.500 → « Ajuster le stock »)                │
└────────────────────────────────────────────────────────────────────────────────┘
```

**Image slot (left, 176px square, `rounded-[12px]`):**
- Reuses the existing media pipeline: primary attachment `url` + **`?variant=md`** (SMALL/400px WebP — sharp at 176px, avoids shipping the original). FE appends the query param; no backend change needed since `md` is already accepted (`SignedMediaController.php:73`).
- Edit mode: hover/focus overlay "replace photo" → opens the existing `ProductImageUpload` flow (`POST /products/{id}/images`; if a primary already exists, upload then `PATCH .../images/{id} {is_primary:true}`). Create mode: `CreateModeImageBuffer`.
- Fallback: current dashed/`ImageIcon` placeholder styling scaled up, plus a one-line "add photo" affordance. Never `aria-hidden` anymore — it's now content (`alt` = product name).

**Right column (top → bottom), each block a descriptor (§4):**
1. **Name input** — unchanged binding (`setValue('name', …, {shouldDirty:true})`), promoted to first row, larger type (17px semibold).
2. **Barcode row** — existing barcode input + `useCatalogBarcodeLookup` wiring verbatim; refresh button stays; scan state dot.
3. **Enrichment block** — status chip + data chips (§5). Chips are read-only labels, not inputs; brand/category remain edited in the General section.
4. **Helper line** — unchanged.

**Ready-to-sell strip** sits in the same visual card, on the light surface directly under the dark band (not a separate scroll section). Five cells (§3). On `sm` screens the strip wraps 2+3; the image drops to 96px above the stacked column.

---

## 3. Ready-to-sell strip — fields, math, terminology

**Terminology (DECIDED, do not revisit):** "Prix de vente HT" / "Prix de vente TTC" (EN "Sale price (excl. tax)" / "Sale price (incl. tax)"). No "gross price" anywhere.

| Cell | Binding | Notes |
|---|---|---|
| Qté initiale | RHF `opening_quantity` (`QuantityInput`, existing `quantity_step`) | REUSED field, moved up. `opening_date` + `opening_unit_cost` override stay reachable via a small "détails d'ouverture" disclosure in the strip (keeps 5-cell density; defaults unchanged: date=today, cost=purchase_price — server already defaults blank cost, InventoryOpeningService). |
| Coût (HT) | RHF `purchase_price` (`MoneyInput`) | Same field as the Pricing section (single RHF source → both render in sync for free). Edit mode with WAC present: cell shows WAC read-only (see lock table §6). |
| Marge % | NEW derived input (percent, 2dp regex) | **Margin off cost (markup), matching `MarginService::calculateMargin`:** `margin = (HT − cost) / cost × 100`. Bc* string helpers only (`bcsub/bcdiv/bcmul` per ProductForm.tsx:561–563 pattern); never parseFloat (rule 19). |
| Prix de vente HT | NEW derived input (`MoneyInput`) | `HT = TTC / (1 + tax_rate/100)`. **Not stored** — `sale_price` remains the stored TTC value; no schema/payload change. First FE use of `tax_rate` for math. |
| Prix de vente TTC | RHF `sale_price` (`MoneyInput`) | Stored field, unchanged contract. |

**Bidirectionality (last-edited wins, others recompute):**
- edit **TTC** → HT = TTC/(1+r); margin from HT vs cost
- edit **HT** → TTC = HT×(1+r) (rounded once at currency scale via `bcformat`); margin recomputes
- edit **margin** → HT = cost×(1+m/100); TTC = HT×(1+r)
- edit **cost** → margin recomputes (HT/TTC hold — price is the commitment, cost is a fact)
- **No tax config selected:** treat r=0 (HT≡TTC), show inline hint "sélectionnez une TVA" chip on the TTC cell; the pair still works.
- Intermediates at scale+1, single rounding at the boundary (precision contract, CLAUDE.md rule 19).
- Replaces the read-only "indicative margin" display (ProductForm.tsx:899–914) — that block and its tax-mixing caveat disappear; the new margin is tax-exact.

**Margin-hierarchy note (one line):** when `feat/margin-category-override` (MarginResolver: pricing_mode auto|manual, category→company cascade, provenance) merges, the margin cell gains a provenance chip + "auto" state; the strip's descriptor slot (§4) reserves `pricing.margin` id for it — no layout change.

---

## 4. Config-array layout system (cheap rearrangement — DECIDED approach)

**Rejected:** any drag-and-drop layout builder / user-facing customization. Rearrangement is a **developer data change**, not a product feature.

Close the existing half-data-driven gap: today `sectionDefs` (ProductForm.tsx:584–596) orders the nav but cards are hand-placed JSX with duplicated gating. Extend to one source of truth driving nav + cards + hero blocks:

```ts
// types (packages-local to the editor feature; illustrative)
interface EditorSectionDef {
  id: string                      // DOM id ('section-pricing')
  labelKey: string                // i18n key for nav + card title
  component: EditorSectionKey     // key into a static renderer registry
  when?: (ctx: EditorGateCtx) => boolean  // ONE gating spot (isParapharmacy, hasModule, isEditing…)
  navVisible?: boolean            // default true; opening/variants can be false
}
interface HeroBlockDef {
  id: string                      // 'identity.name' | 'identity.barcode' | 'enrichment.chips' | …
  component: HeroBlockKey         // registry key
  slot: 'image' | 'main' | 'strip'  // hero grid slot
  when?: (ctx: EditorGateCtx) => boolean
}
```

- **Registries are static maps** `{ [key]: React.FC<EditorBlockProps> }`; blocks receive the shared form context (RHF) + product + gates — no prop drilling per reorder.
- Reordering/hiding = editing the two arrays (`EDITOR_SECTIONS`, `HERO_BLOCKS`). Nav, scroll-spy, and the rendered card stack all derive from `EDITOR_SECTIONS` — fixing the current mismatch where `section-opening`/Automotive/Variants have cards but no nav entries (they get `navVisible:false` explicitly).
- The strip's five cells are themselves `slot:'strip'` blocks (`stock.openingQty`, `pricing.cost`, `pricing.margin`, `pricing.priceHt`, `pricing.priceTtc`) so post-launch experiments (drop margin, add stock-by-branch) are one-line edits.
- View page (§7) consumes the same descriptor shape with a `mode: 'view'` context — read-only renderers registered under the same keys.

---

## 5. Enrichment in the hero

**Status source (backend truth, not the current UI fiction):** map `products.enrichment_status` (+ `latestEnrichmentResult.status`, relation Product.php:378–381 — needs exposing on the product payload or a light query) to four hero states:

| Hero state | Backend condition |
|---|---|
| never-submitted | `enrichment_status === null` |
| pending | `pending` \| `enriching` |
| ready-for-review | `completed` && latest result `pending_review` |
| enriched | latest result `accepted` (status cleared to null on accept — use `brand_source === 'enriched'` or accepted result as signal) |
| unavailable (muted) | `failed` \| `rejected` \| `not_enrichable` |

**Chips (right column, block `enrichment.chips`):**
- **Status chip** always first (per table above). ready-for-review chip links to the review queue (`/enrichment`, `can:enrichment.review`).
- **Brand chip** — `product.brand` name; ✦ affix when `brand_source === 'enriched'` (BrandSource.php:12–13).
- **Category chip** — `product.category` name. *User data, not enrichment* (accept flow never writes category) — no ✦ ever.
- **Merchandising chips** — gated `hasModule('Parapharmacy')` (`CompanyConfigContext.tsx:64–75`): skin types, "+N équivalents", "+N compléments", "Routine · N étapes" from `parapharmacyMetadata` relations. **These are NOT enrichment data** (verified: enrichment writes only name/description/barcode/brand) — they summarize the Pharmacy section; clicking scrolls to `section-pharmacy`. If the metadata relation isn't on the editor payload yet, chips ship in the view page first (it has the detail payload) and the editor gets them when the payload is extended (Phase 2).
- **Refresh action** — existing endpoint; NEW handling: 422 `no_pending_submission` → info toast "rien à actualiser"; 502 → error toast; success → invalidate product + enrichment queries (missing today, platformQueries.ts:26–36) so chips update live.

---

## 6. Behavior tables

**Enrichment state × hero:**

| State | Status chip | Data chips | Refresh btn |
|---|---|---|---|
| never-submitted | none (opt-in checkbox flow unchanged, ProductForm.tsx:695–708) | brand/cat if user-set | hidden |
| pending / enriching | spinner "Enrichissement…" | user-set only | enabled |
| ready-for-review | amber "À valider →" (links queue) | user-set only (enriched values NOT shown until accepted) | enabled |
| enriched | green "Enrichi · Synerivia" | brand ✦ + others | enabled |
| failed / not_enrichable / rejected | muted "Non disponible" | user-set only | enabled (retry) |

**Opening lock × strip** (gate = existing `movement_count === 0`, ProductForm.tsx:555; authoritative 409 guard server-side):

| Mode | Qté cell | Coût cell | Margin/HT/TTC |
|---|---|---|---|
| create | editable `opening_quantity` (+ disclosure: date, unit-cost override) | editable `purchase_price` | fully bidirectional |
| edit, movement_count = 0 | same as create | same | same |
| edit, movements exist (LOCKED) | read-only **"En stock: {stock_quantity}"** + "Ajuster le stock" link (existing `/inventory/counts` affordance, ProductForm.tsx:1142–1170) | read-only **"CMP: {cost_price}"** (WAC); purchase_price editable only in Pricing section | still bidirectional, margin computed off **WAC** |
| opening submit fails post-save | existing behavior kept: product saved, warning toast, retry on next edit (ProductForm.tsx:432–471) | — | — |

The old `section-opening` card is **removed from the centre stack** (its fields now live in the strip); its descriptor is deleted, not hidden — one mechanism, one place.

---

## 7. View page variant (read-only hero)

Same geometry, applied to `ProductDetailPage.tsx` Details tab (Codex Task 2 target). Sibling component `ProductHero` (editor components are reuse-only per the handover):
- Image 176px via `ProductPrimaryImageDisplay` logic (primary-pick, ProductPrimaryImageDisplay.tsx:37–40) with `?variant=md`; magnifier → existing `ImageGalleryModal`; no upload overlay.
- Right column: name as `<h1>`-adjacent text, barcode as mono text + copy button (no input, no scan), SKU + status badge, enrichment/brand/category/merch chips identical to §5 (view page already loads full product + metadata).
- Strip = read-only value row: `En stock` (stock_quantity) · `CMP` (cost_price) · `Marge %` (computed, off WAC, tax-exact) · `Prix de vente HT` (derived) · `Prix de vente TTC` (sale_price). Same bc* derivation, rendered with `formatCurrency`/`formatQuantity`.
- Sections below use the same `EditorSectionDef[]`-shaped array with view renderers (§4).

---

## 8. i18n keys (namespace `inventory` unless noted; add to en/fr/ar)

| Key | FR | EN | AR |
|---|---|---|---|
| `products.priceHt` | Prix de vente HT | Sale price (excl. tax) | سعر البيع (دون احتساب الضريبة) |
| `products.priceTtc` | Prix de vente TTC | Sale price (incl. tax) | سعر البيع (شامل الضريبة) |
| `products.marginPercent` | Marge % | Margin % | هامش الربح ٪ |
| `products.costHt` | Coût (HT) | Cost (excl. tax) | التكلفة (دون ضريبة) |
| `products.readyToSell` | Prêt à vendre | Ready to sell | جاهز للبيع |
| `products.openingQtyShort` | Qté initiale | Initial qty | الكمية الأولية |
| `products.openingDetails` | Détails d'ouverture | Opening details | تفاصيل الرصيد الافتتاحي |
| `products.onHandShort` | En stock | On hand | في المخزون |
| `products.selectTaxHint` | Sélectionnez une TVA | Select a tax rate | اختر نسبة الضريبة |
| `catalog:editor.hero.replacePhoto` | Remplacer la photo | Replace photo | استبدال الصورة |
| `catalog:editor.hero.addPhoto` | Ajouter une photo | Add photo | إضافة صورة |
| `catalog:editor.hero.statusPending` | Enrichissement… | Enriching… | جارٍ الإثراء… |
| `catalog:editor.hero.statusReview` | À valider | Ready for review | بانتظار المراجعة |
| `catalog:editor.hero.statusEnriched` | Enrichi · Synerivia | Enriched · Synerivia | مُثرى · Synerivia |
| `catalog:editor.hero.statusUnavailable` | Non disponible | Unavailable | غير متوفر |
| `catalog:editor.hero.refreshNothing` | Rien à actualiser | Nothing to refresh | لا يوجد ما يتم تحديثه |
| `catalog:editor.hero.equivalentsCount` | +{{count}} équivalents | +{{count}} equivalents | +{{count}} مكافئات |
| `catalog:editor.hero.complementsCount` | +{{count}} compléments | +{{count}} complements | +{{count}} مكمّلات |
| `catalog:editor.hero.routineSteps` | Routine · {{count}} étapes | Routine · {{count}} steps | روتين · {{count}} خطوات |

Existing keys reused: `products.openingStock`, `products.openingLocked`, `products.adjustStock`, `products.costWac`, `editor.hero.*` lookup strings. RTL: chips row and strip must use logical properties (`ps-`/`pe-`, `text-start`) per i18n context doc.

---

## 9. Phasing + deltas to Codex handover Task 2

**Tonight's Codex chunk (Task 2 in `CODEX-demo-product-ui-chunk.md` — VIEW page only; `ProductForm.tsx` stays forbidden):**
- Build `ProductHero` (read-only, §7) with the §2 geometry: 176px `md`-variant image, name/barcode/SKU/status stack, enrichment + brand/category + Parapharmacy-gated merch chips, read-only 5-value strip (on-hand · CMP · marge % · HT · TTC, bc* derivation, HT/TTC terminology).
- Define the descriptor types + registry (§4) in `features/products/editor/` and drive the view page's section stack from an array — this is the shared foundation the editor adopts next.

**Exact deltas to Task 2 text:**
1. Replace "Read-only hero (image, name, barcode, SKU, status, key prices)" → "Read-only `ProductHero` per §2/§7 of `2026-07-02-product-page-hero-redesign-design.md`: 176px primary image (`?variant=md` on the signed URL), name/barcode/SKU/status, enrichment status + brand/category chips (+ Parapharmacy merch chips), and the 5-value ready-to-sell strip using 'Prix de vente HT'/'Prix de vente TTC' labels — margin off cost, HT derived from stored TTC `sale_price` via `tax_rate`."
2. Add: "Section stack must be driven by an ordered `EditorSectionDef[]` config array + renderer registry (spec §4) — no hand-placed card order. No drag-drop builder."
3. Add: "New i18n keys per spec §8 (fr/en/ar, all three files + logical RTL properties)."
4. Add: "Do NOT render enriched-but-unaccepted values as product data (spec §6 table); category chip never gets the enriched affix."
5. Unchanged: route/tabs untouched, Edit button, no merge/push, gates.

**Phase 2 (separate session — owns `ProductForm.tsx`):** edit-mode hero rebuild — image slot + upload overlay, wire real enrichment status (expose `enrichment_status`/latest result on editor payload + query invalidation on refresh), move opening fields into the strip and delete `section-opening` card, bidirectional margin/HT/TTC cells, migrate `sectionDefs` → full `EDITOR_SECTIONS`/`HERO_BLOCKS` arrays.
**Phase 3 (post-merge of `feat/margin-category-override`):** margin provenance chip + auto/manual pricing_mode in the margin cell. **Later/optional:** backend emits per-variant URLs in `ProductMediaController::formatOne` so clients stop appending query params; completeness items become descriptor-derived.
