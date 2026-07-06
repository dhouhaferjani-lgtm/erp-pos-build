# Adversarial Code Review — Procurement Wave 7 (supplier-invoice creation UI + read endpoints)

- **Scope:** UNCOMMITTED working-tree diff in `apps/erp.procurement-v2` (branch `feat/procurement-wave3`, HEAD `d37db55cb` = Wave 6). 14 modified files + 3 new (`SupplierInvoiceCreationReadApiTest.php`, `SupplierInvoiceCreatePage.tsx`, `SupplierInvoiceCreatePage.test.tsx`).
- **Spec:** `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` Gap 3 §3.1–§3.10; brief `docs/sessions/CODEX-TASK-wave7.md`.
- **Reviewer verification actually run:** scoped PHPStan (`app/Modules/Procurement app/Modules/Document/Presentation`) → 0 errors; `tsc --noEmit` → clean; scoped ESLint on changed FE files → 0 errors (63 warnings, incl. NEW hardcoded-color warnings); `vitest run src/features/purchases/` → 59/59; BE test by path → 3 passed / 19 assertions; **`vitest run src/features/documents/purchase-orders/` → 9/9 FAIL with diff, 9/9 PASS at HEAD `d37db55cb` (verified in a throwaway worktree, since removed)** — Codex's scoped gate (`src/features/purchases/supplier-invoices/` only) could not see this.

## Verdict: **NEEDS-REVISION**

Nothing is architecturally unsound and the three entry points converge on one payload shape as required — but the diff breaks 9 existing tests (hard gate), the primary flow 422s on any PO with an open free/bonus window, and the multi-receipt prefill grain produces provably wrong creation snapshots. All fixes are local.

---

## BLOCKER / MAJOR

### W7-1 — MAJOR (regression, verified red): `useNavigate()` added to PurchaseOrderDetailPage breaks 9 existing tests
`apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:2,92` adds `useNavigate()`. `apps/web/src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx:39-46` mocks `react-router-dom` keeping `...actual` and only stubs `Link`/`useParams`, rendering **without a Router** — the real `useNavigate` now throws `useNavigate() may be used only in the context of a <Router>` in all 9 tests. Verified: 9/9 pass at HEAD, 9/9 fail with the diff. (The 2 failures in `DocumentForm.tenantScope.test.tsx` / `DetailPagesAndRepository.tenantScope.test.tsx` are **pre-existing at HEAD** — not Wave 7.)
**Fix:** add `useNavigate: () => vi.fn()` to that test file's router mock (in-scope collateral of the page change), or wrap the render in `MemoryRouter`. Re-run the whole `src/features/documents/` scope before merge.

