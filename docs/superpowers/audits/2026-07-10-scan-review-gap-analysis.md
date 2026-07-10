# Scan Review — Gap Analysis vs Industry + Atomic-Design Audit (2026-07-10)

**Inputs:** owner feedback on staging (VAT free-text, product creation rigor, catalog wiring); 2 web-research agents over 8 AP-automation products (Rossum, Dext, Nanonets, Odoo, Xero/Hubdoc, QuickBooks, Ramp, Pennylane); 1 codebase audit agent (atomic contract per `src/components/README.md` + `docs/architecture/frontend.md`, cross-checked against the `feat/design-system-unification` inventory `2d92d0402`).

## 1. Owner's three points — verdicts

| Point | Verdict | Detail |
|---|---|---|
| VAT must not be free text | **CONFIRMED GAP** — industry-universal | 3 of 4 EU/FR-market products bind line VAT to the org's configured tax codes (Dext "Choose a Tax rate", Odoo `account.tax`, Pennylane dropdown with "Autre"/"Aucune"/"Multitaux"; QBO UK VAT codes). Nobody ships free-numeric VAT on lines. We have the exact component already: `TaxConfigurationSelect` (atom) with built-in **"+ Add new tax"** → `TaxConfigFormModal`, and it filters by `applicable_document_types` where `'supplier_invoice'` matches our `kind` string exactly. |
| Product creation must not be free-form | **PARTIALLY CONFIRMED** | Industry norm is a *lightweight but structured* quick-create (Dext "+Add supplier" name-only; Odoo many2one quick-create form; Pennylane "Créer…" in the party field) — a full-form redirect is NOT the norm. Our `AddQuickProductModal` is already structured (name, SKU auto-gen, price, tax config via the canonical selector). The genuine free-form residue: the modal's `tax_rate` prefill from OCR seeds a numeric but `tax_configuration_id` resets to null — no rate→configuration matcher exists anywhere in the repo. |
| Quick-created products must land in the catalog | **CONFIRMED WORKING** | `AddQuickProductModal` → `POST /products` (`CreateProductRequest`, same endpoint as the catalog create flow), `is_active: true` → visible in Catalogue→Produits immediately; costing established at commit via WAC. Matches industry (Dext/Ramp create into master data / synced ERP). |

## 2. The "something missing" — named

**Supplier-scoped mapping memory. We have NONE; it is the #1 recurring pattern across all 8 products.**
- Evidence (codebase): no `supplier_product`/`supplier_item` table exists; `MatchSuggestionService::suggest()` recomputes candidates live per request (Partner: vat/name; Product: sku/barcode/oem/name-LIKE); `SupplierInvoiceCommitter` writes the reviewer's confirmed `product_id` into `document_lines` and **records nothing** for future scans. A reviewer's correction today does not improve next month's match for the same supplier line.
- Evidence (industry): Hubdoc Supplier Rules; Dext supplier rules (default product/category/tax per supplier); Pennylane per-supplier default account + VAT rate; Ramp vendor GL defaults + AP-Agent learning-with-citations; Rossum grey-tick from vendor-history. All persist supplier-level defaults; Ramp is the ceiling (per-field provenance).
- **Highest-leverage design move:** on commit, persist `(supplier_id, normalized line description / supplier ref) → (product_id, tax_configuration_id)`; feed it into `MatchSuggestionService` as the top-ranked candidate with a "remembered from <date> invoice" provenance hint. This also solves the "VAT unknown on photo receipts" case (his live scan had empty VAT — a supplier default would have filled it).

Other recurring patterns we lack:
- **Required-before-commit field gating with per-field machine/human states** — we have the cue icons + aggregate commit gating (good; Rossum-grade is per-field confidence thresholds, default 0.975).
- **Multi-VAT line splitting** ("Multitaux") — Pennylane's own community flags theirs as broken; for FR/TN this is a differentiation opportunity, not a must-have now.
- **Explicit "no VAT/exempt" option** (QBO "No VAT", Pennylane "Aucune") — needed for TN exonéré cases; free-numeric zero is not the same as a declared exemption class.

