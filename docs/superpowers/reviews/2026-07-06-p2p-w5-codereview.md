# P2P Wave 5 (invoice-first) — Adversarial Code Review

- Date: 2026-07-06
- Branch: `feat/p2p-entry-points` (worktree `apps/erp.p2p-flow`)
- Scope: uncommitted diff vs `HEAD` (`eb403782d`) = Wave 5
- Refs: plan Rev 2 WAVE 5, spec §7, `docs/sessions/TASK-LOG-w5.md`
- Verdict: **NEEDS-FIX-ROUND**

The backend spine (delivered saga, parked-pending shape, posting guards, approval gate,
snapshot extraction) is correct, order-safe, and covered by non-vacuous tests. The
fix-round drivers are one real linking integrity gap (MAJOR) and FE completeness
deviations from spec §7.3 that are only partly excusable as "first-pass".

---

## What passes (verified against code)

- **Delivered line-mapping is order-safe.** `InvoiceFirstOrchestrator::createDelivered`
  iterates `array_values($lines)` by `$index` and maps to
  `$purchaseOrder->lines->values()->get($index)`. `Document::lines()` is
  `->orderBy('line_number')` (Document.php:277) and `StandaloneReceiptService` assigns
  `line_number = 1,2,3…` in the same input order (StandaloneReceiptService.php:230-232),
  and creates exactly one PO line per input line. Index↔line_number alignment holds; the
  `DomainException` drift guard (InvoiceFirstOrchestrator.php:47-49) is a belt-and-braces.
  Zero-PPV holds: receipt posted at billed `unit_price`, SI created from the same lines.
- **Pending guard fires first, is explicit.** `SupplierInvoicePostingService::post`
  throws `PENDING_RECEIPT_UNLINKED` immediately after `load('lines')`
  (SupplierInvoicePostingService.php:66-70), before the lock/matcher — not the matcher's
  generic null-source exception. Controller `post()` delegates entirely with no pre-check
  (SupplierInvoiceController.php:280-304). Matches plan C-B3.
- **Pending create depth.** `CreateSupplierInvoiceService` handles null
  `source_document_ids` (→ `source_document_id = null`, CreateSupplierInvoiceService.php:59-62,
  118), null `source_line_id` (line data + skip snapshot, :108-109, :149-151), and forces
  `match_status = Unmatched` when `pending_receipt` (:208). Request relaxation is gated on
  `pending_receipt`/`invoice_first_delivered` AND `allowsInvoiceFirst()` fail-closed
  (CreateSupplierInvoiceRequest.php:68-70, 157-165).
- **Snapshot extraction is a true no-math-change delegation.** `SupplierInvoiceMatchSnapshotService::forSourceLine`
  is byte-for-byte the prior `matchSnapshotAttributes` math; both callers delegate
  (CreateSupplierInvoiceService.php:224, RematchDraftSupplierInvoicesCommand.php:150).
  Rematch keeps its 2-arg (default `qtyAlreadyPlanned='0.0000'`) behavior → no accumulation
  regression. Null-before / stamped-after semantics preserved.
- **Approval gate is precise.** `assertInvoiceFirstApproval` fires only when
  `requiresInvoiceFirstApproval()` AND the source-doc chain contains a PO with
  `payload->auto_generated.source === 'invoice_first'` (SupplierInvoicePostingService.php:517-570);
  `standalone_receipt` auto-POs do NOT trip it. Actor is explicit param from
  `$request->user()->id`; no ambient `auth()` inside the service; default-null bridge keeps
  non-invoice-first direct-call tests green. Chain detection uses portable
  `whereJsonContainsKey('payload->auto_generated')` + payload read.
- **Signature change callers updated (criterion 6).** Controller passes `$user->id`; direct
  test call sites (`SupplierInvoiceReceiptClearingTest`, `SupplierInvoiceGlTest`) invoke
  `->post($invoice)` on the default-null bridge and remain green (non-invoice-first).
- **Seeder names match (criterion 8, partly).** `supplier-invoices.link-receipts` and
  `supplier-invoices.approve-invoice-first` are seeded (RolesAndPermissionsSeeder.php:124-125,
  433, 654) and match the route (`routes.php:112`) and gate string exactly.
- **FE contracts.** `useLinkSupplierInvoiceReceipts` uses `tenantScopedKey` and
  `apiPost` (api.ts:376-390); idempotency key generated once via
  `useState(newIdempotencyKey)` (W4 pattern, CreateSupplierInvoicePage.tsx:117); money/qty
  via `MoneyInput`/`QuantityInput`, no `parseFloat`; locale keys present in en/fr/ar.
- **Aged-payables rider** exercises the real service chain (StandaloneReceiptService →
  CreateSupplierInvoiceService → SupplierInvoicePostingService) and asserts drop-out; no
  production change needed. In scope; no out-of-scope sweep drift (criterion 9 clean).

