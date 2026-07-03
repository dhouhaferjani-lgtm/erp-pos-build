# Unified Imports — Parties & Products (Design)

**Date:** 2026-07-02 (finalized 2026-07-03 with owner answers + industry research; v2 same day after Codex adversarial review — contract-repair pass)
**Status:** FINAL v3 — owner decisions incorporated; Codex round-1 findings dispositioned (Appendix B); round-2 consistency findings fixed (Appendix B addendum).
**Depends on:** bulk-import repair set (`fix/bulk-import-repairs`, merged origin/dev 2026-07-03) — delimiter/Excel/decimal-comma/envelope/status fixes. This design builds on the repaired engine.
**Review:** `docs/superpowers/audits/2026-07-03-unified-imports-spec-adversarial-review.md`

## Goal

Replace the fragmented import experience (partners, then separately opening balances; products without enrichment) with **two primary imports**:

1. **Parties** — suppliers and customers *including their opening balances* in one file.
2. **Products** — one sell-ready file (barcode, quantity, purchase price, sale prices, margin, tax, …), enrichment-integrated: known barcodes get enriched from the Synerivia platform, unknown ones get enriched in the background, and the user receives an Excel report of what was/wasn't enriched.

Current imports are kept but demoted to an "Advanced imports" section (hidden entirely for the parapharmacy vertical — see §3).

## Approach (chosen: A)

**A — new import types inside the existing Import module engine** (`app/Modules/Import`). The engine already provides: multipart upload, server-side header parsing (any CSV delimiter + XLSX/XLS), column mapping with suggestions, staged rows (`import_rows`), row-level validation errors, sync (<100 rows) / queued (`imports` queue) execution, progress broadcasting, history, failed-rows export. New flows inherit all of it.

Rejected: **B** standalone wizards (duplicates machinery, third paradigm); **C** extending the Accounting opening-balance batch subsystem into a general importer (wrong direction — it is an accounting subsystem with post/lock semantics).

## Prerequisite 0 — repair the AR/AP opening post lifecycle (BLOCKING, ships first)

The parties import feeds `ArApOpeningService::postBatch()`. That path has two confirmed defects today (verified against code 2026-07-03) and has likely never run end-to-end:

