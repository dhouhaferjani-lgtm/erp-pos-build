# Opus adversarial review — api.document cluster (round 1)

Review date: 2026-05-05
Branch tip reviewed: b74fc03f (Batch 3 chore-submit; production fixes at dd56691b, 5174e756, 8bd2b13a)
Reviewer: opus (first-layer adversarial review)

Verdict: REQUEST-CHANGES
Commits reviewed:
- `dd56691b` — Batch 1 (CreditNote slice, api.document.001-008)
- `5174e756` — Batch 2 (Conversion + Document/PDF + Converters, api.document.014-019, .022-033, .041) [misleadingly subject-lined "fix(inventory):" due to a concurrent agent's git race; actual diff is 5 Document production files + 1 Document test, zero Inventory files — verified via `git show --stat`]
- `8bd2b13a` — Batch 3 (Refund + residual services, api.document.009-013, .020-021, .034-040, .042)

## Summary

The api.document cluster is the largest in the sweep at 42 inventoried callsites and the three batches close them all per the inventory ledger. All 41 regression tests across the three new test files (`CreditNoteTenantIsolationTest`, `DocumentConversionTenantIsolationTest`, `RefundResidualTenantIsolationTest`) are honest: pre-fix re-runs confirm 11/11, 13/15, and 15/15 RED respectively when the fix files are reverted to their parent state, and 41/41 GREEN when restored. PHPStan and Pint are clean on every changed file. The broader Document feature+unit suite is also green at 437/437 (13 skipped). The structural-SQL-log assertions in feature tests properly require BOTH `tenant_id` and `company_id` literals in the captured WHERE clause — they would fail if either predicate is dropped. POS surface diff `dev..HEAD` is empty. `verify-history` reports 963 events / 268 callsites / 0 problems.

However, the hostile audit surfaced **one real cross-tenant cart-data-leak blind spot** (`DraftPersistenceService::addLinesBatch` lines 422 + 425) that mirrors api.document.012/013 exactly but was missed because the inventory only inventoried the single-line `addLine` path. The companion `DraftController::autoSave` route accepts unrestricted `$request->all()` with no validator on `lines.*.product_id` / `lines.*.service_id`, so a tenant-A user can POST a tenant-B product UUID and have the foreign product's `name` snapshotted into a tenant-A draft document line. This is a real exploitable defect — not just a defense-in-depth gap — because the auto-save route is explicitly granted with "no permissions required" per its route comment. I am blocking the cluster on this one finding (Finding A below). All other findings are NICE-TO-HAVE.

## Findings

### Finding A — BLOCKING: `DraftPersistenceService::addLinesBatch` is the unscoped twin of api.document.012/013

`DraftPersistenceService::saveDraft` line 133 dispatches to `addLinesBatch($document, $companyId, $userId, $data['lines'])` whenever the request payload contains 2+ lines. Inside `addLinesBatch` (lines 414–490), the product / service lookups are bare:

```php
// File: apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php
// Lines 421-425
$productIds = collect($linesData)->pluck('product_id')->filter()->unique()->toArray();
$products = Product::whereIn('id', $productIds)->get()->keyBy('id');

$serviceIds = collect($linesData)->pluck('service_id')->filter()->unique()->toArray();
$services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');
```

The batch path then calls `$product->name` / `$service->name` (lines 441-443) and persists the result as the line's `designation_snapshot` / `description`, which is read back by every subsequent reader of the draft. If the request supplies a tenant-B product UUID, the tenant-A draft will carry the tenant-B product name verbatim.

The single-line path (`addLine`, line 198–220) is correctly scoped under `api.document.012`/`api.document.013` (`Product::query()->where('tenant_id', $document->tenant_id)->where('company_id', $companyId)->find(...)`). The batch path was missed.

This is exploitable because the route `POST /api/v1/documents/auto-save` (`apps/api/app/Modules/Document/Presentation/routes.php:34`) is registered with explicit comment "no permissions required - fraud detection" and the controller `DraftController::autoSave` does:

```php
// apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:60-78
public function autoSave(Request $request): JsonResponse
{
    /** @var User|null $user */
    $user = $request->user();
    $tenantId = $user->tenant_id ?? '';
    $companyId = $this->companyContext->requireCompanyId();
    $userId = (string) Auth::id();

    $draftId = $request->input('draft_id');
    $data = $request->all();   // <-- no validator on lines.*.product_id

    try {
        $document = $this->draftService->saveDraft(
            tenantId: $tenantId,
            companyId: $companyId,
            userId: $userId,
            draftId: $draftId,
            data: $data
        );
```

There is no `$request->validate(...)` call and no FormRequest. So the cross-tenant product_id is not caught at the validator tier either. This is the only path I found in the cluster review where a route accepts user-supplied product/service UUIDs with neither validator scoping nor service-tier scoping.

**Required fix**: Apply the same `Product::query()->where('tenant_id', $document->tenant_id)->where('company_id', $companyId)->whereIn('id', $productIds)->get()` pattern to `addLinesBatch` lines 422 and 425. Add a regression test under `tests/Feature/Document/RefundResidualTenantIsolationTest.php` (or a new `DraftPersistenceTenantIsolationTest`) that POSTs to `/api/v1/documents/auto-save` with `lines: [{product_id: <tenant-B uuid>, ...}, {product_id: <tenant-B uuid>, ...}]` (≥2 lines to hit the batch path), then asserts the persisted draft line's `designation_snapshot` is empty (or null), not the foreign product name. Bump inventory entries to add `api.document.043` (`addLinesBatch` Product) and `api.document.044` (`addLinesBatch` Service).

### Finding B — NICE-TO-HAVE: `CreditNoteService::createStandaloneCreditNote` line 374 has bare `Product::whereIn` (validator-tier protected only)

```php
// File: apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:372-374
$standaloneProdIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
$standaloneProducts = Product::whereIn('id', $standaloneProdIds)->get()->keyBy('id');
```

Symmetric to Finding A but neutralized at the validator tier: `CreditNoteController:154` (api.document.003) wraps `lines.*.product_id` with `ScopedExists::tenantAndCompany('products', ...)`, so cross-tenant product_ids surface as 422 before reaching the service. Two-tier defense template (matching Treasury) would scope the service-layer read too. Not blocking because the validator covers it today; would be exposed if the validator were ever loosened or if a non-HTTP caller invoked the service directly. Suggested fix: scope `Product::whereIn` by `tenant_id` + `company_id` from the `companyContext` (already constructor-injected in this service after the Batch 1 fix).

### Finding C — NICE-TO-HAVE (defense-in-depth gap): `AgedReceivablesService::generateCustomerStatement` (api.document.009) uses `company_id` only — no `tenant_id`

The Batch 3 fix at line 179-181 reads:

```php
$partner = Partner::query()
    ->where('company_id', $companyId)
    ->findOrFail($partnerId);
```

The Partner model is BOTH tenant- and company-scoped (`tenant_id` and `company_id` columns both present per `apps/api/app/Modules/Partner/Domain/Partner.php:240,251` scopes). The cluster's stated invariant (Codex Treasury Finding 14) is "every read whose anchor came from a route param leads with BOTH tenant_id AND company_id". The fix as landed uses company_id only, breaking symmetry with how Batch 1 scoped CreditNoteService Partner reads (lines 283 etc.) — those use BOTH tenant_id + company_id.

The companion source-level invariant test (`test_aged_receivables_service_uses_company_scoped_partner_lookup`, line 410-415 of `RefundResidualTenantIsolationTest.php`) only matches `Partner::query()->where('company_id'`, so it explicitly accepts the asymmetric scoping. The remaining 6 `Document::where('company_id', $companyId)` reads in this same file (lines 41, 187, 199, 249, 314) follow the same company-only pattern — they pre-date this cluster and were not in scope. Flagging only because adding a `->where('tenant_id', $companyContext->requireTenantId())` predicate would close the symmetry gap with the rest of the sweep, and the service has constructor access to a CompanyContext via `requireCompany()->tenant_id`. Not blocking; consistent with the cluster decision but worth noting for orchestrator visibility.

### Finding D — NICE-TO-HAVE (test rigor): source-level regex matchers asymmetrically guard tenant_id only

The Batch 3 source-level invariant tests (`RefundResidualTenantIsolationTest.php` lines 417-447) for non-routed Domain services use regex patterns like:

```php
$this->assertMatchesRegularExpression('/Product::query\(\)\s*->where\([\'"]tenant_id[\'"]/', $source);
```

These match if `tenant_id` is present and would correctly fail if a future regression removes `tenant_id`. But the regex does NOT require `company_id` — a regression that removes ONLY `company_id` (keeping tenant_id) would slip past the test. The actual production code at `DraftPersistenceService:208-209,217-218,422,425` writes both predicates, so the gap is theoretical today, but the regex-only check is weaker than the SQL-log assertions in routed tests (which require BOTH literal substrings). Suggested tightening: change the regex to `/Product::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/` (or add a parallel `assertMatchesRegularExpression` on `company_id`). Not blocking; consistent with the cluster's pattern of looser source-level guards (no DB introspection available in unit-style invariant checks).

This is the inverse of the checklist Q7 worry: removing tenant_id WOULD be caught; removing company_id would NOT.

### Finding E — NICE-TO-HAVE (test rigor): `test_pdf_generate_path_uses_scoped_lookup` is a file-wide string match, not method-body anchored

Line 286-300 of `DocumentConversionTenantIsolationTest.php`:

```php
public function test_pdf_generate_path_uses_scoped_lookup(): void
{
    // ...
    $generatePathSource = file_get_contents($fileName);
    $this->assertNotFalse($generatePathSource);
    $this->assertStringContainsString('$this->scopedFindOrFail($id)', $generatePathSource);
}
```

The assertion checks that `$this->scopedFindOrFail($id)` appears ANYWHERE in `DocumentPdfController.php`. The helper is also called by `download` and `preview`, so even if a future regression rewrote `generatePath` to use bare `Document::findOrFail($id)`, the assertion would still pass (because `download` / `preview` would still keep the helper string). Compare with `test_receive_purchase_order_goods_uses_scoped_lookup` (line 235-248), which correctly extracts the method body via `strpos(...) ... substr(...)` and then asserts `scopedQuery()` is in the method body specifically. Recommend tightening `test_pdf_generate_path_uses_scoped_lookup` with the same method-body extraction pattern.

### Finding F — INFORMATIONAL: 2 of 15 Batch 2 tests pass even with the Batch 2 fix reverted (because of upstream Batch 1 protection or in-place company_id manual abort)

Pre-fix re-run with Batch 2 fix files reverted produced 13/15 RED, 2/15 GREEN. The 2 passers are:

- `test_convert_invoice_to_credit_note_rejects_cross_tenant_id` — the bare `Document::findOrFail($id)` at `DocumentConversionController:188` (reverted) still loads the cross-tenant invoice, but the downstream `creditNoteService->createCreditNote(...)` call hits Batch 1's now-scoped `CreditNoteService::createCreditNote` (which throws ModelNotFoundException for cross-tenant `sourceInvoiceId`). So the route returns 404 because Batch 1 protects it. Defense-in-depth chains correctly; the test asserting 404 here is honest about the end-to-end behavior, not the controller-tier guard specifically.

- `test_tax_breakdown_rejects_cross_tenant_id` — the bare `Document::findOrFail($id)` at `DocumentController:248-249` (reverted) loads the cross-tenant document, but lines 251-254 contain a manual `if ($document->company_id !== $this->companyContext->getCompanyId()) { abort(404); }` check that pre-dates this fix and catches cross-company access. The Batch 2 fix replaced the bare-find + manual-check with a `scopedQuery()->findOrFail()`, which is cleaner but functionally equivalent. The test passes either way.

Both are honest test outcomes — they correctly assert end-to-end behavior. Worth noting only because the fix's value-add for these two callsites is architectural cleanliness (centralizing scoping in `scopedQuery()` rather than duplicating `if ... abort(404)` checks), not closing an active leak. The structural-SQL-log assertions for `taxBreakdown` and `convertInvoiceToCreditNote` would still catch a regression that drops the predicate while also dropping the manual abort.

### Finding G — INFORMATIONAL: 3 bare `exists:document_lines,id` validators remain (deferral-accepted)

```
apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:152
apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:61   (line_ids.*)
apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:189  (lines.*.line_id)
```

All three are line-id validators. Per the cluster pattern (matching api.cart's `lines.*.line_id` deferral), these are NICE-TO-HAVE because the line_ids are consumed by services that iterate the now-tenant-scoped parent document's `lines()` relation, so cross-tenant line ids are rejected structurally one tier deeper. Not blocking, matches Cart deferral pattern.

### Finding H — INFORMATIONAL: cluster name collision

There are two `AgedReceivablesService` classes in the codebase:
- `App\Modules\Document\Application\Services\AgedReceivablesService` (the one fixed in Batch 3)
- `App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService` (Accounting module, not in this cluster)

Both are not the same class and are used by different controllers. No bug, but worth noting that the cluster-name-based grep `grep "AgedReceivablesService"` will surface both. The Accounting one needs its own audit under api.accounting cluster (which is already a separate cluster per the inventory).

## Audit exhaustiveness

- **Test honesty per batch**:
  - Batch 1 (CreditNoteTenantIsolationTest, 11 tests): pre-fix RED 11/11, post-fix GREEN 11/11. ✅
  - Batch 2 (DocumentConversionTenantIsolationTest, 15 tests): pre-fix RED 13/15 + 2 GREEN (Finding F), post-fix GREEN 15/15. ✅ honest
  - Batch 3 (RefundResidualTenantIsolationTest, 15 tests): pre-fix RED 15/15, post-fix GREEN 15/15. ✅
  - Combined: 41/41 GREEN with all fixes in place.

- **Hostile-grep `->where('id|...|location_id', ...)` in `app/Modules/Document/`**: 23 matches, triaged:
  - Category (a) safe — leads with tenant+company predicates: 9 (controller-tier `Document::forCompany($companyId)->...->where('id', ...)` / `Document::where('tenant_id')->where('company_id')->...->where('id', ...)` chains in QuoteController, CreditNoteController, InvoiceController, DeliveryNoteController, PurchaseOrderController, SalesOrderController, plus 6 AgedReceivablesService partner_id reads which lead with company_id).
  - Category (b) structurally protected — chained on already-tenant-scoped object: 5 (e.g. CreditNoteService line 174 `$invoice->lines()->where('id', $lineId)`, ReturnNoteService line 202 `->where('product_id', $line->product_id)` on tenant-scoped line, Document model line 368 self-join, Document model line 744 join from order line, ReturnNoteService line 169 location lookup post-fix, EloquentDocumentVehicleContextWriter lines 26+40 — these run inside transactions seeded by upstream-scoped reads).
  - Category (c) blind spots needing fix: 0 in raw `where('id', ...)` chains; **but Finding A above is a categorical sibling** (`Product::whereIn('id', $productIds)`) that was missed by the inventory and only surfaced via the broader service-tier static-read grep.

- **Bare exists validators audit**: 3 hits, all `exists:document_lines,id` — Finding G defers them (matches Cart pattern).

- **Request-derived tenant/company patterns**: 0 matches. Document module does not derive tenant/company from request input/headers/queries — always from `$this->companyContext->requireCompany()` etc. ✅ clean.

- **Cross-module callers**:
  - `Workshop\WorkOrder\Infrastructure\Adapters\DocumentGenerationAdapter` calls `DocumentPostingService::post($document)` where `$document` is a freshly-created tenant-scoped Document — structurally protected.
  - `Compliance\Commands\VerifyFiscalChainsCommand` references `DocumentPostingService` only in a doc comment — no actual call.
  - `Compliance\Services\UninvoicedDeliveryNoteService` is a distinct service that doesn't import the Document services.
  - No external module imports `Document\Domain\Services\RefundService` — RefundController is the sole consumer. ✅

- **Structural-SQL-log honesty**:
  - All 3 batches' SQL-log feature tests assert BOTH `"tenant_id"` AND `"company_id"` literals — would fail if either is dropped from the WHERE.
  - Batch 1 lines 396-397, 424-425, 466-467.
  - Batch 2 lines 321-322, 345-346, 374-375.
  - Batch 3 lines 345-346, 401-402.
  - `findRouteAnchoredDocumentQuery` helper (in each test class) filters DB query log down to the route-anchored read (not noise queries) before asserting. ✅

- **Source-level invariant tests for non-routed methods**:
  - Finding D: regex matchers only require `tenant_id` (not `company_id`) — asymmetric weakness.
  - Finding E: `test_pdf_generate_path_uses_scoped_lookup` is file-wide, not method-body anchored.
  - `test_receive_purchase_order_goods_uses_scoped_lookup` IS method-body anchored — correct pattern.

- **Concurrent-attribution caveat (Q9)**: `git show 5174e756 --stat` shows 5 production Document files + 1 Document test, zero Inventory files. Subject line "fix(inventory):" is a copy-paste race artifact from a concurrent agent's commit. Verified — Batch 2's actual content is the Document Batch 2 fix, and the actual Inventory cluster fix is at 1eada1cb (different file list).

- **`verify-history`**: `php artisan sweep:inventory:verify-history --inventory-path=docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` → "verified 963 event(s) across 268 callsite(s); 0 problem(s)." ✅

- **POS surface diff**: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` → empty. ✅

- **PHPStan / Pint**: `vendor/bin/phpstan analyse <14 changed files> + 3 test files` → "[OK] No errors". `vendor/bin/pint --test <same set>` → `{"result":"pass"}`. ✅
  - Note: a broader `phpstan analyse app/Modules/Document tests/Feature/Document` returns 184 errors on UNRELATED pre-existing files (CreditNoteAllocationTest, ProductFactory issues, InvoiceDocumentTest collection-vs-model property access). None are in changed files. The cluster's PHPStan gate is honored only on the touched files, consistent with the rest of the sweep.

- **Broader Document feature+unit suite**: `vendor/bin/phpunit tests/Feature/Document tests/Unit/Document` → 437 tests, 1425 assertions, 13 skipped, 0 failures. ✅ no regressions.

## Confidence

High confidence the 42 inventoried callsites are closed correctly per their inventory entries, the regression tests are honest (proven by the stash/un-stash cycle showing 39/41 RED pre-fix), and PHPStan/Pint are clean. The structural-SQL-log assertions in feature tests are correctly grained to fail loudly on predicate drop. Cross-module Document service consumers do not bypass the now-scoped reads.

Blocking on Finding A (DraftPersistenceService::addLinesBatch) is necessary because it is a real exploitable path:
1. Routed at `POST /api/v1/documents/auto-save` (registered with explicit "no permissions required" comment),
2. With no FormRequest / `$request->validate(...)` call (controller does `$request->all()` straight into the service),
3. Service-layer batch-fetch is unscoped (`Product::whereIn('id', $productIds)`),
4. The fetched product's `name` is persisted into the draft line snapshot,
5. Tenant A submitting `lines: [{product_id: <tenant-B uuid>, ...}, {product_id: <tenant-B uuid>, ...}]` would leak tenant B product names into tenant A's draft.

The single-line `addLine` path was correctly fixed under api.document.012/013 — the batch path is the categorically identical sibling that the inventory missed.

## What I could have missed

- I did not run a pre-fix repro of Finding A against the current branch tip — the path is open today on HEAD, not just on the parent commits. The Workshop adapter-style argument (the document was just created with the work order's tenant) does not apply because the auto-save route allows arbitrary user input on `lines`.
- Findings B (CreditNoteService::createStandaloneCreditNote line 374) and Findings D/E (test rigor) are not exploitable in production today because of tier-2 protections (validator + Batch 1 service-layer scoping, and methodology choices) — they break symmetry with the cluster's stated invariant but do not constitute active leaks.
- I did not run the broader Treasury / Compliance / Cart / Inventory cluster regression sweeps; those are owned by their respective cluster gates per `tenant-isolation-sweep-inventory.yml`. The Document-only suite + 3 test files + `verify-history` are the cluster gate per inventory discipline, and all passed.
- Finding F is informational, not a defect — Batch 2's fix is architecturally correct (centralizing scoping in `scopedQuery()` instead of leaving the manual `abort(404)` check) even though for those 2 callsites the end-to-end behavior was already correct.
- I did not exhaustively trace every JSONB `payload` field reader across the module. I checked the major ones (`SalesOrderToInvoiceConverter::markDeliveryNotesAsInvoiced`, `DocumentPostingService::checkDeliveryRequirement`, `RefundService::getCreditNoteSummary`, `DeliveryNoteToInvoiceConverter`) — all read JSONB IDs from already-tenant-scoped parent documents, so structurally protected. If a future feature reads JSONB-encoded UUIDs from a less-trusted source, it would be a separate cluster's concern.
