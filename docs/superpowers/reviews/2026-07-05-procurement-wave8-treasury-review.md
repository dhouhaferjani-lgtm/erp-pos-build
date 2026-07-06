# Procurement Wave 8 (Multi-PO Supplier Invoices) — Treasury/GL Adversarial Review

Reviewer: treasury-reviewer (adversarial, code-grounded)
Date: 2026-07-05
Worktree: `apps/erp.procurement-v2` (branch `feat/procurement-wave3`), UNCOMMITTED working tree
Spec: `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` §3.2 "Rev 3 (S4)" + §3.11 + M8
Brief: `docs/sessions/CODEX-TASK-wave8.md`

## VERDICT: NEEDS-REVISION

W8-1 and W8-2 are code-proven regressions (SQL dumped via `toSql()`), W8-1 is a
silent cross-module correctness break on a shipped sales path. Multi-PO clearing,
persistence normalization, same-supplier guard, and lock ordering are otherwise sound
and well-tested. Fix W8-1/W8-2/W8-3 before merge.

---

## Findings

### [CRITICAL] W8-1 — `childDocuments()` `orWhere` poisons every consumer that appends AND-filters
`apps/api/app/Modules/Document/Domain/Document.php:358-367`

The relation now chains `->orWhere(...)` onto the base `hasMany`. Any caller that
appends further `->where()` constraints gets ambiguous SQL precedence (AND binds tighter
than OR), so the base `source_document_id` branch escapes the appended filters.

Proven — `getFulfillmentStatus()` (`Document.php:721-724`,
`->where('type', DeliveryNote)->whereIn('status', ['confirmed','posted'])`) compiles to:

```
where ("source_document_id" = ? and "source_document_id" is not null
       or (tenant_id=? and company_id=? and type='supplier_invoice' and payload @> ?)
          and "type" = ? and "status" in (?, ?))
```

= `(source_document_id = id AND not null) OR (payload-branch AND type='delivery_note' AND status IN(...))`.

Branch A returns **all** child documents of any type/status. A sales `Invoice`/`SalesOrder`
that has any non-delivery-note child (e.g. a credit note) is then reported as having
delivery notes → wrong `FulfillmentStatus`, surfaced on the hot serialization path
`DocumentData.php:159` for every Invoice/SalesOrder. This is a cross-module regression
caused by a procurement change; existing `DocumentFulfillmentIsPhysicalTest` only covers
child-less cases, so it is uncaught.

Fix: do NOT attach `orWhere` to the base relationship. Either (a) make `getAllDescendants`
run two queries (source_document_id children + a scoped payload-union query) and merge, or
(b) keep the base `hasMany` clean and wrap the union in a nested `where(fn ...)` such that
appended constraints apply to both branches. Re-run `getFulfillmentStatus` SQL after.

### [IMPORTANT] W8-2 — Eager load `with(['childDocuments'])` is dead (returns empty)
`apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:206`

Because the `orWhere` closure captures `$this->{id,tenant_id,company_id}`, during eager
loading the relation is resolved on a fresh empty model (all null). Proven eager SQL:

```
where ("source_document_id" is null and "source_document_id" is not null
       or (tenant_id is null and company_id is null and type='supplier_invoice' and payload @> 'null')
          and "source_document_id" in (?)) ...
```

