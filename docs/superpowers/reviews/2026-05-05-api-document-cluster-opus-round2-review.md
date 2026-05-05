# Opus adversarial round-2 re-review — api.document cluster

Review date: 2026-05-05
Branch tip reviewed: `da1790ef` (round-1 remediation tip)
Reviewer: opus (round-2 adversarial re-review)

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED

Commits reviewed:
- `1f0aaf10` — Opus round-1 Finding A (addLinesBatch) + tightened Findings D, E source-level matchers
- `da1790ef` — Codex round-1 Findings 1+2 (shared CRUD CreateDocumentRequest + UpdateDocumentRequest + 6 controllers' batch reads, per-method anchored DraftPersistence test)

## Summary

Both round-1 remediations are correctly landed and test-honest. The only round-2 surface remaining is one Pint cosmetic in the new test file (`SharedDocumentCrossCompanyTest`) that auto-imports a fully-qualified type, plus a couple of NICE-TO-HAVE/INFORMATIONAL items that match the round-1 cluster pattern (defense-in-depth gaps consistent with already-accepted Finding C and Finding G). No new exploitable defects were found.

The 49-test isolation suite (11 + 15 + 17 + 6) is GREEN on HEAD. Pre-fix reverse-applied production diffs produced the expected RED outcomes: 5/6 RED for Codex Finding 1's CreateDocumentRequest revert, 1/1 RED for the QuoteController batch read revert, 1/1 RED for the per-method anchored DraftPersistence test when only `addLine` drops `company_id`. The structural-SQL-log invariants in feature tests require BOTH `tenant_id` AND `company_id` literals. PHPStan is clean. POS surface diff `dev..HEAD` is empty. `verify-history` reports 963 events / 268 callsites / 0 problems.

## Round-1 finding closure verification

### Opus Finding A — `DraftPersistenceService::addLinesBatch` leak — CLOSED

`DraftPersistenceService.php:427-438` now reads:

```
$products = Product::query()
    ->where('tenant_id', $document->tenant_id)
    ->where('company_id', $companyId)
    ->whereIn('id', $productIds)->get()->keyBy('id');

$services = Service::query()
    ->where('tenant_id', $document->tenant_id)
    ->where('company_id', $companyId)
    ->whereIn('id', $serviceIds)->get()->keyBy('id');
```

Two regression tests added at `RefundResidualTenantIsolationTest.php`:
- `test_auto_save_batch_lines_does_not_leak_cross_tenant_product_name` — POSTs 2 lines with tenant-B product UUIDs to `/auto-save`, asserts no DocumentLine carries `TenantBSecretProductName` in description or designation_default_snapshot.
- `test_auto_save_batch_lines_query_includes_tenant_and_company_predicates` — SQL-log invariant on the batch products `whereIn` requiring BOTH `"tenant_id"` AND `"company_id"` literals.

Manual callsite ledger entries `api.document.043` (Product) and `api.document.044` (Service) added at `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml:118-142`.

### Opus Findings D + E — source-level matchers tightened — CLOSED

`RefundResidualTenantIsolationTest.php:418-470` and `DocumentConversionTenantIsolationTest.php` (PDF method-body anchor):
- All 4 source-level regex matchers (DocumentPostingService Product, DraftPersistence saveDraft Document, DraftPersistence addLine Product+Service, DraftPersistence addLinesBatch Product+Service) now require BOTH `tenant_id` AND `company_id` literals.
- `test_pdf_generate_path_uses_scoped_lookup` extracts only the `generatePath` method body via strpos+substr (was file-wide).
- `test_draft_persistence_service_uses_scoped_lookups` extracts each of the three method bodies (`saveDraft`, `addLine`, `addLinesBatch`) separately and matches the per-method scoping regex (Codex Finding 2 closure).

### Codex Finding 1 — shared CRUD same-tenant cross-company leak — CLOSED

`CreateDocumentRequest.php` (lines 18-22 inject `CompanyContext`, lines 45-47 derive `companyId` and `scopedTenantId`):
- partner_id, source_document_id, lines.*.product_id, lines.*.service_id all use `ScopedExists::tenantAndCompany`.
- location_id and lines.*.location_id use `ScopedExists::company` (locations table is company-only — verified `locations` has no `tenant_id` column per ScopedExists::company contract).

`UpdateDocumentRequest.php` (lines 18-22 + 42-43): partner_id, lines.*.product_id, lines.*.service_id all use `ScopedExists::tenantAndCompany`. Source_document_id, location_id, currency are intentionally absent in the update path (immutable post-creation by design); not a defect.

All 6 CRUD controllers' batch reads:
- `QuoteController.php:221, 223, 329, 331` — store + update Product + Service whereIn scoped by tenant_id + company_id (store uses `$tenantId` / `$companyId`; update uses `$documentModel->tenant_id` / `$documentModel->company_id`).
- `SalesOrderController.php:221, 223, 329, 331` — same shape.
- `InvoiceController.php:236, 238, 346, 348` — same shape.
- `PurchaseOrderController.php:230, 232, 340, 342` — same shape.
- `DeliveryNoteController.php:226, 228` — store path scoped (no update batch read in this controller per its store-only batch flow).
- `ReturnNoteController.php:225, 354` — store + update Product whereIn scoped (no Service batch in return-note flow).

20 controller batch reads scoped, 6 in inventoried CreateDocumentRequest validators + 4 in UpdateDocumentRequest. All share-tenant cross-company validator and snapshot leaks closed.

### Codex Finding 2 — per-method-anchored DraftPersistence test — CLOSED

Confirmed via mutation: editing `addLine` to drop only `->where('company_id', $companyId)` (keeping tenant_id) makes the test go RED with the expected message "addLine Product::query() must scope by both tenant_id and company_id (api.document.012)". Restoring the line returns it to GREEN.

## New round-2 findings

### Finding 1 — INFORMATIONAL: Pint cosmetic on `SharedDocumentCrossCompanyTest`

`vendor/bin/pint --test tests/Feature/Document/SharedDocumentCrossCompanyTest.php` reports:

```
{"result":"fail","files":[{"path":"...SharedDocumentCrossCompanyTest.php","fixers":["fully_qualified_strict_types","ordered_imports"]}]}
```

The fix Pint would apply is to import `Symfony\Component\HttpFoundation\Response` and shorten the docblock annotation `TestResponse<\Symfony\Component\HttpFoundation\Response>` to `TestResponse<Response>` (line 340). Cosmetic only; not a correctness defect, but the cluster gate "PHPStan + Pint clean" wording in da1790ef's commit message is contradicted by the new test file. Suggested edit (one-line auto-fix via `vendor/bin/pint tests/Feature/Document/SharedDocumentCrossCompanyTest.php`).

This is a NICE-TO-HAVE pre-merge cleanup, not blocking.

### Finding 2 — NICE-TO-HAVE (test rigor): SharedDocumentCrossCompanyTest only covers Quote (1 of 6 CRUD controllers) and only the Create path (no Update path)

The new 6-test file proves the fix on:
- Quote.store happy path (validator + controller batch read).

It does NOT prove the same fix on:
- SalesOrder, Invoice, PurchaseOrder, DeliveryNote, ReturnNote (5 sibling controllers). All 5 use the same `CreateDocumentRequest` (verified via grep) and the same `Product::query()->where('tenant_id')->where('company_id')->whereIn('id', ...)` shape, so the fix is mechanically uniform. The validator is shared; only the controller batch reads need symmetry. A code-review verification (executed in this audit) confirms the 20 controller batch reads are all uniformly scoped.
- Update path (any controller). `UpdateDocumentRequest` validator is exercised on routes like PUT /quotes/{id}, but no SQL-log or boundary-rejection test covers it.

Defense-in-depth would prefer to enumerate at least one structural-SQL-log assertion per controller and per direction (store + update). Today's risk is minimal because:
- Validator scoping is identical via the shared FormRequest (`CreateDocumentRequest::rules()` returns one array for all 4 customer-facing controllers; `UpdateDocumentRequest::rules()` likewise).
- Controller batch read shape is uniform per `git grep` audit (verified 20 calls all carry `->where('tenant_id', ...)->where('company_id', ...)`).

NICE-TO-HAVE, not blocking. Suggested follow-up: parameterize the new SharedDocumentCrossCompanyTest with a data provider that loops through all 6 store routes and both directions. Estimated 30 minutes; matches the symmetry the cluster invariant otherwise enforces.

### Finding 3 — INFORMATIONAL: validator-tier `vehicle_context.vehicle_id` is `uuid`-only, not `ScopedExists::tenantAndCompany('vehicles', ...)`

`CreateDocumentRequest.php:60` and `UpdateDocumentRequest.php:57`:

```
'vehicle_context.vehicle_id' => ['required_with:vehicle_context', 'uuid'],
```

A user submitting a foreign-tenant vehicle_id is caught at the service tier (`VehicleContextBuilder::buildFromVehicleId` at `apps/api/app/Modules/Vehicle/Application/Services/VehicleContextBuilder.php:37-38` filters by tenant_id + company_id, throws ModelNotFoundException). So the cross-tenant payload triggers a 500 (or DomainException) instead of a clean 422.

Two-tier defense template would scope the validator. Today this works because the service-tier scoping catches it, but the user-facing UX is degraded (500 vs 422) and the cluster invariant of "tenant + company at the validator tier" is broken.

NICE-TO-HAVE, not blocking. Outside the inventoried 42 callsites + .043/.044, but worth noting for a follow-up cluster pass on the `Vehicle` module (which is its own cluster per the inventory).

### Finding 4 — INFORMATIONAL: `Document::whereIn('id', $deliveryNoteIds)` chains across InvoiceController + DocumentPostingService + RefundService + 2 converters are not tenant+company scoped

7 callsites surface in the audit:
- `InvoiceController.php:643, 825` — `confirmDeliveriesAndPost` and `checkDeliveryNotesDelivered` read `$sourceOrder->payload['delivery_note_ids']` from a baseQuery-loaded invoice's source order.
- `DocumentPostingService.php:326` — same shape inside `checkDeliveryRequirement`.
- `RefundService.php:324` — `getCreditNoteSummary($invoice)` reads `$invoice->payload['credit_note_ids']`.
- `DeliveryNoteToInvoiceConverter.php:251` — reads `$options['delivery_note_ids']` (validator-protected at `DocumentConversionController.php:248` via `ScopedExists::tenantAndCompany`).
- `SalesOrderToInvoiceConverter.php:441` — `markDeliveryNotesAsInvoiced` reads `$order->payload['delivery_note_ids']` (the source order is already tenant+company scoped via the converter caller).

For all 7, the JSONB IDs were written by tenant+company-scoped code at the upstream conversion point (e.g., `SalesOrderToDeliveryNoteConverter.php:169` appendToSourcePayload runs on a tenant-scoped order/delivery pair). Defense-in-depth would still scope the read by `tenant_id + company_id` of the parent document. Opus round-1 explicitly listed this as "What I could have missed" — none of these reads are user-anchored, all read JSONB encoded by the same tenant. Not exploitable in production today.

NICE-TO-HAVE, not blocking. Consistent with the pre-existing Document-module pattern (Opus round-1 Finding C: company-only scoping in AgedReceivablesService is already accepted as informational).

### Finding 5 — INFORMATIONAL: `CreditNoteService::createStandaloneCreditNote::Product::whereIn` (line 374) still bare

This is identical to Opus round-1 Finding B — the standalone credit note path has unscoped `Product::whereIn('id', $standaloneProdIds)`. Validator-tier protected via `CreditNoteController.php:161` `ScopedExists::tenantAndCompany('products', $tenantId, $companyId)`. Not blocking; survived round-1 re-classification as NICE-TO-HAVE.

## Audit exhaustiveness

Commands run, output captured:

- `git pull --ff-only` -> Already up to date.
- `vendor/bin/phpunit tests/Feature/Document/CreditNoteTenantIsolationTest.php tests/Feature/Document/DocumentConversionTenantIsolationTest.php tests/Feature/Document/RefundResidualTenantIsolationTest.php tests/Feature/Document/SharedDocumentCrossCompanyTest.php` -> `OK (49 tests, 122 assertions)`.
- `vendor/bin/phpunit tests/Feature/Document tests/Unit/Document` -> `OK, but there were issues! Tests: 445, Assertions: 1458, PHPUnit Deprecations: 39, Skipped: 13.` (No failures; deprecations are pre-existing.)
- `vendor/bin/phpstan analyse <14 files>` (12 production, 2 tests) -> `[OK] No errors`.
- `vendor/bin/pint --test <14 files>` -> `{"result":"fail","files":[{"path":"...SharedDocumentCrossCompanyTest.php","fixers":["fully_qualified_strict_types","ordered_imports"]}]}` (Finding 1; cosmetic).
- `php artisan sweep:inventory:verify-history --inventory-path=docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> `verified 963 event(s) across 268 callsite(s); 0 problem(s)`.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` -> empty.

Per-finding reverse-applied test honesty:

- Reverse-applied `git diff da1790ef^ da1790ef -- apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php` -> `SharedDocumentCrossCompanyTest`: `Tests: 6, Assertions: 12, Failures: 5`. The 5 failures hit:
  - test_create_quote_rejects_cross_company_partner_id (validator boundary)
  - test_create_quote_rejects_cross_company_line_product_id
  - test_create_quote_rejects_cross_company_line_service_id
  - test_create_quote_partner_validator_query_includes_company_id_predicate
  - test_create_quote_product_validator_query_includes_company_id_predicate
  The 6th test (controller batch query) still passed because that test seeds a same-company product to bypass the validator boundary and only asserts the controller-tier query — its honest dependency is on the `QuoteController` change, not the FormRequest. Honest as designed.
- Reverse-applied `git diff da1790ef^ da1790ef -- apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php` -> `test_create_quote_controller_batch_query_includes_company_id_predicate`: `Tests: 1, Assertions: 2, Failures: 1`. Honest — the SQL-log invariant correctly fails when the controller batch read is reverted to bare `whereIn`.
- Mutated `DraftPersistenceService::addLine` Product lookup to drop only `->where('company_id', ...)` (keeping `tenant_id`) -> `test_draft_persistence_service_uses_scoped_lookups`: `Tests: 1, Assertions: 12, Failures: 1`. Honest — the per-method anchored regex correctly catches the `addLine`-only regression that the round-1 file-wide regex would have missed (Codex Finding 2 properly closed).

Hostile sweeps:

- `grep -rn "Rule::exists\\|exists:" apps/api/app/Modules/Document/` -> 3 hits, all `exists:document_lines,id` (Opus round-1 Finding G — deferred, matches Cart deferral pattern).
- `grep -rn "ScopedExists::tenantAndCompany\\|ScopedExists::company" apps/api/app/Modules/Document/Presentation/` -> 14 hits across 7 files (the round-1 originals + 4 in CreateDocumentRequest + 3 in UpdateDocumentRequest from da1790ef). All correctly typed.
- `grep -rn "Document::whereIn\\|DocumentLine::whereIn\\|Partner::whereIn\\|Product::whereIn\\|Service::whereIn" apps/api/app/Modules/Document/` -> 7 unscoped Document::whereIn (Finding 4 above) + 1 unscoped Product::whereIn at CreditNoteService:374 (Finding 5 / Opus round-1 Finding B).
- `grep -rn "Product::find\\|Service::find\\|Partner::find\\|Partner::where\\|Partner::query"` -> only the 2 Partner::query reads at `AgedReceivablesService.php:181` (company-only, Opus round-1 Finding C — informational) and `CreditNoteService.php:310` (already tenant+company scoped). No new defects.
- `grep -rn "DocumentVehicleContext::"` in module -> 7 creates, 1 query — all post-document-validation or scoped via writer's idempotency check.
- Cross-module callers: `grep -rln "CreateDocumentRequest|UpdateDocumentRequest|DraftPersistenceService" apps/api/app/Modules/ | grep -v "/Document/"` -> empty. No cross-module bypass paths.

Per-controller route binding:

- All 6 CRUD controllers (Quote, SalesOrder, Invoice, PurchaseOrder, DeliveryNote, ReturnNote) bind `CreateDocumentRequest`/`UpdateDocumentRequest` via type-hinted `store()` / `update()` parameters (verified via `grep -n "public function store\\|public function update" + adjacent FormRequest type`).

Inventory ledger / .043+.044 entries:

- `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml:118-142` — both `api.document.043` (Product) and `api.document.044` (Service) entries present with severity=high, fiscal_path=false, expected_scope=tenant_and_company.

## Confidence

High confidence the cluster is closed for production-impacting concerns.

- Both round-1 BLOCKING findings (Opus A, Codex 1) are mechanically corrected and test-pinned with structural-SQL-log invariants that fail loudly on predicate drop.
- Both round-1 minor test-rigor findings (Opus D, E; Codex 2) are tightened: source-level regexes now require both `tenant_id` AND `company_id`, and per-method anchored matching catches single-method regressions.
- All 49 isolation tests are green. Pre-fix reverse-applied production diffs produce the expected RED outcomes (5/6, 1/1, 1/1).
- The broader Document feature+unit suite (445 tests) is green with no regressions.
- POS surface diff `dev..HEAD` is empty.
- `verify-history` shows 963 events / 268 callsites / 0 problems.

The 5 round-2 findings I identified are all NICE-TO-HAVE/INFORMATIONAL:
- Finding 1: Pint cosmetic — should be auto-fixed before merge.
- Finding 2: SharedDocumentCrossCompanyTest covers Quote.store only (mechanically symmetric with 5 siblings + Update path).
- Finding 3: vehicle_context.vehicle_id validator is uuid-only (service-tier scoped).
- Finding 4: 7 JSONB-derived `Document::whereIn` reads consistent with Opus round-1 Finding C asymmetry.
- Finding 5: CreditNoteService standalone path bare `Product::whereIn` (validator-protected, identical to round-1 Finding B).

None constitute active leaks. All match the cluster's stated invariant tolerance for service-tier reads scoped at the validator tier or via JSONB-write-time scoping.

## Verdict rationale: APPROVE-WITH-MINOR-EDITS-APPLIED

I am approving with one edit applied: run `vendor/bin/pint tests/Feature/Document/SharedDocumentCrossCompanyTest.php` to fix the cosmetic ordered_imports + fully_qualified_strict_types violations. Once that one auto-fix lands, the cluster is ready for `sweep:inventory:review` to flip all 42 (+ .043 / .044) callsites to `fixed`.

I would NOT block on Findings 2-5: they are pattern-consistent NICE-TO-HAVE defense-in-depth gaps already accepted in the round-1 verdict (Findings B, C, F, G, H).

## What I could have missed

- I did not run the full repository-wide test suite (only the cluster's 4 test files + 445 broader Document suite + verify-history). The cluster gate is honored on touched files, consistent with the rest of the sweep.
- I did not exhaustively trace every JSONB payload field reader in cross-module callers (e.g., `Workshop\WorkOrder\Infrastructure\Listeners\WriteDocumentVehicleContextForWorkOrderInvoice` at line 56 calls `findWorkOrderIdForDocument($event->invoiceId)` which uses an unscoped `Document::query()->where('id', $documentId)->value('work_order_id')`). The event's `invoiceId` is dispatched from a tenant-scoped invoice posting, so it's structurally protected. If a future feature emits the same event from less-trusted code, that path would need re-audit.
- I did not run the Treasury / Compliance / Cart / Inventory cluster regression sweeps. Per the cluster gate discipline, the Document-only suite is the sweep gate.
- The Pint cosmetic finding (Finding 1) is the only one that, if left in place, would cause `./scripts/preflight.sh` to fail. The cluster is otherwise clean. Addressing it is a 5-second auto-fix.
