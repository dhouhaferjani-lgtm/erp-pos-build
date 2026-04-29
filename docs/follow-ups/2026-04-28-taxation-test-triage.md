# Taxation Feature Test Triage — Skipped Tests

**Date:** 2026-04-28
**Origin:** L4 in `2026-04-28-q2-release-deferred-items.md` ("24 skipped Taxation Feature tests — worth a triage to decide which can be re-enabled vs. retire").
**Author:** Session 3 (M4 + L4)

---

## Method

`grep -rn 'markTestSkipped' apps/api/tests/Feature/Taxation/` enumerated every skip site. Most of these are **class-level skips** in `setUp()` (so the *whole* test class no-ops) rather than per-method skips. The audit's "24 tests" figure counts each skipped test method that lives under a skipped `setUp()`.

For each skip site, this doc records:

- **Class & line.**
- **Skip rationale.**
- **Verdict** — one of:
  - `Re-enable now` — the underlying blocker is gone; un-skip in a follow-up PR.
  - `Still blocked` — production code is genuinely missing; link to the gap.
  - `Retire` — the test is duplicative or no longer reflects desired behavior.
  - `Owned elsewhere` — another active session owns the un-skip.

Verdicts are recommendations only — un-skipping itself is out of scope for this triage doc; each will land in a follow-up PR (one per class is fine).

---

## Skip site inventory

### 1. `tests/Feature/Taxation/TaxCalculationTest.php:240`

- **Test:** `test_it_does_not_apply_stamp_duty_for_drafts` (per-method skip; rest of class runs).
- **Rationale (in source):** "TaxCalculationService does not differentiate Draft from Posted documents when applying stamp duty. Re-enable when the service gates document-level taxes by document status."
- **Verdict:** **Owned elsewhere — M3 (refund-flow / deferred-items coordination session).**
  Per the deferred-items doc, M3 is "Stamp duty draft guard." That session owns both the production code change in `TaxCalculationService` (add a `DocumentStatus` guard before applying stamp duty) and the un-skip of this test. **Do not touch in this triage.**

---

### 2. `tests/Feature/Taxation/TaxSnapshotInvoiceTest.php:46` (class-level skip)

- **Affected tests (5):**
  - `test_creates_tax_snapshots_on_invoice_confirmation`
  - `test_tax_snapshots_reflect_company_tax_status_at_confirmation_time`
  - `test_tax_snapshots_survive_company_tax_status_changes`
  - `test_tax_snapshots_are_immutable_no_updated_at_column`
  - `test_handles_stamp_duty_correctly`
  - `test_recalculates_and_updates_document_totals`
- **Rationale (in source):** `'Requires document confirm endpoint implementation'`.
- **Reality check:** `apps/api/app/Modules/Document/Presentation/routes.php:137` registers `POST /api/v1/invoices/{invoice}/confirm` → `InvoiceController::confirm`. The endpoint exists and the AutoSpecs cluster ships tax-snapshot creation through it.
- **Verdict:** **Re-enable now (with rehab work).**
  The blocker rationale is stale — the endpoint shipped. The tests still need a rehab pass to bring fixtures in line with the current `Document` schema (e.g., `documents.type` enum, `fiscal_category`, post-Phase-4 tolerance / strip behavior), so this is "re-enable + repair," not a one-line un-skip. Recommended: dedicate one follow-up PR per class (size ≈ a half-day each).