Both OR branches are unsatisfiable for real rows → the eager-loaded `childDocuments`
attribute is always empty. `related()` only works because `getDocumentChain()` →
`getAllDescendants()` re-queries via the method (fresh, correct). The eager load is dead
and would silently return empty for any future consumer that reads the attribute. Fix:
drop `childDocuments` from the `with(...)` at :206 (or make the relation eager-safe as part
of W8-1's fix).

### [IMPORTANT] W8-3 — Posting guard runs BEFORE idempotency no-op → non-idempotent re-post on drift
`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:91` (assert) vs `:94-101` (idempotency)

`assertLineParentsShareInvoiceHeader()` executes before the "already posted" no-op check.
If a parent PO's partner/currency/company drifts AFTER a successful post, a repeat `post()`
throws `DomainException` instead of returning cleanly — breaking idempotent re-post in the
drift edge (attack surface #5). Move the idempotency `exists()`/return block ahead of the
assert. Secondary: the assert reads the PO **header** via `$poLine->document` but only PO
**lines** are `lockForUpdate()` (`:74-80`); the header partner/currency read is not
lock-protected against a concurrent header update (attack surface #4 — small race window,
header changes rare; note only).

### [IMPORTANT] W8-4 — Missing create-level rejection tests for the same-supplier guard
`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php`

Task exit criterion "cross-supplier rejected 422" is not tested at the request layer. No
test covers: multi-PO cross-partner 422, cross-currency 422, `min:1` empty-array 422,
`distinct` duplicate-id 422, or a non-PurchaseOrder id in `source_document_ids`. The
posting-level partner-mismatch reject IS tested
(`SupplierInvoiceReceiptClearingTest::test_posting_rejects_invoice_line_parent_with_different_partner`),
but the create `withValidator` per-PO loop (`CreateSupplierInvoiceRequest.php:150-190`) reject
paths are unexercised. Add them.

### [MINOR] W8-5 — Ancestor direction incomplete for the invoice's own Related tab
`apps/api/app/Modules/Document/Domain/Document.php:383-387`

`getDocumentChain()` ancestors walk only the single `sourceDocument` (=`source_document_ids[0]`).
A multi-PO invoice's OWN Related-Documents tab lists only PO #1, not PO #2..N. The spec's
literal R3D-4 requirement (POs show the invoice as a descendant) is met and tested, but the
inverse view is asymmetric. Consider unioning `payload->...->source_document_ids` into the
ancestor walk. No test asserts PO #1 dedupes the invoice (linked via both column AND
payload) — `unique('id')` handles it but it is unverified.

### [MINOR] W8-6 — `getAllDescendants` recursion: no cycle guard + N+1 JSON scan
`apps/api/app/Modules/Document/Domain/Document.php:414-433`

Recursion has no visited-set; each node issues a `whereJsonContains(payload->..., id)` (JSON
containment, no GIN index) → N+1 with a JSON scan per node on large tenants. No infinite
loop in normal data (payload holds PO ids; invoices are not self-referential), so cycle risk
is corruption-only. Pre-existing N+1 pattern, widened by the union. Note for hardening.

### [MINOR / pre-existing] W8-7 — No PO-status guard: a Cancelled/Draft PO can be in the set
`CreateSupplierInvoiceRequest.php:150-165`, `SupplierInvoicePostingService.php:441-456`

Both create and posting guards check `type === PurchaseOrder` only, not status. A cancelled
PO of the same partner/currency/company can be listed. Matches pre-existing single-PO
behavior; the invoiced-qty cap limits blast radius. Note for hardening.

---

## Verified sound (no action)

- **Persistence contract:** `source_document_id = source_document_ids[0]` exactly as
  submitted, order preserved (`CreateSupplierInvoiceService.php:57-58, :113, :131`;
  `prepareForValidation` merges input array unsorted). Full list in
  `payload->supplier_invoice->source_document_ids`. Confirmed by
  `SupplierInvoiceApiTest::test_store_accepts_multiple_purchase_orders_and_persists_full_source_list`.
- **Backward-compat:** single `source_document_id` normalized to one-element array
  (`CreateSupplierInvoiceRequest.php:41-56`); create service falls back
  `source_document_ids ?? [source_document_id]` (`:58`).
- **Same-supplier/currency/company guard:** each id `ScopedExists::tenantAndCompany`
  (company) + `distinct` (dupes) + `array|min:1` (empty) at rule level
  (`CreateSupplierInvoiceRequest.php:73-82`); per-PO type/partner/currency in `withValidator`
  (`:130-190`). Empty/duplicate/non-PO/cross-partner/cross-currency all rejected at create.
- **Multi-PO clearing:** `SupplierInvoiceReceiptClearingTest::test_multi_po_invoice_clears_each_receipt_line_basis_and_routes_delta_to_ppv`
  asserts EXACT GL legs (Dr GR-IR 210.000, Cr PPV-income 5.000), 408 nets 0, and
  quantity_invoiced re-derivation on BOTH receipt lines AND both PO lines across two POs —
  real behavior, not "it posts". Good quality.
- **Lock ordering (R3D-11):** `orderBy('id')` on both the PO-lines lock
  (`SupplierInvoicePostingService.php:77`) and receipt-lines lock (`:86`), spanning all POs
  of the invoice → deterministic acquisition, deadlock-safe.
- **Posting guard covers all parent POs** of the invoice lines (iterates `lockedPoLines`
  derived from every `source_line_id`), under the transaction, after locks (`:91`).
- **Descendants (R3D-4):** invoice appears as descendant of the SECONDARY PO via the payload
  union; confirmed by
  `SupplierInvoiceApiTest::test_related_documents_include_multi_po_invoice_from_secondary_purchase_order`.
- **Credit-note reversal (Wave 5 LIFO):** unaffected — CN anchors on `source_document_id =
  invoice.id`, reads invoice lines' `source_line_id`/receipt lines (PO-count-agnostic); Wave 8
  does not touch `SupplierCreditNotePostingService`. Not tested for a multi-PO invoice but not
  broken.
- **FE cross-supplier block:** standalone PO multi-select is fed by
  `useOpenPurchaseOrdersForSupplier(selectedSupplier?.id)` (`SupplierInvoiceCreatePage.tsx:99,296`)
  → only same-supplier POs are selectable; backend enforces same-partner as defense in depth.

## One-line fix-before-merge
Rework `Document::childDocuments()` so the payload-union does NOT sit as an `orWhere` on the
base relationship (W8-1 corrupts `getFulfillmentStatus`), drop the now-dead
`with('childDocuments')` (W8-2), and move the posting idempotency no-op ahead of the
share-header assert (W8-3).
