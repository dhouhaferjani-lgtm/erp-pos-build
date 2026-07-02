# Unified Imports — Parties & Products (Design)

**Date:** 2026-07-02
**Status:** DRAFT — awaiting owner review. Open assumptions are marked ⚠ ASSUMPTION.
**Depends on:** bulk-import repair set (`fix/bulk-import-repairs`, commit `a64a44aa3`) — delimiter/Excel/decimal-comma/envelope/status fixes. This design builds on the repaired engine.

## Goal

Replace the fragmented import experience (partners, then separately opening balances; products without enrichment) with **two primary imports**:

1. **Parties** — suppliers and customers *including their opening balances* in one file.
2. **Products** — one sell-ready file (barcode, quantity, purchase price, sale price, margin, tax, …), enrichment-integrated: known barcodes get enriched from the Synerivia platform, unknown ones get enriched in the background, and the user receives an Excel report of what was/wasn't enriched.

Current imports are kept but demoted to an "Advanced imports" section.

## Approach (chosen: A)

**A — new import types inside the existing Import module engine** (`app/Modules/Import`). The engine already provides: multipart upload, server-side header parsing (any CSV delimiter + XLSX/XLS), column mapping with suggestions, staged rows (`import_rows`), row-level validation errors, sync (<100 rows) / queued (`imports` queue) execution, progress broadcasting, history, failed-rows export. New flows inherit all of it.

Rejected: **B** standalone wizards (duplicates machinery, third paradigm); **C** extending the Accounting opening-balance batch subsystem into a general importer (wrong direction — it is an accounting subsystem with post/lock semantics).

## 1. Parties import (`ImportType::Parties`)

One row = one partner, with optional opening-balance columns on the same row.

| Column | Req | Notes |
|---|---|---|
| `name` | ✔ | |
| `type` | ✔ | `customer` \| `supplier` \| `both` |
| `code` |  | external/legacy id; upsert key when present (else name+type) |
| `email`, `phone`, `tax_id` |  | |
| `address_line1`, `address_city`, `address_postal_code`, `address_country` |  | |
| `opening_receivable` |  | non-negative magnitude (locked sign convention) |
| `opening_payable` |  | non-negative magnitude |
| `balance_date` |  | defaults to company opening date |
| `reference` |  | legacy doc reference for the opening entry |

⚠ ASSUMPTION (OB granularity): **one balance per partner** in v1, not invoice-level open items. Aging-detail migrations keep using the existing AR/AP open-items batch wizard under "Advanced imports".

### Execution (single job, two internal phases)

1. **Partners phase** — reuse existing `importPartner` upsert per row.
2. **Balances phase** — after partner rows commit, rows with `opening_receivable`/`opening_payable` are assembled into **auto-created opening-balance batches** (one AR, one AP per import job) using the existing Accounting services (`OpeningBalanceBatchService` → validate → post). GL entries, audit trail, batch visibility, and the lock lifecycle are unchanged — the import feeds the proven machinery.
   - Batch naming: `IMPORT-{job-short-id}-AR/AP`, linked back via job id for traceability.
   - A balance row failing batch validation/post surfaces as a normal row-level execution error on that partner's row (partner itself stays imported; error message says the balance was not posted).
   - If opening balances are already **locked** for the company, any row with balance columns fails validation with an explicit "opening balances are locked" error; partner-only rows still import.

## 2. Products import (evolved `ImportType::Products`)

| Column | Req | Notes |
|---|---|---|
| `barcode` |  | EAN-13/UPC normalized (existing normalizer) |
| `name` | ✔* | *optional when the barcode resolves via platform lookup (Phase A) |
| `sku` |  | auto-generated when empty |
| `quantity` |  | opening stock (see below) |
| `location_code` |  | defaults to company default location |
| `purchase_price` |  | also the opening-stock unit cost (WAC seed) |
| `sale_price` |  | wins over margin when both present |
| `margin` |  | % — used only when `sale_price` empty |
| `tax_rate`, `unit`, `category_name`, `brand`, `description`, `is_active` |  | existing semantics; `brand` upserts cross-vertical Brand |

- **Margin math** ⚠ ASSUMPTION: markup on cost — `sale_price = purchase_price × (1 + margin/100)`, bcmath at currency scale (+1 intermediate), rounded once at the boundary. If the margin-hierarchy slice (`feat/margin-category-override`) merges first, imported explicit sale prices set `pricing_mode=manual`; margin-derived ones may set `auto` — coordinate at implementation time.
- **Quantity = opening stock**, not a raw stock write: creates one **Opening inventory movement** per product/location via `InventoryOpeningService` (unit cost = `purchase_price`). The existing compliance rule stands: when the opening period is locked, `quantity` is rejected per-row with "stock increases must come through Purchase Orders → Goods Receipt"; the rest of the row still imports.