1. **Lifecycle order bug:** `postBatch()` calls `markRowsPosted()` (all rows → `Posted`) *before* `markBatchValidated()`, which requires `getValidRowCount() > 0` — rows in `Valid` status. After posting, valid count is 0, so `markBatchValidated` throws "no valid rows to process" and the whole transaction rolls back. Every AR/AP batch post fails. Fix: validate the batch **before** marking rows posted (mirror `InventoryOpeningService`'s order), or teach the batch transition to accept `Posted` rows. Regression test: post an AR batch with N valid rows end-to-end and assert documents + batch status.
2. **Hardcoded currency default:** `ArApOpeningService` defaults a missing row currency to `'TND'` instead of resolving the company currency. Fix: resolve company currency (same source as the rest of Accounting); reject non-company currencies in v1.

These fixes land as their own TDD task **before** any unified-imports work; the parties import then treats the lifecycle as stable.

## Cross-module contracts (new/changed) — Import module never reaches across boundaries

| Contract | Module owning implementation | Change |
|---|---|---|
| `PartnerServiceInterface::upsertWithTypeMerge` | Partner | extend: persist `code`; company-scoped upsert-key precedence `code` → `vat_number` → `name`; conflict rule below |
| `ProductServiceInterface::upsert` | Product | extend: upsert-key precedence `sku` → `barcode` (company-scoped); `name` fallback for placeholder rows; brand upsert |
| Tax default resolution (exists inside `ProductService` via `TaxResolutionService`) | Product | expose via Shared contract so Import's price resolver can resolve a row's effective tax rate before HT→TTC conversion |
| AR/AP opening batch create/add-rows/post (`ArApOpeningService`, `OpeningBalanceBatchService`) | Document / Accounting | expose via Shared contract for Import; no behavior change beyond Prerequisite 0 |
| Product-level opening stock (`OpeningBalancePostingService` — the same service `ProductController` already uses) | Inventory | expose via Shared contract; no behavior change |
| Enrichment bulk-lookup / submit (`ProductSubmissionService`) | PlatformIntegration | extend `submit()` with caller-supplied idempotency key (see §2) |

Import consumes these through `Shared/Contracts/` interfaces only (rule 6). All money strings formatted with `CurrencyScale::bcformatStrict` at storage scale 3 after `NumericFieldNormalizer`; quantities via `QuantityScale` scale 4; intermediates at scale+1 (precision contract).

## 1. Parties import (`ImportType::Parties`)

One row = one partner, with optional opening-balance columns on the same row.

| Column | Req | Notes |
|---|---|---|
| `name` | ✔ | |
| `type` | ✔ | `customer` \| `supplier` \| `both` |
| `code` |  | external/legacy id; **company-scoped upsert key when present** (else `vat_number`, else `name`) — requires the Partner contract change above (today `code` is ignored and not persisted) |
| `email`, `phone`, `tax_id` |  | `tax_id` maps to partner `vat_number` |
| `address_line1`, `address_city`, `address_postal_code`, `address_country` |  | |
| `opening_balance` |  | **signed**, scoped by `type` (sign convention below); valid only when `type` is `customer` or `supplier` |
| `opening_balance_customer` |  | signed, customer-scoped — required form **when a `type=both` row imports any opening balance**; otherwise both side columns may be blank |
| `opening_balance_supplier` |  | signed, supplier-scoped — same rule |
| `balance_date` |  | defaults to company opening date; becomes `document_date` AND `due_date` of the open item |
| `reference` |  | legacy doc reference for the opening entry |

Partner `code` conflict rule: same `code`, different `name`/`type` → the row **updates** the existing partner (code wins; type merges via the existing customer+supplier→`both` logic); a `partner_updated` note rides row provenance.

**DECIDED (owner, 2026-07-03): one balance per partner in v1** — the *actual net balance*, not aged open items. Aging-detail migrations keep using the existing AR/AP open-items batch wizard under "Advanced imports". Industry research (Appendix A) confirms this split is exactly how the market segments: lump-sum-on-the-partner-record is the QuickBooks Online convenience model for small businesses, while per-invoice detail is what accounting-led systems (Xero, Odoo, ERPNext, Business Central, SAP B1, French lettrage practice) require — we offer both, on the right surfaces.

### Sign convention (DECIDED: single signed column, type-scoped semantics)

- **Customer row:** positive = customer owes us (receivable / débit 411). Negative = we owe the customer (prepayment / avoir / credit balance).
- **Supplier row:** positive = we owe the supplier (payable / crédit 401). Negative = the supplier owes us (overpayment / debit balance).
- i.e. **positive always means the "normal" balance direction for that partner type** — the same symmetric convention QuickBooks Online uses for its record-level Opening Balance field, and equivalent to Xero's signed single-column conversion-balance mode.
- `type=both` rows: `opening_balance` is ambiguous → **validation error** telling the user to map `opening_balance_customer` / `opening_balance_supplier` instead (both may be present; each side posts to its own batch).
- Validation: signed money regex `/^-?\d+(\.\d{1,3})?$/`, `NumericFieldNormalizer` first, then `CurrencyScale::bcformatStrict($v, 3)`. Zero = no opening entry (skip, not error).

Rationale (research, Appendix A): no vendor exposes a raw debit/credit column pair *on the partner file* — that shape belongs to journal imports (Odoo, EBP, FEC), which our Advanced accounting batch already covers. For a partner-level lump sum, the signed field is the established SMB pattern (QBO; Xero single-column mode), it survives spreadsheet sorting/summing, and the sign→document-type conversion below preserves full accounting rigor internally (verified compliant-as-input by the adversarial research pass, A.3).

### Execution (single job, two internal phases)

1. **Partners phase** — `PartnerServiceInterface` upsert per row (with the `code` key extension).
2. **Balances phase** — after partner rows commit, rows with a non-zero balance are assembled into **auto-created opening-balance batches** (one AR, one AP per import job) posted through `ArApOpeningService` (post-Prerequisite-0).
   - **Sign → open-item mapping** (the subsystem requires positive magnitudes + `document_type`; `total`/`open_amount` are `min:0`):
     - customer, positive → AR row, `document_type=invoice`, `total=open_amount=abs(value)`
     - customer, negative → AR row, `document_type=credit_note`, magnitude `abs(value)`
     - supplier, positive → AP row, `document_type=invoice`
     - supplier, negative → AP row, `document_type=credit_note`
     - `document_date = due_date = balance_date`; `external_invoice_number = reference` (fallback `IMPORT-{job-short-id}`); **`currency` = company currency, injected explicitly on every row**
   - Same direction-encoding as SAP B1 (invoice vs credit-note objects), Business Central (Document Type), Sage 50 UK (Inv/Crn) — Appendix A.
   - **Accounting contract (explicit):** AR/AP open-item batches create **historical, non-fiscal Documents with `balance_due` — they do NOT post GL** (`OpeningBatchType::affectsGL()` is false for AR/AP: "assumes GL already done"). GL control-account opening (411/401) remains the job of the Advanced accounting opening-balance batch, per the GL roadmap (GL = downstream projection). The wizard's done-screen copy must say this: *"Partner balances imported as open items. General-ledger opening balances are entered separately under Advanced imports → Accounting opening balances."* Test plan asserts documents + balances, **not** GL entries.
   - Batch traceability: `opening_balance_batches.import_file_reference` (existing column) = import job id; name `IMPORT-{job-short-id}-AR/AP`. **Retry idempotency:** a partial unique index on `(import_file_reference, type)` (where `import_file_reference` not null) prevents racing duplicates. On retry: batch found **posted** → skip (never re-post); batch found **draft/validated** (a failed post rolls its transaction back, so no documents exist) → delete its staged rows, re-add from the current import rows, post. Any other state → fail the balances phase with an operator-visible `balance_batch_unrecoverable` warning on affected rows.
   - **Balance failures are row *warnings*, not import errors:** the partner itself imported, so the row stays successful with warning `balance_not_posted` (+ reason). The job summary and workbook surface "N balances not posted" prominently. (Rationale: one row = two outcomes; the existing single `import_error`/`is_imported` row model can't represent a half-failure, and failing the row would misreport the partner as not imported. Sub-results ride `import_rows.data._results = {partner, ar_balance, ap_balance}`.)
   - If opening balances are already **locked** for the company, any row with balance columns fails validation with an explicit "opening balances are locked" error; partner-only rows still import.
   - **v1 scope: company currency only.** Foreign-currency balances → Advanced open-items wizard.

## 2. Products import (evolved `ImportType::Products` — same API value, extended)

| Column | Req | Notes |
|---|---|---|
| `barcode` |  | EAN-13/UPC normalized (existing normalizer) |
| `name` | ✔* | *phase 3 relaxes to `barcode` OR `name` (accept-then-enrich); **in phase 2, `name` stays required** (today name+sku+type are hard-required — validation contract changes land with each phase) |
| `sku` |  | when blank: deterministic auto-generation — `sku = barcode` when barcode present, else generated ULID-based; deterministic so re-import stays idempotent |
| `type` |  | `part` \| `service` \| `consumable`; **defaults to `part`** when blank (today required) |
| `quantity` |  | opening stock (see below) |
| `location_code` |  | defaults to company default location |
| `purchase_price` |  | net (HT) cost; also the opening-stock unit cost (WAC seed) |
| `sale_price_incl_tax` |  | TTC — maps onto `products.sale_price` (stored TTC; POS extracts tax from it) |
| `sale_price_excl_tax` |  | HT — converted: `TTC = HT × (1 + tax_rate/100)` |
| `margin` |  | % markup on cost: `HT = purchase_price × (1 + margin/100)`, then TTC via tax rate; percent regex `/^-?\d+(\.\d{1,2})?$/` (percent is NOT currency-scaled) |
| `tax_rate`, `unit`, `category_name`, `brand`, `description`, `is_active` |  | existing semantics; `brand` upserts tenant-scoped Catalog Brand by slug (`firstOrCreate`, same as `EnrichmentReviewService::accept`) — added to the Product upsert contract (today ignored) |

Product upsert key precedence (contract change): match by **file-provided** `sku` first; else match by `barcode` (company-scoped); else create with the generated sku. "Provided" means present in the file *before* any generation — a generated sku never participates in matching, so a barcode-only row always reaches the barcode match and cannot duplicate an existing product that has the same barcode under a different sku (explicit test case). `updateOrCreate` stays company-scoped.

### Price resolution (DECIDED: all three mappable + authoritative-source question)

Canonical stored value is `products.sale_price` = **TTC** (verified: POS `computeTaxAmount` extracts tax from `sale_price`; product editor computes margin as `(HT − cost)/cost` — markup on cost against HT, matching this spec's margin formula).

**The resolver lives in the Import module** and runs during validation, producing a single canonical `sale_price` (TTC, numeric string) that flows into the *unchanged* `sale_price` key of the product upsert — the Product service never sees the three input columns.

- **All three** price inputs (`sale_price_incl_tax`, `sale_price_excl_tax`, `margin`) are independently mappable — never block mapping.
- **Wizard asks when ≥2 are mapped:** "Which field is authoritative when values conflict?" Default suggestion: **TTC** (retail users think in shelf price). Persisted in `import_jobs.options.price_authority`.
- **Effective tax rate per row** (needed for any HT/margin → TTC conversion): row `tax_rate` if present; else the company/category default via the same tax-resolution source `ProductService` already uses (exposed through the Shared contract above). The resolved rate is recorded in row provenance. If no rate can be resolved, warning `tax_unresolved` and the sale price is only set when the authority is TTC.
- **Per-row resolution:** compute canonical TTC from the authoritative field (bcmath, intermediates at scale+1, one boundary round via `bcformatStrict`). If another mapped price field is present and its derived TTC differs by **more than one unit at the currency's last decimal place**, the row imports with the authoritative value and gets warning `price_conflict` (provided vs derived values) — never a rejection.
- API/unattended default (no wizard answer): precedence **TTC > HT > margin**.
- `margin` mapped but `purchase_price` empty on a row → warning `margin_without_cost`, no sale price set from margin.
- Coordination: if the margin-hierarchy slice (`feat/margin-category-override`) merges first, imported explicit sale prices set `pricing_mode=manual`; margin-derived ones set `auto` — confirm against `MarginResolver` at implementation time.

### Opening stock

`quantity` creates one **Opening inventory movement** per product/location via **`OpeningBalancePostingService`** (the exact service `ProductController` already uses for product-level opening stock — *not* `InventoryOpeningService`, which is the batch-wizard path), after the product upsert, unit cost = `purchase_price`.

- `quantity` without `purchase_price` → warning `qty_without_cost`, movement skipped (WAC must not seed at zero cost).
- Existing active opening movement for the product/location (service rejects duplicates) → warning `opening_exists`, movement skipped, product data still updated.
- `type=service` rows → `quantity` ignored with warning.
- Batch/expiry-tracked products: not supported by this import in v1 → warning, movement skipped (batch stock goes through the existing flows).
- Opening period **locked** → warning `opening_locked` ("stock increases must come through Purchase Orders → Goods Receipt"), movement skipped; the product row itself succeeds. (Same single-outcome-per-row model as parties: stock sub-results ride `data._results.opening_stock`; no mixed failed-row/imported-product state.)

### Enrichment integration (DECIDED: on by default, asynchronous, accept-then-enrich)

Owner decision: enrichment runs **by default** and must **never block acceptance** — accept the file, enrich asynchronously, then report back whatever was not enriched.

**Phase A — bounded prefill at validation (best-effort).** Barcodes resolve against platform `POST /api/v1/products/bulk-lookup` (endpoint exists; ERP client `ProductSubmissionService::bulkLookup()` exists, currently uncalled). Chunked (100/call), cache key = `{tenant}:{vertical}:{barcode}` TTL 1h, circuit-breaker aware, **hard time budget ~5s total** within the existing synchronous upload path (`set_time_limit(300)`, 10MB cap); unique-barcode cap 5,000/file (rows beyond the cap skip prefill, not the import). Chunks not resolved inside the budget are treated as "unknown for now" → async path. Platform outage → zero prefill, import proceeds (nothing becomes required).
- Found: prefill empty `name`/`brand`/`category` (file values win; provenance `enrichment_source` on the row) and store the platform linkage on the product.

**Accept-then-enrich for unresolved barcodes (phase 3 — the `barcode OR name` validation relaxation activates here, together with the placeholder machinery below; phase 2 still requires `name`).** A row with `barcode` but no `name` is **accepted**: the product is created as an **inactive placeholder** — `name = barcode`, `is_active = false`, `enrichment_status = pending`, and a new boolean column `products.is_enrichment_placeholder = true` (queryable carrier; the existing enrichment columns have no placeholder notion and products have no metadata jsonb). Placeholders may receive prices/cost/opening stock like any row. Rows with **neither name nor barcode** are rejected (workbook sheet 3). **Activation rule:** `EnrichmentReviewService::accept()` sets `is_active = true` and clears `is_enrichment_placeholder` when the accepted result supplies a name for a placeholder product; manual completion (user names it in the editor) does the same. Placeholders are listed on workbook sheet 2 and in the completion notification.

**Phase B — background enrichment.** After execution, a queued job (`imports` queue — already in Horizon config; enrichment submits are throttled so they don't starve import execution) submits not-found barcodes:
- Today: loop of existing single `POST /api/v1/products/submit`. **Idempotency key becomes caller-supplied and deterministic** — UUIDv5 with namespace `Uuid::NAMESPACE_URL` and name string `autoerp:import-enrichment:{tenant_id}:{company_id}:{import_job_id}:{normalized_barcode}` (exact contract so retries/languages agree) — replacing today's random-per-call UUID, so queue retries cannot duplicate submissions; `platform_submission_id` is recorded on the placeholder for webhook correlation.
- Later: swap the loop for platform `POST /api/v1/products/bulk-submit` — **does not exist on the platform yet**; log the ask in the monorepo-root `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` (path is outside `apps/erp`: `../../docs/...`) and align with the existing enrichment handovers.
- Results return through the existing webhook (`enrichment.batch_resolved` fan-out already handled) → `enrichment_results` PendingReview → normal review/accept flow (auto-accept where H-A rules apply). No new result plumbing.
- Per-job toggle: `import_jobs.options.enrichment_enabled`, default **true**.

**Result workbook (Excel).** New endpoint `GET /imports/{id}/result-workbook`: generated **on demand** from current DB state (no stored artifact, no retention problem; regenerating later naturally reflects newly arrived enrichment), same auth as the other import routes, streamed XLSX. Three sheets — (1) imported & enriched, (2) imported, enrichment pending/unavailable (incl. placeholders), (3) rejected rows with reasons. Warnings appear as a column on sheets 1–2. Implemented as an xlsx sibling of `FailedRowsExportService` (PhpSpreadsheet already installed). Linked from the wizard Done step and History (History drops nothing — the failed-rows CSV button stays).

## Job options & warnings (new engine contracts)

- **Options ingestion:** `POST /imports` accepts an optional `options` object (validated per type: `location_code`, `enrichment_enabled` bool, `price_authority` in `ttc|ht|margin`), threaded through `ImportService::createJob()` into the existing `import_jobs.options` jsonb (column exists; no ingestion path today). Because `price_authority` is only knowable **after mapping**, a `PATCH /imports/{id}/options` endpoint updates options any time **before execution starts** (409 afterward). Wizard order is explicit: **Upload → Mapping → Options → Validate → Execute → Done** (options step shown for products when ≥2 price columns mapped, plus the enrichment toggle; parties: no options step in v1). History displays the options used.
- **Warnings channel:** new nullable `warnings` jsonb column on `import_rows` (list of `{code, detail}`), **fully separate from `errors`/`import_error`** — warnings never affect row validity, success counts, or failed-rows export. Job summary/API exposes `warning_rows` count; the wizard Done step and History show it; workbook renders them. (Riding inside `errors` would flip rows invalid; riding silently inside `data` would give the FE no contract.)

## 3. Dashboard, permissions & legacy handling (DECIDED: keep legacy, hide for parapharmacy)

- Import dashboard: two primary cards — **Business partners** and **Products** — with the migration order hint (parties first).
- Legacy tiles (`partners` (old), opening-balance batch wizard link, old `opening_balances` CSV type, `stock_levels`, `product_images`, `composite_items`) move into a collapsed **"Advanced imports"** section. Routes and backends stay; nothing is deleted.
- **Parapharmacy vertical: the "Advanced imports" section is not promoted in the UI** — hidden via FE gate (`useCompanyConfig()` `vertical === 'parapharmacy'`). This is deliberately a UI demotion, **not** an access ban: the legacy endpoints remain callable for parapharmacy tenants by users holding `imports.manage` (support/operator escape hatch — e.g. an accountant-led aged-AR migration for a parapharmacy still works via API/support). Other verticals/countries keep the section visible: research (Appendix A) shows the segmented per-object import with journal-level opening balances IS the industry-standard pattern for accounting-led migrations — we will want it for FR/UK/IT onboarding and aged-AR/AP migrations, so it is kept, not redone.
- **Backend permission gating (new — FE hiding is not an enforcement boundary):** import routes today carry no `can:` middleware while creating partners, products, movements, and accounting documents. Add an `imports.manage` permission (seeded to admin/owner roles via `RolesAndPermissionsSeeder`) enforced on upload/validate/execute/download routes; mirror in FE `RequirePermission`. Finer-grained per-domain permissions are out of scope v1.
- `ImportType::Parties` is a **new external API value** `parties`; the old `partners` type remains functional and unpromoted. Compatibility matrix — every touchpoint that switches on type must handle the new case: backend enum match arms (required/optional columns, validation rules), template download, smart-mapping synonyms, `ProcessImportJob`, shared DTO types (`php artisan typescript:transform`), FE type union (`apps/web/src/features/import/types.ts`), wizard target columns, dashboard tiles, history labels.
- i18n: **all** new FE strings (cards, options step, warning labels, done-screen accounting note, workbook column headers where user-facing) land as `import` namespace keys in every supported locale file — no hardcoded literals (rule 11).

## Data / schema changes

- `ImportType::Parties` enum case + validation rules + template + smart-mapping synonyms (FR + EN headers: `solde`, `solde_client`, `solde_fournisseur`, `marge`, `prix_achat`, `prix_vente_ht`, `prix_vente_ttc`, `quantité`, `code_barre`).
- Partner: persist `code` (column exists with company-scoped uniqueness; service currently drops it).
- Products: new boolean `is_enrichment_placeholder` (default false) — migration.
- `import_rows`: new nullable `warnings` jsonb — migration.
- `import_jobs.options` (existing jsonb): `location_code`, `enrichment_enabled`, `price_authority` — plus the API/service ingestion path.
- Batch → job linkage via existing `opening_balance_batches.import_file_reference`.

## Error handling

- Row **errors** (partner upsert failure, product upsert failure, locked OB balance columns, missing name+barcode) mark the row failed; the job completes partially per repaired status semantics (Failed only when 0 successes).
- Row **warnings** (`balance_not_posted`, `balance_batch_unrecoverable`, `price_conflict`, `margin_without_cost`, `tax_unresolved`, `qty_without_cost`, `opening_exists`, `opening_locked`) never block; surfaced in summary + workbook. Locked-period quantity is `opening_locked` (movement skipped, product row succeeds — §2 Opening stock).
- Platform failures during Phase A degrade to "no prefill" and never block; Phase B failures retry per existing enrichment job policies and never affect import status.

## Testing (TDD throughout)

- Prerequisite 0: AR/AP batch post end-to-end regression (order bug), company-currency resolution.
- Unit: sign→document_type mapping (all four quadrants + zero + `both` rejection), price-resolution matrix (each authority × field combinations × conflict/no-conflict, bcmath scales, tax-rate fallback), deterministic sku generation, deterministic idempotency key, parties row splitter, workbook generator sheets.
- Feature: parties import creates partners (+ `code` upsert idempotency) + posted AR/AP batches with documents & `balance_due` (**no GL assertion — AR/AP opening posts no GL by design**); negative balances → credit_note items; locked-OB rejection; retry idempotency via `import_file_reference`; products import creates products + opening movements + WAC seed; placeholder creation/activation; duplicate-opening & qty-without-cost warnings; margin-derived pricing incl. default-tax fallback; barcode prefill (platform HTTP faked at the client boundary — no fake data in production code); prefill time-budget expiry; background submit dispatch with deterministic key; options ingestion; warnings channel isolation from failed-rows export; result-workbook endpoint contents & auth.
- FE: wizard flows incl. options step (Vitest at api/queries level), dashboard card gating incl. parapharmacy hiding, warning display, i18n keys exist in all locales.
- Live Playwright pass with a French-locale Excel file for both imports before merge.

## Build order

0. **Prerequisite** — AR/AP opening lifecycle fix + currency fix (blocking, own TDD task).
1. **Parties unified** — Partner `code` contract + BE type + signed-balance batch integration + options/warnings engine plumbing + FE card + template + permission gate.
2. **Products unified, sell-ready** — validation/upsert contract changes + price resolution + opening stock + workbook (sheets 2/3) + options step.
3. **Enrichment integration** — Phase A bounded prefill, placeholders + activation, Phase B deterministic submits, workbook sheet 1, REALIGNMENT-LOG entry for `/bulk-submit`.

Each phase independently shippable.

## Landing (DECIDED)

Land on **`post-demo`**; promote to `dev` once stable (per post-demo branch policy). The repaired legacy flow already on `dev` is what the demo uses — it stays untouched.

## Resolved questions (owner, 2026-07-03)

1. **OB granularity:** one signed net balance per partner in v1; aged open items stay on the Advanced wizard.
2. **Margin semantics:** markup on cost — `HT = cost × (1 + m/100)` — matching existing product-editor math; plus explicit HT and TTC columns with the authoritative-source conflict rule.
3. **Enrichment default:** ON, asynchronous, accept-then-enrich; report un-enriched via workbook + notification.
4. **Landing:** post-demo → dev when stable. Legacy flows kept (industry-standard for accounting-led migrations), hidden for parapharmacy.

---

## Appendix A — Industry research (2026-07-03)

Question 1: should a partner-level opening balance field be signed, or magnitude + direction? Question 2: is our segmented legacy flow industry-standard and worth keeping?

**Method:** 3 parallel research agents (cloud SMB vendors; open-source + French market; flow segmentation) + 1 adversarial verification pass over the decision-critical claims. Verdicts in A.3.

### A.1 Sign convention findings

| System | Partner-balance shape | Direction encoding | Negative allowed? |
|---|---|---|---|
| QuickBooks Online/Desktop | single `Opening Balance` field on customer/vendor record | **sign** — "a negative open balance means you owe the customer or vendor" | ✔ signed |
| Xero (conversion balances) | Debit/Credit columns, **or** single column | column pair, or **sign** (positive=debit, negative=credit) in single-column mode | ✔ in single-column mode |
| Sage 50 UK | one row per open transaction | `Type` = `Inv` / `Crn` (document type) | ✘ — credit balances are `Crn` rows |
| Dynamics 365 BC | general-journal lines (or "Prepare Journal" batch) | `Document Type` = Invoice / Credit Memo | ✘ |
| SAP Business One (DTW) | per-object templates | oInvoices vs oCreditNote (template = direction) | ✘ |
| Odoo / EBP / FEC (France) | journal items | separate **débit / crédit columns** (double-entry, lettrage, FEC compliance) | ✘ (column pair) |
| ERPNext | Opening Invoice Creation Tool | Invoice Type (Sales/Purchase) + positive Outstanding Amount | ✘ (direction via type) |

**Conclusion:** at the *partner-record* level the signed single field is the established SMB pattern (QBO; Xero's signed single-column mode). The débit/crédit column pair belongs to *journal-level* imports — which our Advanced accounting batch already provides. Document-type encoding (Sage/BC/SAP/ERPNext) is what our internal AR/AP open-items subsystem already uses (`document_type` invoice/credit_note, positive magnitudes) — the signed column converts onto it losslessly. Our design = QBO ergonomics on the file, SAP/BC rigor in the ledger.

### A.2 Segmentation findings

- **Accounting-led systems are all segmented:** Xero conversion (chart → balances → unpaid invoices/bills → contacts, balances never on the contact record); Odoo (one model per CSV; stock and balances are separate models); ERPNext (data import per doctype + dedicated Opening Invoice Creation Tool + Stock Reconciliation); Business Central (RapidStart configuration packages are per-table and cannot import posted ledger entries — balances go through journals); SAP B1 DTW (one template per object). Rationale: aged AR/AP reporting, audit confirmations, lettrage (France), FEC export compliance.
- **Retail POS systems are combined:** Shopify product CSV carries price, cost-per-item, and single-location inventory qty in one row (separate inventory CSV only for multi-location); Lightspeed X-Series onboarding product CSV combines price/cost/SKU/tax; Square segments only ongoing transactional stock (POs), not onboarding.
- **QuickBooks Online sits in the middle** — lump-sum Opening Balance on the entity CSV, with Intuit itself steering rigorous users toward per-invoice entry.

**Conclusion (drives the keep-legacy decision):** the combined two-file flow matches the retail-POS onboarding pattern our parapharmacy/small-retail users expect; the segmented flow matches the accounting-led migration pattern that FR/UK/IT accountants and larger migrations will demand (aged detail, journal balances, FEC). Keep both: unified primary, segmented under Advanced (hidden for parapharmacy).

### A.3 Verification verdicts (adversarial pass, 2026-07-03)

All six decision-critical claims survived an independent refutation attempt against primary sources:

1. **QBO signed Opening Balance field + Intuit "leave it blank, enter open items" guidance — CONFIRMED** (leave-blank recommendation verbatim in Intuit help L90uWlIBd; negative-balance semantics confirmed via Intuit credit/overpayment articles; exact field-level "negative-accepting" wording is inference from documented behavior).
2. **Xero dual mode (Debit/Credit columns as positives, or single signed column) + mandatory per-invoice AR/AP detail — CONFIRMED** (verbatim in Xero Central "Enter conversion balances").
3. **Sage UK/IE compulsory `Type` = Inv/Crn with positive totals; credit balances are Crn rows — CONFIRMED** (help.sbc.sage.com + gb-kb.sage.com).
4. **BC Document Type Invoice/Credit Memo journal lines, per open item; RapidStart populates journals, never posted ledger entries — CONFIRMED** (nuance: the ledger-entry restriction is stated operationally — journals are the path — rather than as an explicit prohibition line in MS docs).
5. **French débit/crédit column convention at per-tiers auxiliary level (411/401); FEC mandates separate Debit/Credit columns, never a signed field — CONFIRMED** (per-non-lettré-invoice best practice: PLAUSIBLE — practitioner guides, no single official source).
6. **Retail POS combined product file (price + cost + single-location qty) vs segmented accounting-led ERPs — CONFIRMED** via Shopify primary docs (Lightspeed specifics: PLAUSIBLE, not independently fetched).

**Verifier synthesis:** the signed lump-sum column is well-precedented as an *input convenience* (QBO record level, Xero single-column mode) but would be non-compliant as a *storage model* in FR/TN/IT — the internal conversion to document-type-encoded open items (§1 Execution) is exactly the required normalization, and keeping the segmented per-invoice importer available is the industry default for compliance-sensitive migrations, not optional polish.

### A.4 Sources (primary)

- Xero Central: Enter conversion balances; Enter unpaid invoices and bills; Import conversion balances (Conversion Toolbox); The accounting behind Xero conversion balances
- Intuit: Enter outstanding balances for customers and vendors (L90uWlIBd); Import bills; negative open balance community guidance
- Microsoft Learn: Set up company configuration packages (RapidStart); practitioner guides (thedynamicsexplorer, usedynamics) for opening-balance journals
- Sage: help.sbc.sage.com Enter or import customer/supplier opening balances (Type Inv/Crn CSV)
- Odoo: docs (essentials/export_import_data), odoo.com forum threads on opening balances + eLearning slide "Import an opening balance"
- ERPNext: docs.frappe.io Opening Invoice Creation Tool; opening balance
- SAP B1: community.sap.com DTW business-partner opening-balance threads (official help.sap.com pages were fetch-blocked — flagged)
- EBP: support.ebp.com import manuel des écritures (Débit/Crédit columns, compte tiers)
- French practice: macompta.fr reprise d'une comptabilité existante; compta-facile.com reprise des à-nouveaux / balance auxiliaire; agiris.fr lettrage; legalstart.fr FEC
- Shopify: help.shopify.com product CSV / inventory CSV; Lightspeed X-Series import docs; Square purchase-order import

---

## Appendix B — Codex adversarial review dispositions (2026-07-03)

Review: `docs/superpowers/audits/2026-07-03-unified-imports-spec-adversarial-review.md`. All 8 blockers and the decision-critical majors were independently verified against code before dispositioning; every verified claim held.

| Finding | Disposition in v2 |
|---|---|
| B1 GL entries claimed but AR/AP posts no GL | Spec corrected: documents-only contract made explicit (§1 Execution), user-visible copy required, GL stays with Advanced accounting batch per GL roadmap; test plan no longer asserts GL |
| B2 postBatch lifecycle unusable (verified: markRowsPosted before markBatchValidated → always throws) | Prerequisite 0 (blocking, ships first) + regression test |
| B3 `parties` API value ripple | Compatibility matrix added (§3); `partners` stays functional |
| B4 partner `code` ignored by service | Partner contract extension: persist `code`, key precedence code→vat_number→name, conflict rule (§1) |
| B5 barcode-only rows can't validate/upsert | Validation contract `barcode OR name`; deterministic sku; upsert precedence sku→barcode; `type` default `part` (§2) |
| B6 price columns silently dropped | Resolver lives in Import, emits canonical `sale_price` into unchanged product key; tax fallback via shared contract (§2) |
| B7 no options ingestion path | Options contract on `POST /imports` + `createJob` + wizard step (§ Job options) |
| B8 warnings break row-validity semantics | Dedicated `import_rows.warnings` jsonb + counts, isolated from errors/failed exports (§ Job options) |
| M1 wrong opening-stock service | `OpeningBalancePostingService` (ProductController pattern); duplicate/no-cost/service-type/batch-tracked/locked rules specified (§2) |
| M2 batch traceability | `import_file_reference` = job id + retry idempotency rule (§1) |
| M3 'TND' currency default | Company currency injected on every row; service fix bundled into Prerequisite 0 |
| M4 placeholder carrier | New `products.is_enrichment_placeholder` bool + `enrichment_status=pending` + activation rule in `EnrichmentReviewService::accept` (§2) |
| M5 idempotency overstated (verified: random UUID per call) | Deterministic caller-supplied key `uuid5(tenant, company, job, barcode)`; contract change on submit (§2) |
| M6 no backend permission gate | `imports.manage` permission on all import routes, mirrored in FE (§3) |
| M7 no workbook artifact contract | `GET /imports/{id}/result-workbook`, on-demand generation, same auth, streamed (§2) |
| M8 two-phase row semantics | Balance failures = warnings + `data._results` sub-results; row success = partner success (§1) |
| M9 Phase A brittleness | Time budget, cache key scope, 5k barcode cap, degradation path specified (§2) |
| M10 i18n unspecified | i18n requirement added (§3) |
| M11 module-boundary ambiguity | Cross-module contracts table added (top) |
| M12 `both` + sign ambiguity | Side-specific columns mandatory for `both`; payload mapping table explicit (§1) |
| N1 regex ≠ formatter | `bcformatStrict`/`QuantityScale` named in contracts section |
| N2 tax source when `tax_rate` absent | Default-tax fallback + `tax_unresolved` warning (§2) |
| N3 brand contract | Tenant-scoped Catalog Brand firstOrCreate named (§2) |
| N4 REALIGNMENT-LOG path | Monorepo-root path spelled out (§2 Phase B) |

Open questions 1–13 from the review are answered by the dispositions above; none required a new owner decision (Q2/GL follows the owner-approved GL roadmap: GL = downstream projection, opening GL via the accounting batch).

### Round-2 addendum (v3 fixes)

Round 2 verdict was NOT-READY on 8 consistency findings introduced by the v2 revision itself; all fixed in v3:

| Round-2 finding | v3 fix |
|---|---|
| R2-1 generated sku bypasses barcode match | Matching uses **file-provided** sku only; generated sku never matches; explicit duplicate-prevention test (§2) |
| R2-2 locked-period qty contradicts row semantics | Downgraded to warning `opening_locked` + skipped movement; product sub-results `data._results.opening_stock` (§2) |
| R2-3 parapharmacy gating FE-only | Reworded as deliberate UI demotion, not access ban; API remains `imports.manage`-gated escape hatch (§3) |
| R2-4 phase 2 ships barcode-only rows without placeholders | `barcode OR name` relaxation explicitly activates in phase 3; phase 2 keeps `name` required (§2) |
| R2-5 batch retry incomplete for partial batches | Partial unique index on `(import_file_reference, type)`; draft/validated → delete rows, re-add, post; other states → `balance_batch_unrecoverable` (§1) |
| R2-6 `both` wording implies balances required | Reworded: side columns required only when a `both` row imports any balance (§1) |
| R2-7 options step placement | Wizard order fixed (Upload → Mapping → Options → Validate → Execute); `PATCH /imports/{id}/options` before execution, 409 after (§ Job options) |
| R2-8 uuid5 underspecified | Exact namespace (`Uuid::NAMESPACE_URL`) + name-string contract (§2 Phase B) |