### W7-2 — MAJOR: free-window-only receipt lines poison the payload → guaranteed 422 on save
- `PurchaseOrderController::receiptLines` `uninvoiced=1` filter (`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:817-822`) returns lines where `free_qty > free_quantity_invoiced` even when the **paid** window is exhausted. Same union in the `has_uninvoiced` index filter (`:230-244`).
- The create page maps **every** returned line (`SupplierInvoiceCreatePage.tsx:104-130`) with `quantity = positiveSub(received_qty, quantity_invoiced, 4)` → `"0.0000"` for a free-only-open line, and `buildPayload` (`:174-197`) includes it.
- `CreateSupplierInvoiceRequest` `lines.*.quantity` has `gt:0` (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:68-72`) → the **whole invoice** 422s, including its legitimately billable lines.
- This is not exotic: v1 UI never emits `is_bonus_line` lines, so after the FIRST invoice fully covers the paid window of a bonus-carrying receipt (order 10 + 2 free, invoice 10), that line stays "uninvoiced" forever, the PO stays in `has_uninvoiced`, and the PO-detail button stays enabled (`(uninvoicedReceiptLines?.length ?? 0) > 0`, `PurchaseOrderDetailPage.tsx:354`) → dead-end 422 page.
- Split-window note (attack surface 1): the endpoint's OR-of-two-windows is *not* the superseded Rev-2 arithmetic union `received_qty + free_qty − quantity_invoiced` — a paid-over-invoiced line with free open is still (correctly, under §2.5 Rev 3.2) reported. The filter itself is defensible for a UI that could bill the free window; **this v1 UI can't**, which is the bug.
**Fix (either/both):** FE — drop lines with `bccomp(matchableQty,'0') <= 0` from prefill and payload (and disable the PO-detail button when no line has paid matchable > 0); BE — make `uninvoiced=1` paid-window-only for this endpoint (documented as the v1 prefill source, §3.4) until the bonus-line UI exists.

### W7-3 — MAJOR: multi-receipt PO → one invoice line per receipt line, but creation snapshots double-consume the first FIFO window
FE emits one payload line **per receipt line**, all sharing the same `source_line_id` (`SupplierInvoiceCreatePage.tsx:111-129,180-185`). The request accepts this and posting is safe (aggregate per PO line: `SupplierInvoiceMatcher::buildQtyGroupStatuses` sums per `source_line_id`; `SupplierInvoicePostingService` consumes receipt lines FIFO once, `:104-186,283-330`). **But** the creation snapshot is computed per line via `CreateSupplierInvoiceService::matchSnapshotAttributes` → `ReceiptLineConsumptionPlanner::plan($sourceLineId, $qty)` (`CreateSupplierInvoiceService.php:158-166,204-245`), and the planner reads **persisted** `quantity_invoiced` (`ReceiptLineConsumptionPlanner.php:28-55`) which is only incremented at POST time — so for two receipt lines 12.500 (qty 10) / 12.800 (qty 5) on one PO line, BOTH invoice lines plan against receipt 1's window: line 2 (billed at 12.800) gets `price_match_basis` = 12.500 and `matched_receipt_line_id` = receipt 1. Result: spurious `PriceVariance` `match_status` on a payload that is economically exact (each receipt billed at its own price), wrong provenance columns, and the FE preview said "Matched" (basis = its own `received_unit_price`). The matcher then trusts the wrong snapshot forever (`priceBasisForInvoiceLine` prefers `price_match_basis`, `SupplierInvoiceMatcher.php` bottom).
**Fix options:** (a) FE aggregates to **one invoice line per PO line** at the FIFO-weighted price (weighted by paid matchable across its receipt lines, bc string math) — payload then matches the exact semantics Wave 5 was built and tested for; or (b) BE `matchSnapshotAttributes` tracks in-memory consumed windows across lines of one `create()` call. (a) is smaller and stays inside Wave 7 scope.

### W7-4 — MAJOR: match-preview basis diverges from the backend matcher basis (Matched shown where backend flags PriceVariance)
Preview compares entered `unitPrice` against `received_unit_price ?? PO unit_price` with strict equality (`SupplierInvoiceCreatePage.tsx:49-57,114`). The backend basis is the FIFO-weighted receipt-line **`accrual_unit_cost`** (spec §2.5 line 352; `ReceiptLineConsumptionPlanner.php:44`; snapshot in `CreateSupplierInvoiceService.php:204-245`), which is the **landed** cost — `received price + freight share + non-recoverable tax share` (`GoodsReceiptService.php:352-377`, spec §2.4 line 321). Whenever a receipt batch carries freight, preview says Matched while the saved draft comes back PriceVariance (tolerance permitting) — the exact wrong-direction inconsistency attack surface 3 asks about. Ironically the endpoint already returns `accrual_unit_cost` (`PurchaseOrderController.php:832`) and the page ignores it.
Tolerance handling itself is per-brief: no policy read endpoint exists (verified — nothing exposes `ProcurementPolicy`/`variance_tolerance*` as a read), chips-only + logged deviation (`SupplierInvoiceCreatePage.tsx:98-102`) is the sanctioned fallback; its only effect is over-strict chips (variance shown that backend would tolerate) — safe direction. Quantity chip is also safe-direction only (per-line vs backend aggregate can over-warn, never under-warn).
**Fix:** keep prefilled `unit_price = received ?? PO` (correct — it's what the supplier bills), but drive the CHIP comparison from `accrual_unit_cost` (weighted per W7-3's chosen grain).

### W7-5 — MAJOR: attachment failure after draft creation → invisible orphan draft + easy duplicates
`handleSubmit` (`SupplierInvoiceCreatePage.tsx:199-213`): `createInvoice` succeeds, then `Promise.all(uploads)` rejects → single catch shows a generic error toast and **does not navigate**. The draft exists but the user is left on the create form believing the save failed; pressing Save again creates a **second draft** (duplicate-reference warning only fires on blur and is non-blocking).
**Fix:** after a successful create, always `navigate` to the detail page; run uploads in a separate try/catch and surface failures as a warning toast ("draft created, N attachment(s) failed — retry from the detail page", which has upload UI).

### W7-6 — MAJOR: `duplicate-reference` endpoint 500s on a non-UUID `partner_id`
`SupplierInvoiceController::duplicateReference` (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:145-171`) passes the raw query string into `where('partner_id', $partnerId)` — a PG `uuid` column; any non-UUID value → SQLSTATE 22P02 → 500 (documented codebase pitfall: validate `Str::isUuid()` before uuid `where`s). No route constraint possible (query param). Scoping/injection are otherwise fine: `baseQuery()` company-scopes (`HandlesDocuments.php:42-47`), `ofType(SupplierInvoice)`, parameterized bindings, and the `(company_id, external_document_number)` index exists (`2025_12_11_100001_add_is_historical_to_tables.php:36`).
**Fix:** `if (! Str::isUuid($partnerId)) { return exists:false; }` (same graceful shape as the empty-param path already present at `:148-153`).

## MINOR

### W7-7 — MINOR (perf): `has_uninvoiced` correlated subquery can't use the only receipt-line index
`PurchaseOrderController.php:230-244` filters `goods_receipt_lines` by `company_id` + correlated `po_line_id`, but the table's only lookup index is `(tenant_id, po_line_id)` (`2026_07_04_100000_create_goods_receipts_tables.php:58`) and `tenant_id` is absent from the predicate; there is no index on `company_id` → seq scan of receipt lines (PG will likely decorrelate to one hash semi-join, so no N+1, but still a full scan per index request). The sibling `receiptLines()` endpoint DOES filter tenant_id (`:805-812`) — inconsistent.
**Fix:** add `->where('goods_receipt_lines.tenant_id', $tenantId)` inside the exists.