### Enrichment integration (phased)

**Phase A — synchronous prefill (no platform changes needed).** During validation, all barcodes in the file are resolved against platform `POST /api/v1/products/bulk-lookup` (endpoint exists; ERP client `ProductSubmissionService::bulkLookup()` exists but is currently uncalled). Chunked (e.g. 100/call), cached 1h (existing cache), circuit-breaker aware — on platform outage, import proceeds without prefill and `name` becomes required again (graceful degradation).
- Found: prefill empty `name`/`brand`/`category` (file values win over platform values; provenance flag `enrichment_source` on the row), and store the platform linkage on the product.
- Not found: row valid only if `name` provided.

**Phase B — background enrichment for unknown barcodes.** After execution, a queued job (`imports` queue) submits not-found barcodes for enrichment:
- Today: loop of existing single `POST /api/v1/products/submit` (idempotency keys, quota-aware, throttled).
- Later: swap the loop for platform `POST /api/v1/products/bulk-submit` — **this endpoint does not exist on the platform yet**; log the ask in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` and align with the existing enrichment handovers.
- Results return through the existing webhook (`enrichment.batch_resolved` fan-out already handled) → `enrichment_results` PendingReview → the normal review/accept flow. No new result plumbing.

**Result workbook (Excel).** Per job, downloadable from the wizard Done step and History: XLSX with three sheets — (1) imported & enriched, (2) imported, enrichment pending/unavailable, (3) rejected rows with reasons. Implemented as an xlsx sibling of `FailedRowsExportService` using PhpSpreadsheet (already installed). Enrichment states on sheet 1/2 reflect status at generation time; regenerating later reflects newly arrived results.

## 3. Dashboard & legacy handling

- Import dashboard: two primary cards — **Business partners** and **Products** — with the migration order hint (parties first).
- Legacy tiles (`partners` (old), opening-balance batch wizard link, `product_images`, `composite_items`, old `opening_balances` CSV type) move into a collapsed **"Advanced imports"** section. Routes and backends stay; nothing is deleted.
- Old `partners` and `opening_balances` import types remain functional for API compatibility but are no longer promoted.

## Data / schema changes

- `ImportType::Parties` enum case + validation rules + template + smart-mapping synonyms (FR + EN headers: e.g. `solde_client`, `solde_fournisseur`, `marge`, `prix_achat`, `prix_vente`, `quantité`, `code_barre`).
- `import_jobs.options` (existing jsonb) carries per-job settings: `location_code` default, enrichment on/off.
- New nullable columns on `import_rows`: none — enrichment provenance rides inside `data`/`errors` jsonb.
- Link table not needed: batch → job via batch `reference`/metadata.

## Error handling

- All row failures (partner upsert, balance post, product upsert, opening movement, margin math) are row-level `import_error`s; the job completes partially per the repaired status semantics (Failed only when 0 successes).
- Platform failures during Phase A degrade to "no prefill" and never block the import; Phase B failures are retried per existing enrichment job policies and never affect import status.

## Testing (TDD throughout)

- Unit: margin computation (bcmath, scales, edge margins), parties row splitter (partner vs balance parts), workbook generator sheets.
- Feature: parties import creates partners + posted AR/AP batches + GL entries; locked-OB rejection; products import creates products + opening movements + WAC seed; margin-derived pricing; barcode prefill (platform HTTP faked at the client boundary — no fake data in production code); background submit dispatch; result workbook contents.
- FE: wizard flows for both types (Vitest at api/queries level), dashboard card gating.
- Live Playwright pass with a French-locale Excel file for both imports before merge.

## Build order

1. **Parties unified** (no platform dependency) — BE type + batch integration + FE card + template.
2. **Products unified, sell-ready** (no platform dependency) — fields + margin + opening stock + workbook (sheets 2/3 only).
3. **Enrichment integration** — Phase A bulk-lookup prefill, Phase B background submits, workbook sheet 1, REALIGNMENT-LOG entry for `/bulk-submit`.

Each phase is independently shippable.

## Open questions for owner

1. ⚠ OB granularity: single balance per partner OK for v1? (open items stay available under Advanced)
2. ⚠ Margin semantics: markup on purchase price (`PA × (1+m%)`) vs margin on selling price (`PA / (1−m%)`)? Spec currently assumes markup on cost.
3. Enrichment default: on by default for the products import (with a toggle), or opt-in?
4. Landing: does this ride `dev` (demo-relevant) or `post-demo`?
