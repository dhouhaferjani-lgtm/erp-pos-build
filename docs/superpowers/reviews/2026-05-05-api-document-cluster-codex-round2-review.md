REQUEST-CHANGES

Round-2 second-layer adversarial review for the api.document cluster.

Review date: 2026-05-05
Branch tip reviewed locally: `b7d899ce` (`style(document): pint auto-fix SharedDocumentCrossCompanyTest + Opus round-2 verdict`)

`git pull --ff-only` could not be completed in this sandbox:

```text
error: cannot open '.git/FETCH_HEAD': Operation not permitted
```

I reviewed the checked-out workspace at `b7d899ce`.

## Opus round-2 status verification

Opus's closure claims for the original round-1 findings are materially honest:

- `DraftPersistenceService::addLinesBatch` now scopes Product and Service batch lookups by `tenant_id` and `company_id` before `whereIn`.
- `CreateDocumentRequest` and `UpdateDocumentRequest` now use active `CompanyContext` plus `ScopedExists::tenantAndCompany` for partner/document/product/service IDs, and `ScopedExists::company` for locations.
- The six shared CRUD controllers' Product/Service batch reads are scoped by tenant and company in store/update paths where those reads exist.
- `RefundResidualTenantIsolationTest::test_draft_persistence_service_uses_scoped_lookups` is now method-body anchored for `saveDraft`, `addLine`, and `addLinesBatch`.
- `DocumentConversionTenantIsolationTest::test_pdf_generate_path_uses_scoped_lookup` is method-body anchored to `generatePath`.
- The previous Pint cosmetic is fixed at `b7d899ce`.

One Opus note needs correction but is not material: `vehicle_context.vehicle_id` is still validator-tier `uuid` only, but `VehicleContextBuilder::buildFromVehicleId` throws `ValidationException::withMessages(...)` after tenant+company scoping, so the likely UX is 422, not the 500 implied by Opus. Its NICE-TO-HAVE classification remains fair.

## Test honesty verification

Green checks:

- `vendor/bin/phpunit tests/Feature/Document/CreditNoteTenantIsolationTest.php tests/Feature/Document/DocumentConversionTenantIsolationTest.php tests/Feature/Document/RefundResidualTenantIsolationTest.php tests/Feature/Document/SharedDocumentCrossCompanyTest.php`
  - `OK (49 tests, 122 assertions)`
- `vendor/bin/phpunit tests/Feature/Document tests/Unit/Document`
  - `OK, but there were issues! Tests: 445, Assertions: 1458, PHPUnit Deprecations: 39, Skipped: 13.`
- `vendor/bin/pint --test` on the round-2 touched files
  - `{"result":"pass"}`
- `vendor/bin/phpstan analyse ... --debug --no-progress`
  - `[OK] No errors`
  - Note: without `--debug`, PHPStan failed before analysis because this sandbox blocks its parallel worker socket: `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted`.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`
  - `verified 963 event(s) across 268 callsite(s); 0 problem(s).`
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`
  - empty

Reverse/mutation honesty checks:

- Reverse-applied `da1790ef` production diff for `CreateDocumentRequest.php`, then ran `SharedDocumentCrossCompanyTest.php`:
  - `Tests: 6, Assertions: 12, Failures: 5`
  - Same shape Opus reported. The controller batch SQL test still passed because it targets the QuoteController change, not the FormRequest change.
- Reverse-applied `da1790ef` production diff for `QuoteController.php`, then ran `test_create_quote_controller_batch_query_includes_company_id_predicate`:
  - `Tests: 1, Assertions: 2, Failures: 1`
  - Failure SQL was the reverted bare product batch read: `select * from "products" where "id" in (?) ...`
- Reverse-applied `1f0aaf10` production diff for `DraftPersistenceService.php`, then ran the DraftPersistence source/leak/SQL tests:
  - `Tests: 3, Assertions: 10, Failures: 3`
  - Failures covered missing `.043` source marker, leaked tenant-B product name, and missing tenant/company predicates in the batch products query.
- Mutated only `DraftPersistenceService::addLine` Product lookup to drop `->where('company_id', $companyId)`, then ran `test_draft_persistence_service_uses_scoped_lookups`:
  - `Tests: 1, Assertions: 12, Failures: 1`
  - Failure message correctly anchored to `addLine Product::query() ... (api.document.012)`.

## New Finding 1 - REQUEST-CHANGES: Draft auto-save still persists foreign product_id/service_id after scoped lookup miss

The `addLinesBatch` remediation scopes the lookup but still writes the untrusted request UUID into the tenant-A `document_lines` row when the scoped lookup returns no Product/Service.

Relevant code:

- `apps/api/app/Modules/Document/Presentation/routes.php:33-35` registers `/api/v1/documents/auto-save` with no permission middleware beyond auth.
- `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:68-77` passes `$request->all()` into `DraftPersistenceService::saveDraft`.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:427-441` correctly scopes Product/Service lookup.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:450-451` gets `$product` / `$service` from the scoped collections.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:473-474` still inserts raw `$lineData['product_id']` and `$lineData['service_id']`.
- The single-line update path has the same shape: scoped lookup at `DraftPersistenceService.php:207-219`, raw persistence at `DraftPersistenceService.php:239-241`.

Temporary repro test added and removed during review:

```php
public function test_auto_save_batch_lines_does_not_persist_cross_tenant_product_id(): void
{
    $productB = Product::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'company_id' => $this->companyB->id,
        'name' => 'TenantBForeignProductId',
    ]);

    $this->actingAsForTenant()
        ->postJson('/api/v1/documents/auto-save', [
            'type' => DocumentType::Quote->value,
            'partner_id' => $this->customerA->id,
            'lines' => [
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 50],
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 60],
            ],
        ])
        ->assertStatus(200);

    $linesWithForeignProductId = DocumentLine::query()
        ->where('product_id', $productB->id)
        ->count();

    $this->assertSame(0, $linesWithForeignProductId);
}
```

Current result on `b7d899ce`:

```text
Tests: 1, Assertions: 2, Failures: 1.
Failed asserting that 2 is identical to 0.
```

This is not just cosmetic data pollution. The module has unscoped relations:

- `DocumentLine::product()` is a plain `belongsTo(Product::class)` at `apps/api/app/Modules/Document/Domain/DocumentLine.php:134-136`.
- `DocumentLine::service()` is a plain `belongsTo(Service::class)` at `apps/api/app/Modules/Document/Domain/DocumentLine.php:150-152`.

That means a tenant-A document line containing a tenant-B `product_id` can later dereference the tenant-B product anywhere code eager-loads or accesses `lines.product`. Concrete downstream surfaces include:

- `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:541-544` eager-loads `lines.product` before posting.
- `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:782-790` reads `$line->product->is_physical`.
- `apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php:50` loads `Document::with(['lines.product'])->find($event->invoiceId)`.
- `apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php:125-150` reads the dereferenced Product's `isPhysical()`, `cost_price`, `id`, and can pass that foreign product into a company-A COGS journal entry.
- Conversions propagate the poisoned UUID: `CopiesDocumentData.php:113-120` copies `product_id` and `service_id` verbatim from source to target document lines.

The round-2 fix closed the foreign name snapshot leak, but it did not close the foreign identifier contamination. The safe behavior for the no-validation draft path is to persist `product_id` only when `$product !== null`, persist `service_id` only when `$service !== null`, and otherwise write null (or reject the draft line, but this endpoint is intentionally lenient). The same rule must be applied to both `addLine` and `addLinesBatch`, and regression tests should assert that cross-tenant and same-tenant cross-company product/service IDs are not persisted.

Required remediation:

- In `addLine`, set persisted `product_id` from `$product?->id` and persisted `service_id` from `$service?->id`.
- In `addLinesBatch`, set inserted `product_id` from `$product?->id` and inserted `service_id` from `$service?->id`.
- Ensure `DraftLineAdded` / `DraftLineAddedV2` event payloads also use the sanitized persisted IDs, not request IDs.
- Add tests for batch product, batch service, and update/addLine path. At minimum, the repro above must pass.

## Other sweep results

Opus's remaining NICE/INFO findings are honestly classified:

- `SharedDocumentCrossCompanyTest` only directly exercises Quote.store and not all sibling controllers/update paths. Code is mechanically scoped across the sibling controllers, but the test coverage gap remains a NICE-TO-HAVE.
- The JSONB-derived `Document::whereIn` reads in InvoiceController, DocumentPostingService, RefundService, and converters are still unscoped. I found no user-anchored path beyond already-scoped parent documents, so I agree this is defense-in-depth, not blocking.
- `CreditNoteService::createStandaloneCreditNote` still has bare `Product::whereIn`, but the HTTP validator is tenant+company scoped. NICE-TO-HAVE remains fair.
- Bare `exists:` in `apps/api/app/Modules/Document/Presentation/` is limited to the three accepted `exists:document_lines,id` validators.

Adjacent module sweep:

- Compliance: `AnomalyDetectionService::detectHighAbandonmentRate` uses `Document::find($documentId)` from company-scoped `AuditEvent` payloads. It checks status only; I did not find an active data leak, but it remains defense-in-depth debt.
- Inventory: `PostCOGSOnInvoice` is the meaningful downstream consumer because it dereferences `lines.product` from an event-loaded invoice. That becomes relevant only because the draft path can still persist foreign product IDs.
- Workshop: `DocumentGenerationAdapter` uses `Document::find($wo->quote_document_id)`, but that ID is written from a generated work-order quote in the same adapter flow.
- Accounting: `InvoicePostedListener` uses `Document::find($event->invoiceId)`, but the event is dispatched from an already-scoped `Document` instance by `DocumentPostingService`.

## Verdict

REQUEST-CHANGES.

Do not flip the 42 + `.043` / `.044` callsites to `fixed` yet. The core lookup predicates are now present and the round-2 tests are honest, but the no-validation draft path still persists attacker-supplied foreign product/service UUIDs into tenant-local document lines. That keeps a real cross-tenant/cross-company dereference path alive through `DocumentLine` relations and downstream invoice/inventory processing.