### W7-8 — MINOR (i18n): match-chip labels are untranslated in ALL three locales
`create.match.matched/priceVariance/quantityVariance` are the literal strings `"Matched"/"PriceVariance"/"QuantityVariance"` in en, **fr and ar** (`apps/web/src/locales/fr/purchases.json`, `ar/purchases.json` new blocks) — raw English camelCase shown to fr/ar users, while proper translations already exist at `supplierInvoices.matchStatus.*` ("Rapprochée", "Écart de prix", …). Reuse or translate.

### W7-9 — MINOR (rule 18): new buttons use hardcoded Tailwind colors
`DocumentActionBar.tsx:216` (`bg-blue-600 … hover:bg-blue-700`), `GoodsReceiptListPage.tsx` header button + `text-yellow-700` warning span + checkbox `border-gray-300 text-blue-600 focus:ring-blue-500`. These add NEW ESLint hardcoded-color warnings on touched lines; `SupplierInvoiceCreatePage.tsx` itself is token-clean. Migrate to `tokens.button.primary` / `textColors.warning` etc.

### W7-10 — MINOR (spec deviation, entry point b): "Invoice receipts" selects POs, not receipt rows
Spec §3.2 (line 421) wants receipt-row selection grouped by PO on a "real receipt-header list"; the implementation adds checkboxes to the existing received-**PO** list (`GoodsReceiptListPage.tsx:366-375`). The single-PO guard is functionally equivalent (action disabled when >1 selected, tooltip + visible message) and the payload converges, but: fully-invoiced POs are selectable → dead-end empty create page; `selectedInvoicePoIds` never resets on tab switch; the `entry=receipts` query param is dead. Acceptable for v1 under the brief's wording — flagging the deviation.

### W7-11 — MINOR (gating): buttons not permission-gated; FE/BE permission mismatch
Neither new button checks a permission (PO-detail button shown to all viewers; route `RequirePermission permission="purchases.create"` catches navigation — `routes/index.tsx:897`). Backend create is `can:documents.update` (`Procurement/Presentation/routes.php:85-88`), so a `purchases.create`-only user passes the route and 403s on save. Consistent with existing purchases pages (quote-requests use the same pattern), hence MINOR — but the attack surface asked, so recorded.

### W7-12 — MINOR (test quality gaps vs spec §3 test plan, line 505)
FE tests DO assert payload shape + prefill math (`6.0000` = 10−4, price fallback, string discipline) and the blur-triggered duplicate warning — good. Missing: cross-PO guard blocked message (explicitly demanded by the spec), variance chips (only `matched` asserted), multi-receipt prefill, zero-matchable line handling (would have caught W7-2), 422 surfacing. BE test missing: free-only-open line exposure, other-company isolation for all 3 endpoints, non-UUID partner_id (would have caught W7-6), `has_uninvoiced` free-window case. Wave-7 spec exit also names a Playwright E2E (received-PO → create → Matched → post) — absent; the brief's gates didn't demand it, noting for the wave-8 ledger.

### W7-13 — INFO
- Receipt-lines query fires on every PO detail render incl. drafts (`PurchaseOrderDetailPage.tsx:129`) — harmless extra request; gate on `confirmed|received` if you touch the file again.
- `vat_rate` is a free-text input (`SupplierInvoiceCreatePage.tsx:434-438`); garbage → generic 422 toast (`getErrorMessage` returns only `data.error.message`, no field errors — `lib/api.ts:61-73`).
- `notes` is accepted end-to-end (request `notes` rule + service persists) — not silently dropped.
- bc string discipline holds everywhere (no `parseFloat`/`Number` on money/qty; `safeBig` tolerates empty strings, no render crash); `tenantScopedKey` on all new query keys; `useUploadAttachment` widening is backward-compatible; route `supplier-invoices/new` correctly registered before `:id`; `whereUuid` added to SI show/match/post routes (nice hardening); DocumentActionBar change is `type === 'purchase_order'`-fenced — other document types unaffected; GoodsReceiptListPage edits don't touch Wave 6's receive-dialog toast paths (7/7 page tests green).

## Gate status as re-verified by this review
| Gate | Result |
|---|---|
| PHPStan (Procurement + Document/Presentation) | OK |
| `tsc --noEmit` | OK |
| ESLint changed files | 0 errors (new hardcoded-color warnings — W7-9) |
| `vitest src/features/purchases/` | 59/59 |
| BE `SupplierInvoiceCreationReadApiTest` | 3/3 (19 assertions) |
| **`vitest src/features/documents/purchase-orders/`** | **0/9 with diff, 9/9 at HEAD → W7-1** |

## Required for SHIP
W7-1 (fix broken tests), W7-2 (zero-qty 422), W7-3 (snapshot grain), W7-5 (orphan draft), W7-6 (500 guard). W7-4 strongly recommended in the same pass (one-line basis swap once W7-3 picks a grain). W7-7..9 are cheap and should ride along; W7-10..12 may be deferred to Wave 8 with a note.
