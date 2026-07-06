# P2P Entry Points — W6 + W7 adversarial code review

- **Scope:** uncommitted diff vs HEAD (`cbc14883f`) in worktree `apps/erp.p2p-flow`, branch `feat/p2p-entry-points`. W6 (entry/exit notes + GRN print) and W7 (revert + cancel consolidation).
- **Refs:** plan `docs/superpowers/plans/2026-07-05-p2p-entry-points-plan.md` (Waves 6+7), spec `…/specs/2026-07-05-p2p-entry-points-design.md` §8+§9, task log `docs/sessions/TASK-LOG-w6w7.md`.
- **Verdict: NEEDS-FIX-ROUND** (3 MAJOR, several MINOR). Backend revert/cancel logic and guards are correct and well-pinned; the fix round is driven by the GRN-print auth break, an unbounded projection, and a brief-mandated missing test.

---

## BLOCKER
None.

## MAJOR

### M1 — GRN print link bypasses the authenticated API client (breaks under token auth + multi-company)
`apps/web/src/features/purchases/GoodsReceiptListPage.tsx:559` renders a plain
`<a href={`/api/v1/goods-receipts/${receipt.id}/pdf`} target="_blank">`. This is the **only** plain
`href` to `/api/v1` in the entire web app (`grep 'href=.*api/v1' apps/web/src` → this line only). Every
other PDF download (`useDownloadPdf`/`usePreviewPdf`/`usePrintPdf`, `useDocumentPdf.ts`) goes through the
axios client with `responseType:'blob'`, which is the only path that attaches the auth headers set in
`apps/web/src/lib/api.ts:111-116`: `Authorization: Bearer <token>` and `X-Company-Id`.
A top-level browser navigation carries neither. Consequences:
- **Token-auth deployments** (staging/demo run with empty `SANCTUM_STATEFUL_DOMAINS` → pure bearer token, no session cookie) → the navigation is unauthenticated → **401**. The W6.2 deliverable (working print button) does not work in the demo target.
- **Multi-company:** with no `X-Company-Id`, `CompanyContextMiddleware::resolveCompanyId` (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:104-111`) falls back to the user's *default* company; `GoodsReceiptController::receiptForCurrentCompany` `findOrFail`s scoped to that company → **404** for a receipt viewed in any non-default company.
- **Fix:** download via a mutation through the `api` client (blob → object URL), mirroring `useDownloadPdf`.

### M2 — Entry/exit projection loads the full `stock_movements` history into memory + N+1 source resolution
`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:51` runs
`$query->get()` with **no row limit** (only optional location/date narrowing), then filters, groups and
paginates entirely in PHP (`:52-70`). `stock_movements` is the highest-volume table in the system (every POS
sale line, every receipt). The FE requests `per_page=100` with **no default date range**
(`EntryExitNotesPage.tsx:75-78`), so every page load pulls the tenant/company's entire movement history.
Compounding this, `sourceType()` (`:164-167`) issues a fresh `Document::find` **per movement** whose
`reference_type` is `Document`, and it is invoked up to 3× per movement (in `matchesSourceType`, inside
`groupKey` during `groupBy`, and in `formatNote`) with no memoization/eager-load → N+1 (the exact
"N+1 on source-label resolution" the brief called out). Read-only correctness is fine; scalability is not.
- **Fix:** push direction/source-type/pagination toward SQL (or at least cap + require a date window), and
  batch-resolve referenced Documents once (`whereIn` + keyBy) instead of per-row `find`.

### M3 — SO-revert reservation release is untested; no happy-path PO revert test
`revertSalesOrder` (`DocumentPostingService.php`) releases reservations via
`releaseBySource(… ReleaseReason::OrderModified …)`, and the brief explicitly required locating **and
asserting** this release. It has **zero test coverage**: `DocumentPostingServiceTest` adds only Quote-revert
(happy) and PO/DeliveryNote *rejection* cases; `DocumentRevertEndpointTest` covers only Quote + permission.
There is also no success-path test for a clean PurchaseOrder reverting to Draft — every PO test is a
rejection branch, so the PO "reverts when clean" path and the whole `revertSalesOrder` path (incl. the
`OrderModified` reason and confirmed→draft field clearing) are unverified. The cancel-path SO release *is*
pinned (`DocumentCancelConsolidationTest::test_sales_order_cancel_pin_releases_reservations`), but that is a
different code path (`ReleaseReason::Cancelled`).
- **Fix:** add a revert test asserting the reservation is released (`released_at`, `ReleaseReason::OrderModified`, `StockLevel.reserved` decremented) and a clean-PO revert-to-Draft test.

---

## MINOR

- **m1 — PO receipts check deviates from plan + adds cross-module model coupling.** `revertPurchaseOrder`
  inlines `GoodsReceiptLine::query()->whereIn('po_line_id', …)->exists()` instead of the plan-mandated
  `GoodsReceiptService::poLineIdsWithReceipts` (Task 7.1 / self-review "1.1↔7.1"). Functionally equivalent
  (that method wraps the same status-agnostic query — counts draft *and* posted receipt lines), but it
  bypasses the Inventory service boundary and imports `Inventory\Domain\GoodsReceiptLine` + queries
  `Treasury\Domain\PaymentAllocation` directly from a Document domain service (rule 6). Pre-existing
  precedent exists (`Product` import), so low risk — prefer routing through the Inventory service.

- **m2 — GRN print button uses hardcoded Tailwind colors.** `GoodsReceiptListPage.tsx:560-561`
  (`border-gray-300 bg-white text-gray-700 hover:bg-gray-50`) instead of `designTokens` (rule 18, new code).

- **m3 — `RefundController` `code` is free text for non-domain errors.** `RefundController.php:62,90` set
  `code => $e->getMessage()`. Only `DOCUMENT_HAS_PAYMENTS` is a stable token; any other `\Exception` leaks a
  human sentence as `code`. In-scope and back-compat-safe (keeps `error`), but `code` should be
  machine-stable. The RefundController change itself is justified — the `/invoices/{id}/cancel` route flows
  through it and the paid-invoice pin asserts `assertJsonPath('code','DOCUMENT_HAS_PAYMENTS')`. Not scope creep.

- **m4 — GRN PDF `formatNumber` casts quantity to `(float)`.** `GoodsReceiptPdfService.php:95`
  (`$formatter->format((float) $number)`) — display-only, not a stored value, so outside the PHPStan
  decimal-property guard, but the precision contract discourages float on quantity even for rendering.

- **m5 — Missing negative FE/BE type-gating tests.** No test that revert is hidden for an *unsupported* type
  (e.g. invoice) when the user *has* `documents.update`; confirmed fiscal-doc revert rejection is covered only
  transitively via the DeliveryNote `default`-arm test. Code is correct (`canRevert` type whitelist in
  `DocumentActionBar.tsx:138-141`; `revert()` `match` default → `DOCUMENT_REVERT_NOT_SUPPORTED`).

---

## Verified correct (spot checks)

- **Revert guard matrix** (`DocumentPostingService::revert`): Quote status-only; SO releases reservations;
  PO blocked on any receipt line (`PURCHASE_ORDER_HAS_RECEIPTS`), on direct RFQ source
  (`source?->type === PurchaseQuoteRequest` → `PURCHASE_ORDER_FROM_RFQ`), and on SI refs via a correctly
  **parenthesized** `where(fn) { source_document_id = po OR whereJsonContains(payload->supplier_invoice->source_document_ids, po) }`
  (`PURCHASE_ORDER_HAS_SUPPLIER_INVOICES`); everything else → `DOCUMENT_REVERT_NOT_SUPPORTED`. Posted revert
  impossible (only `isConfirmed()` proceeds); fiscal Confirmed docs rejected via `default`. Docblock states
  no event mutations (rule 8) — verified no `event(...)` in any revert path.
- **Cancel consolidation:** all 4 pins present and real — paid/allocated posted invoice → 422
  `DOCUMENT_HAS_PAYMENTS` (endpoint, `DocumentCancelConsolidationTest:121`); unpaid posted invoice →
  Cancelled + `FiscalStatus::Voided` (`:135`, and `InvoiceDocumentTest` flipped to
  `test_unpaid_posted_invoice_can_be_cancelled_and_voided`); SO cancel releases reservations (`:145`);
  RefundService invoice + credit-note payload pins (`:190`,`:201`). `SalesOrderService::cancel` and
  `RefundService::cancelInvoice/cancelCreditNote` now delegate to `DocumentPostingService::cancel` with no
  behavior change (SO release + event moved verbatim into `cancelSalesOrder`, event dispatched in
  `DB::afterCommit` — an improvement; `SalesOrderCancelled` unchanged, rule 8 respected). `hasPaymentAllocations`
  (`whereNotNull('payment_id')->where('amount','>',0)`) blocks any non-zero allocation — consistent with the
  §9.3 "zero allocated payments" rule.
- **Entry/exit projection is read-only** (get/filter/map only). Tenant+company scoped (`:32-33`). Direction
  via `directionForRow()` (signed before/after delta) — a StockTransfer yields two rows differing in
  direction+location, and `groupKey` includes both → correctly one IN note + one OUT note. Route perm
  `inventory.view` mirrors `stock-movements.index`.
- **GRN PDF:** dedicated `GoodsReceiptPdfService` (not routed through `DocumentPdfService::generate(Document)`),
  same DomPDF renderer/options as `DocumentPdfService`; blade `resources/views/inventory/goods_receipt.blade.php`
  contains company header, GRN number, supplier, BL ref+date, PO number, location, lines; posted-only guard
  (`GoodsReceiptController::pdf` → `GOODS_RECEIPT_PDF_NOT_POSTED` 422); company+tenant scoped via
  `receiptForCurrentCompany`; sane filename; route perm `inventory.view` mirrors `goods-receipts.show`.
- **FE revert:** action gated on `hasPermission('documents.update')` + type whitelist + `status==='confirmed'`
  (`DocumentActionBar.tsx:138-141`), backend route `can:documents.update`. Wired on all three detail pages via
  a `ConfirmDialog`; `useRevertDocument` uses `tenantScopedKey` + scoped invalidation predicate.
  `EntryExitNotesPage` uses `tenantScopedKey`, design tokens, and en/fr/**ar** locale parity present
  (verified all three namespaces carry `entryExitNotes`, `printGrn`, `revertToDraft`, `revertedToDraft`).