---

## MAJOR

- **M1 — Linking stamps source_line_id with no product-identity check.**
  `SupplierInvoiceReceiptLinkingService::link` re-asserts company / supplier / currency /
  posted-only (SupplierInvoiceReceiptLinkingService.php:57-86) but never compares the
  invoice line's product/variant to the receipt line's (PO line) product. A user pasting a
  wrong `receipt_line_id` links an invoice line for product A to a receipt line for product
  B; the server writes `source_line_id = poLine.id` (:88, :107) and stamps a
  `price_match_basis` computed from the wrong product's receipt (:119-121) with no guard.
  This is server-authoritative and silent. Add a per-link assertion that
  `invoiceLine.product_id === receiptLine.product_id` (and variant, when set), rejecting
  with a domain error. Review criterion 3.

- **M2 — FE linking + create surfaces are raw-UUID paste boxes, not the spec §7.3 selector.**
  Spec §7.3 mandates the linking action open "the Wave 7 receipt-line selector filtered to
  the supplier". The detail page instead renders one free-text `<input>` per line for the
  receipt-line UUID (SupplierInvoiceDetailPage.tsx:325-345); the create page likewise takes
  the product via a free-text UUID input (`manual-line-product-id-*`,
  SupplierInvoiceCreatePage.tsx:582-593) and the location as a free-text UUID
  (`invoice-first-location-id`, :505-513). Task-log flags this as "first-pass", but as
  shipped it is not usable by an end user and deviates from spec §7.3 / review criterion 7.
  Either wire the real selector or get explicit owner sign-off to defer.

- **M3 — Invoice-first FE supports only a single manual line.**
  `manualLines` is seeded with one row and there is no add/remove control
  (SupplierInvoiceCreatePage.tsx:110-112; no `addManualLine` handler exists). The server and
  request rules accept N lines, but a delivered/pending invoice with 2+ lines cannot be
  created through the UI. Add row controls or confirm single-line is an accepted v1 cut.

---

## MINOR

- **m1 — Seeded `supplier-invoices.create-pending` is never enforced.**
  The permission exists (RolesAndPermissionsSeeder.php:123) but no route/gate references it
  (`rg create-pending apps` → only the seeder). Pending AND delivered invoice-first creation
  both go through `store` gated by `can:documents.update` + the `allowsInvoiceFirst()` policy
  flag only. Either wire the gate (e.g. in the request/controller when
  `pending_receipt|invoice_first_delivered`) or drop the dead permission. Review criterion 8.

- **m2 — Partial link clears `pending_receipt` unconditionally.**
  `link()` sets `pending_receipt => false` (SupplierInvoiceReceiptLinkingService.php:132)
  even when only some invoice lines are in `links`; unlinked lines keep null
  `source_line_id`. The SI loses its pending badge/guard yet remains unpostable (matcher
  null-source). Consider clearing the flag only when every non-bonus line is linked, or
  document the partial-link contract.

- **m3 — `link()` accepts any Draft SI, not only pending ones.**
  Guard is `type === SupplierInvoice && status === Draft` (:35); it does not require
  `pending_receipt === true`. A receipt-first draft could have its `source_line_id`s
  re-pointed and snapshots re-stamped via this endpoint. Permission-gated, low risk, but
  tighten the precondition.

- **m4 — Index pending filter uses `whereJsonContains(..., true)` not the portable key API.**
  SupplierInvoiceController.php:107 uses `whereJsonContains('payload->supplier_invoice->pending_receipt', true)`,
  whereas the rest of Wave 4/5 standardized on `whereJsonContainsKey`/read (plan Task 4.3,
  rule 20). Works (SQLite-verified per task-log) but inconsistent; confirm PG jsonb boolean
  containment and align for maintainability.

- **m5 — Hardcoded Tailwind grays in new create-page markup.**
  `divide-gray-200` / `divide-gray-100` on the manual-line table
  (SupplierInvoiceCreatePage.tsx, manual table `thead`/`tbody`) instead of design tokens
  (rule 18).

- **m6 — Approval gate can 500 on tenants missing the permission record.**
  `$actor->hasPermissionTo('supplier-invoices.approve-invoice-first')`
  (SupplierInvoicePostingService.php:565) throws `PermissionDoesNotExist` (→ 500) rather
  than 422 where the permission is not seeded — the known staging seeder-sync gap. Consider
  `hasPermissionTo(..., 'api')` guarded or a `try`/catch → domain error.

---

## Verdict

**NEEDS-FIX-ROUND.** Fix M1 (linking product-identity guard) as a hard requirement; land or
explicitly defer-with-sign-off M2/M3 (FE completeness vs spec §7.3); address the MINORs at
discretion. Backend correctness, ordering, guards, gate, and snapshot extraction are sound.
