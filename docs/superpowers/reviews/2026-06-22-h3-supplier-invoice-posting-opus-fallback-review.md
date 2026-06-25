# H-3.2 Supplier Invoice Posting — Opus Fallback Review

Date: 2026-06-22  
True Opus review: PENDING; not reachable from this runtime.  
Fallback mode: independent second adversarial pass focused on transaction timing, event delivery, source linkage, and remaining supplier/AP surfaces.

## Verdict

PASS for H-3 completion.

## Findings

### No BLOCKER/HIGH findings

The listener is synchronous with the current event dispatch. When purchase-order confirmation is inside its database transaction, the created GL entry participates in that transaction and the shared helper defers posting/balance refresh with `DB::afterCommit()`. If the confirmation rolls back, the journal row rolls back too and the post/refresh callback is not executed.

Source linkage is explicit: `source_type = supplier_invoice` and `source_id = purchase_order.id`. A repeated event delivery is covered by a test and does not create a duplicate journal entry.

### MEDIUM — Purchase-order confirmation event itself is still dispatched inside the transaction

Status: DOCUMENTED, not remediated in this slice.

The new accounting listener is transactionally safe because its writes are made inside the active transaction and posting is deferred. Other present or future non-DB listeners on `PurchaseOrderConfirmed` could still observe the event before commit. This is a broader event-sourcing hardening concern and should be handled consistently for document lifecycle events rather than patched ad hoc here.

### LOW — Other supplier-payment entry points remain policy extensions

Status: DOCUMENTED.

H-3 now has a live supplier document writer and a live single-payment supplier-payment writer. `PaymentController::storeMultiple()` and `PaymentAllocationService` still do not implement purchase-order supplier-payment semantics. That should become a separate supplier-payment policy item if those surfaces are expected to support purchase-order allocations.

## Verification Reviewed

- `php artisan test tests/Unit/Document/PurchaseOrderServiceTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/PaymentTest.php` passed 40 tests, 141 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- PHPStan L8 passed on touched production files.
- Pint `--test` passed on touched files.