- **Pre-req before un-skip:** verify the test still authenticates correctly under `auth:sanctum` + `SetPermissionsTeam` middleware (Rule #12).

---

### 3. `tests/Feature/Taxation/TaxSnapshotCreditNoteTest.php:44` (class-level skip)

- **Affected tests (3):**
  - `test_creates_tax_snapshots_on_credit_note_confirmation`
  - `test_credit_note_snapshots_reflect_negative_amounts`
  - `test_credit_note_snapshots_independent_of_original_invoice`
- **Rationale (in source):** `'Requires document confirm endpoint implementation'`.
- **Reality check:** `apps/api/app/Modules/Document/Presentation/routes.php:197` registers `POST /api/v1/credit-notes/{id}/confirm` → `CreditNoteController::confirm`.
- **Verdict:** **Re-enable now (with rehab work).** Same shape as item 2 — endpoint exists; tests need a pass to align fixtures with current schema.
- **Cross-link:** the **POS refund-flow design** session is finalising credit-note semantics for IziPOS; verify with that session that the assertions on "negative amounts" and "independent of original invoice" still match the agreed model before un-skipping. Coordinate, do not block.

---

### 4. `tests/Feature/Taxation/TaxSnapshotSalesOrderTest.php:51` (class-level skip)

- **Affected tests (4):**
  - `test_creates_tax_snapshots_on_sales_order_confirmation`
  - `test_tax_snapshots_created_with_stock_reservation`
  - `test_tax_snapshots_with_multiple_products_different_rates`
  - `test_tax_snapshots_for_non_registered_company`
- **Rationale (in source):** `'Requires sales order confirm endpoint implementation'`.
- **Reality check:** `apps/api/app/Modules/Document/Presentation/routes.php:100` registers `POST /api/v1/orders/{order}/confirm` → `SalesOrderController::confirm`.
- **Verdict:** **Re-enable now (with rehab work).** Endpoint exists; fixtures need a rehab pass.

---

### 5. `tests/Feature/Taxation/TaxSnapshotPurchaseOrderTest.php:50` (class-level skip)

- **Affected tests (5):**
  - `test_creates_tax_snapshots_on_purchase_order_confirmation`
  - `test_tax_snapshots_show_non_recoverable_for_unregistered_company`
  - `test_tax_snapshots_with_mixed_recoverable_and_non_recoverable_taxes`
  - `test_tax_snapshots_preserved_after_landed_cost_allocation`
  - `test_tax_snapshots_for_purchase_with_import_taxes`
- **Rationale (in source):** `'Requires purchase order confirm endpoint implementation'`.
- **Reality check:** `apps/api/app/Modules/Document/Presentation/routes.php:230` registers `POST /api/v1/purchase-orders/{purchaseOrder}/confirm` → `PurchaseOrderController::confirm`.
- **Verdict:** **Re-enable now (with rehab work).** Endpoint exists. Note: the "landed cost allocation" and "import taxes" tests may need extra care — verify those ancillary services (landed cost, import-tax handling) are wired before un-skipping those specific methods. If a per-method blocker remains, narrow to a per-method `markTestSkipped` rather than the whole class.

---

### 6. `tests/Feature/Taxation/TaxRecoverabilityTest.php` (5 per-method skips at lines 187, 246, 299, 328, 354)

- **Affected tests (5):**
  - `test_vat_registered_company_recovers_vat_but_not_stamp_duty`
  - `test_non_vat_registered_company_absorbs_all_taxes_into_cost`
  - `test_average_weighted_cost_vat_registered`
  - `test_average_weighted_cost_non_vat_registered`
  - `test_changing_vat_recoverability_affects_cost`
- **Rationale (in source):** `'Pending purchase invoice implementation'`.
- **Reality check:** `DocumentType` enum (`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:9-16`) has cases for `PurchaseOrder` but **no `PurchaseInvoice`**. The skipped test bodies expect `POST /api/v1/purchase/invoices` and a corresponding `/post` action — neither route nor controller exists in the current codebase (`grep` for `purchase/invoices`, `PurchaseInvoiceController`, `Modules/PurchaseInvoice` returns zero hits). The Accounting `AgedPayablesService` references a string `type = 'purchase_invoice'` in a comment, but the enum does not include it.
- **Verdict:** **Still blocked — purchase-invoice domain not yet built.**
  These tests document desired Tunisian VAT/stamp-duty cost-allocation behavior for an as-yet-unbuilt purchase-invoice flow. Until a `PurchaseInvoice` document type and its `/post` endpoint exist, the tests cannot run; they are valuable as **executable acceptance criteria** for that future module. Recommendation: leave skipped, add a tracking item under Section 1 of the deferred-items doc when purchase-invoice work is scheduled.

---

## Summary of verdicts

| Skip site | Tests | Verdict |
|---|---:|---|
| `TaxCalculationTest::test_it_does_not_apply_stamp_duty_for_drafts` | 1 | Owned elsewhere (M3 / refund-flow session) |
| `TaxSnapshotInvoiceTest` (class) | 6 | Re-enable now (with rehab) |
| `TaxSnapshotCreditNoteTest` (class) | 3 | Re-enable now (with rehab) — coord with refund-flow session |
| `TaxSnapshotSalesOrderTest` (class) | 4 | Re-enable now (with rehab) |
| `TaxSnapshotPurchaseOrderTest` (class) | 5 | Re-enable now (with rehab) |
| `TaxRecoverabilityTest` (5 per-method) | 5 | Still blocked — purchase-invoice domain not built |
| **Total** | **24** | **18 to re-enable, 5 still blocked, 1 owned elsewhere** |

(The "24" count matches the deferred-items L4 entry.)

---

## Suggested follow-up PRs (out of scope here)

1. **PR-A — Tax snapshots on document confirmation** (one PR, four classes):
   re-enable `TaxSnapshotInvoiceTest`, `TaxSnapshotCreditNoteTest`, `TaxSnapshotSalesOrderTest`, `TaxSnapshotPurchaseOrderTest`. Repair fixtures, run, repair regressions, ship. Coord with refund-flow session on credit-note semantics.

2. **PR-B — none for `TaxRecoverabilityTest`.** Add a fresh deferred-items entry "build `PurchaseInvoice` document type + post flow + Tunisian recoverability cost-allocation" and link these tests as the spec.

3. **No action here for stamp-duty draft guard** — owned by refund-flow / M3 session.

---

## Out of scope for this doc

- The actual un-skip work (any of the rehabs above).
- Any production code change. This is a triage / inventory doc only.
- The stamp-duty draft test (M3) — owned by the refund-flow / deferred-items coordination session.
