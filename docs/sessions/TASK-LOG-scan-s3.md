# Scan S3 Task Log

## Task 7-pre — StandaloneReceiptService idempotency recovery

Files changed:
- `apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php`
- `apps/api/tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`

TDD red:
- `php artisan test tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
- Result: failed as expected before production changes. Replay after a NULL `goods_receipt_id` returned a different receipt id, proving duplicate receipt creation.

Verification:
- `php artisan test tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS: 2 tests, 12 assertions.
- `grep -rl StandaloneReceipt tests/Feature | xargs -I{} php artisan test {}`
  - PASS: `AgedPayablesAutoPoTest` 4 tests, 15 assertions.
  - PASS: `StandaloneReceiptServiceTest` 12 tests, 72 assertions.
  - PASS: `StandaloneReceiptIdempotencyRecoveryTest` 2 tests, 12 assertions.
  - PASS: `SupplierInvoiceApiTest` 52 tests, 306 assertions.
  - PASS: `MatchSuggestionServiceTest` 4 tests, 32 assertions.
- `./vendor/bin/phpstan analyse --debug app/Modules/Procurement/Application/StandaloneReceiptService.php`
  - PASS: no errors.
- `./vendor/bin/pint app/Modules/Procurement/Application/StandaloneReceiptService.php tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS.

Deviations:
- The task brief said no git commits, while the user explicitly requested a commit per task. Following the direct user instruction.

## Task 7 — Commit endpoint + BL committer

Files changed:
- `apps/api/app/Modules/DocumentIngestion/Application/Contracts/IngestionCommitterInterface.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Committers/SupplierDeliveryNoteCommitter.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/CommitResultData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ReviewedBatchData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ReviewedLineData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ReviewedPayloadData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionCommitterRegistry.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/Controllers/DocumentIngestionController.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/Requests/CommitDocumentIngestionRequest.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/routes.php`
- `apps/api/tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php`

TDD red:
- `php artisan test tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php`
- Result: failed as expected before implementation with 404 on `POST /api/v1/document-ingestions/{id}/commit`.

Verification:
- `php artisan test tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php`
  - PASS: 4 tests, 36 assertions.
- `./vendor/bin/phpstan analyse --debug app/Modules/DocumentIngestion app/Modules/Procurement/Application/StandaloneReceiptService.php`
  - PASS: no errors.
- `./vendor/bin/pint app/Modules/DocumentIngestion tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php app/Modules/Procurement/Application/StandaloneReceiptService.php tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS.

Notes:
- Commit endpoint uses atomic `needs_review|committing -> committing` claim and returns stored commit metadata for already committed rows.
- BL committer re-asserts `goods-receipt.create-standalone`, scoped-resolves supplier/location/product/variant, enforces batch requirements, and rejects missing paid-line price with `LINE_PRICE_REQUIRED`.

## Task 8 — SupplierInvoiceCommitter

Files changed:
- `apps/api/app/Modules/DocumentIngestion/Application/Committers/SupplierInvoiceCommitter.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionCommitterRegistry.php`
- `apps/api/tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php`

TDD red:
- `php artisan test tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php`
- Result: failed as expected before implementation because no committer was registered for `supplier_invoice`.

Verification:
- `php artisan test tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php`
  - PASS: 4 tests, 28 assertions.
- `php artisan test tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php`
  - PASS: 4 tests, 36 assertions.
- `./vendor/bin/phpstan analyse --debug app/Modules/DocumentIngestion app/Modules/Procurement/Application/StandaloneReceiptService.php`
  - PASS: no errors.
- `./vendor/bin/pint app/Modules/DocumentIngestion tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php app/Modules/Procurement/Application/StandaloneReceiptService.php tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS.

Notes:
- SI committer derives `source_document_ids` from scoped PO lines and builds the validated payload shape expected by `CreateSupplierInvoiceService`.
- Pending fork re-asserts `supplier-invoices.create-pending`.
- Duplicate supplier reference guard runs in the commit transaction and uses `pg_advisory_xact_lock(hashtext(?))` on PostgreSQL.

## Final S3 verification

By-path regression:
- `find tests/Feature/DocumentIngestion tests/Unit/DocumentIngestion -type f -name '*.php' | sort | xargs -I{} php artisan test {}`
  - PASS: `CommitDeliveryNoteTest` 4 tests, 36 assertions.
  - PASS: `CommitSupplierInvoiceTest` 4 tests, 28 assertions.
  - PASS: `ExtractDocumentJobTest` 5 tests, 29 assertions.
  - PASS: `IngestionStateMachineTest` 5 tests, 9 assertions.
  - PASS: `IngestionUploadTest` 14 tests, 49 assertions.
  - PASS: `MatchSuggestionServiceTest` 4 tests, 32 assertions.
  - PASS: `ErpMlExtractionClientTest` 4 tests, 9 assertions.
  - PASS: `ExtractionReconcilerTest` 4 tests, 13 assertions.
  - PASS: `ExtractionResultDataTest` 3 tests, 9 assertions.
- `php artisan test tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS: 2 tests, 12 assertions.

Static/style:
- `./vendor/bin/phpstan analyse --debug app/Modules/DocumentIngestion app/Modules/Procurement/Application/StandaloneReceiptService.php`
  - PASS: no errors.
- `./vendor/bin/pint app/Modules/DocumentIngestion tests/Feature/DocumentIngestion tests/Unit/DocumentIngestion app/Modules/Procurement/Application/StandaloneReceiptService.php tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`
  - PASS.
