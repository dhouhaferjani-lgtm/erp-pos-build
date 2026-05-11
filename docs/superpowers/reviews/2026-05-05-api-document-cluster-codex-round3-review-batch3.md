Verdict: APPROVE

Commit reviewed: 8bd2b13a

Round-3 second-layer review for the api.document cluster after remediation commits:

- `8aabee0d` — `DraftPersistenceService::addLine` / `addLinesBatch` persist scoped lookup results instead of raw request product/service UUIDs.
- `4c803be8` — manual stub entry for `api.document.045`.

Reviewed checked-out tip: `4c803be8`.

## Finding 1 closure

Closed.

I reran the round-2 repro via the new regression:

```text
vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php --filter test_auto_save_batch_lines_does_not_persist_cross_tenant_product_id
OK (1 test, 2 assertions)
```

The test posts tenant-B `product_id` values to tenant A's `/api/v1/documents/auto-save` batch path, then asserts:

```php
DocumentLine::query()->where('product_id', $productB->id)->count() === 0
```

That assertion now passes. `DraftPersistenceService::addLinesBatch` writes `$product?->id` / `$service?->id`, so a scoped miss writes null instead of the attacker-supplied UUID. The event payloads are also sanitized because `lineInsertData.product_id` is copied from `$insertData['product_id']`, not from `$lineData`.

The SQL-log invariant also passes:

```text
vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php --filter test_auto_save_batch_lines_query_includes_tenant_and_company_predicates
OK (1 test, 4 assertions)
```

The captured query shape is the expected batch product read:

```sql
select * from "products"
where "tenant_id" = ?
  and "company_id" = ?
  and "id" in (...)
  and "products"."deleted_at" is null
```

The important part is that the `products` `whereIn` query contains both `"tenant_id"` and `"company_id"` predicates before the insert path decides whether to persist a product FK.

## addLine / modifyLine path

`addLine` is also closed. It scopes `Product::query()` and `Service::query()` by document tenant plus company, persists `$product?->id` / `$service?->id`, and emits events using `$line->product_id`.

`modifyLine` is not the same defect. It does not accept or assign `product_id` or `service_id`; it only updates quantity, unit price, description, notes, and derived line total, then emits events using the existing persisted line FK. I did not find a raw-FK update path there.

## Six CRUD controllers

The six controllers all have scoped batch reads:

- Quote: store/update product + service reads include tenant and company.
- SalesOrder: store/update product + service reads include tenant and company.
- Invoice: store/update product + service reads include tenant and company.
- PurchaseOrder: store/update product + service reads include tenant and company.
- DeliveryNote: store product + service reads include tenant and company.
- ReturnNote: store/update product reads include tenant and company.

They still persist raw validated request IDs into `DocumentLine::create()` (`$lineData['product_id']` / `$lineData['service_id']`). I am not treating that as exploitable in this cluster because `CreateDocumentRequest` and `UpdateDocumentRequest` both use `ScopedExists::tenantAndCompany` for `lines.*.product_id` and `lines.*.service_id`, so out-of-scope UUIDs are rejected before controller execution. As defense-in-depth, those controllers could later mirror the draft hardening and persist `$lineProduct?->id` / `$lineService?->id`, but this is consistency debt rather than a blocker.

## Verification

Green:

```text
vendor/bin/phpunit tests/Feature/Document tests/Unit/Document tests/Feature/Modules/Document
OK, but there were issues!
Tests: 483, Assertions: 1576, PHPUnit Deprecations: 39, Skipped: 13.
```

```text
vendor/bin/pint --test app/Modules/Document tests/Feature/Document
{"result":"pass"}
```

```text
php artisan sweep:inventory:verify-history
verified 963 event(s) across 268 callsite(s); 0 problem(s).
```

```text
git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
# empty
```

PHPStan note: the broad requested command exits non-zero due to 184 pre-existing errors in unrelated `tests/Feature/Document/*` files such as `BalanceDueCacheTriggerTest.php`, `CompleteSalesCycleWithReturnTest.php`, `PartialDeliveryTest.php`, and `ReturnNoteIntegrationTest.php`:

```text
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Document tests/Feature/Document --debug
[ERROR] Found 184 errors
```

The remediation files are clean:

```text
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Document/Domain/Services/DraftPersistenceService.php tests/Feature/Document/RefundResidualTenantIsolationTest.php --debug
[OK] No errors
```

The branch-changed Document production/test files under `dev..HEAD` are also clean under PHPStan:

```text
vendor/bin/phpstan analyse --no-progress --memory-limit=2G <dev..HEAD Document production/test files> --debug
[OK] No errors
```

## Verdict

APPROVE.

Codex round-2 Finding 1 is closed for both batch and single-line draft insertion. The remaining raw-ID persistence in the six CRUD controllers is validator-protected and not a reason to keep the 42 + `.043` / `.044` / `.045` callsites open.