## 3. Atomic-design violation inventory (scan feature)

Contract: atoms/molecules/organisms per `src/components/README.md`; raw styled HTML in features is the anti-pattern. The unification branch (`feat/design-system-unification`, unmerged) already counts 4 scan files in its C2/C3 tallies.

**Drop-in swaps (no blockers):** LineMappingTable receipt-line `<select>`→`Select`, batch/expiry `<input>`→`Input`, "+ New product" `<button>`→`Button`; UploadScanPage kind `<select>`→`Select`, browse/remove/submit buttons→`Button`, `<h1>`→`PageHeader`; ReviewIngestionPage 4× `<h1>`→`PageHeader`, location `<select>`→`Select`, checkbox→`Checkbox`, re-extract button→`Button`; CommitBar re-extract/commit→`Button`; ProcessingState `<h1>`→`PageHeader`.

**Swaps with real blockers (shared-component gaps to fix first):**
- `Button` lacks `dangerOutline` variant (CommitBar Reject would regress to solid red) and a `link`/`text` variant (SourceViewer pager).
- `FormField` has no `labelExtra` slot for the machine/edited `FieldCue` icons.
- No interactive Chip molecule exists (candidate quick-pick chips) — `Badge` is presentational-only.
- No Stepper molecule (ProcessingState step dots) — unconsolidated, not a violation.
- **Per-line VAT → `TaxConfigurationField`**: needs (a) `taxConfigurationId` on `ReviewedLineState`, (b) a numeric-safe rate→config matcher (string equality unsafe: "19" vs "19.00"; ambiguity when configs share a rate), (c) backend: commit chain (`CommitDocumentIngestionRequest`→`ReviewedLineData`→committers) only carries bare `vatRate` — carrying `tax_configuration_id` end-to-end is the honest fix; resolving client-side and sending the rate is the cheap fix that loses the "which config" fact.
- **SupplierPicker → PartnerPicker**: blocked by (a) PartnerPicker has no injection point for the OCR candidate ranking, (b) value-shape mismatch (id-only rehydration unbuilt — also flagged by the unification branch), (c) PartnerPicker's `allowNewInline` opens a new tab (regression vs our inline `AddPartnerModal`), (d) PartnerPicker's queryKey misses `tenantScopedKey` (bug; fixed only on the unmerged unification branch). Verdict: keep SupplierPicker short-term; converge when the unification branch lands its picker work.
- `UploadScanPage` uses per-field `useState` instead of RHF+zod (the C4 pattern; escaped the sweep's filename regex).

## 4. Recommended slices (for owner prioritization)

1. **VAT-as-configuration on lines** (owner's #1): `TaxConfigurationField` per line (`documentType={kind}`, size sm), rate→config matcher with ambiguity fallback to "select manually", "+ Add new tax" inherited free, explicit exempt option; backend carries `tax_configuration_id` through the commit chain. Also fix `AddQuickProductModal` prefill to run the same matcher.
2. **Supplier mapping memory** (the missing thing): persistence at commit + suggestion-rank integration + provenance hint in the UI. Backend-led slice; biggest long-term payoff.
3. **Atomic sweep of the scan feature**: all drop-in swaps + the small shared-component gaps (`dangerOutline`+`link` Button variants, `FormField.labelExtra`, Chip molecule) — coordinate with `feat/design-system-unification` to avoid double-building; the Button/FormField gaps are shared-tree changes that unblock the procurement sweep too.
4. **Deferred**: SupplierPicker→PartnerPicker convergence (after unification branch), multi-VAT line splitting, per-field confidence thresholds.

## 5. Live defect found during this session (fixed)

Deployed PDF previews failed: nginx served the pdf.js worker (`.mjs`) as `application/octet-stream` → browser MIME-blocked the module worker. Fixed in `apps/web/docker/entrypoint.sh` (generated server config) + `nginx.conf.template` (`location ~* \.mjs$ { default_type application/javascript; … }`), commit `f2c745d5c`, validated with nginx -t + live container curl.
