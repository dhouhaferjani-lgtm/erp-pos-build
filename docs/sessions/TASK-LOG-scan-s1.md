# Scan-to-Document Wave S1 Task Log

## Task 1: Module skeleton — enums, migration, model, state machine

Files created/modified:
- `apps/api/app/Modules/DocumentIngestion/Domain/Enums/DocumentKind.php`
- `apps/api/app/Modules/DocumentIngestion/Domain/Enums/IngestionStatus.php`
- `apps/api/app/Modules/DocumentIngestion/Domain/DocumentIngestion.php`
- `apps/api/app/Modules/DocumentIngestion/Domain/Exceptions/InvalidIngestionTransition.php`
- `apps/api/app/Modules/DocumentIngestion/Providers/DocumentIngestionServiceProvider.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/routes.php`
- `apps/api/database/migrations/tenant/2026_07_06_200000_create_document_ingestions_table.php`
- `apps/api/tests/Feature/DocumentIngestion/IngestionStateMachineTest.php`
- `apps/api/bootstrap/providers.php`
- `docs/sessions/TASK-LOG-scan-s1.md`

Test commands and outputs:

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionStateMachineTest.php

   FAIL  Tests\Feature\DocumentIngestion\IngestionStateMachineTest
  ⨯ state machine allows only declared transitions and persists status   2.40s
  ⨯ uploaded cannot transition directly to committed                     0.28s
  ⨯ failed ingestion can be retried                                      0.29s
  ⨯ partial unique index ignores rejected and failed rows only           0.28s

   FAILED  Tests\Feature\DocumentIngestion\IngestionStateMachineTest > state machine allows only declared transitions and persists status   Error
  Class "App\Modules\DocumentIngestion\Domain\DocumentIngestion" not found

  Tests:    4 failed (0 assertions)
  Duration: 3.32s
```

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionStateMachineTest.php

   PASS  Tests\Feature\DocumentIngestion\IngestionStateMachineTest
  ✓ state machine allows only declared transitions and persists status   3.89s
  ✓ uploaded cannot transition directly to committed                     0.30s
  ✓ failed ingestion can be retried                                      0.27s
  ✓ partial unique index ignores rejected and failed rows only           0.28s

  Tests:    4 passed (8 assertions)
  Duration: 4.85s
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --debug

Note: Using configuration file /Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/phpstan.neon.
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Domain/DocumentIngestion.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Domain/Enums/DocumentKind.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Domain/Enums/IngestionStatus.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Domain/Exceptions/InvalidIngestionTransition.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Presentation/routes.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Providers/DocumentIngestionServiceProvider.php

 [OK] No errors
```

Deviations:
- Direct commit is allowed in this environment by the user, overriding the session brief's original no-commit protocol.

## Task 2: Extraction DTOs (JSONB contract)

Files created/modified:
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractedFieldData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractedLineData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractionResultData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ConfidenceSummaryData.php`
- `apps/api/tests/Fixtures/document_ingestion/extraction_invoice_fr.json`
- `apps/api/tests/Fixtures/document_ingestion/extraction_bl_fr.json`
- `apps/api/tests/Unit/DocumentIngestion/ExtractionResultDataTest.php`
- `packages/shared/types/generated.d.ts`
- `docs/sessions/TASK-LOG-scan-s1.md`

Test commands and outputs:

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ExtractionResultDataTest.php

   FAIL  Tests\Unit\DocumentIngestion\ExtractionResultDataTest
  ⨯ invoice fixture hydrates and round trips without numeric value coercion 0.41s
  ⨯ delivery note fixture hydrates without prices                        0.07s
  ⨯ numeric field values are rejected instead of cast to strings         0.05s

   FAILED  Tests\Unit\DocumentIngestion\ExtractionResultDataTest > invoice fixture hydrates and round trips without numeric value coercion   Error
  Class "App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData" not found

  Tests:    3 failed (4 assertions)
  Duration: 0.59s
```

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ExtractionResultDataTest.php

   PASS  Tests\Unit\DocumentIngestion\ExtractionResultDataTest
  ✓ invoice fixture hydrates and round trips without numeric value coercion 0.31s
  ✓ delivery note fixture hydrates without prices                        0.04s
  ✓ numeric field values are rejected instead of cast to strings         0.04s

  Tests:    3 passed (9 assertions)
  Duration: 0.44s
```

```text
$ cd apps/api && CACHE_STORE=array php artisan typescript:transform

| App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData    | App.Modules.DocumentIngestion.Application.DTO.ExtractedFieldData    |
| App\Modules\DocumentIngestion\Application\DTO\ExtractedLineData     | App.Modules.DocumentIngestion.Application.DTO.ExtractedLineData     |
| App\Modules\DocumentIngestion\Application\DTO\ConfidenceSummaryData | App.Modules.DocumentIngestion.Application.DTO.ConfidenceSummaryData |
| App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData  | App.Modules.DocumentIngestion.Application.DTO.ExtractionResultData  |
| App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus          | App.Modules.DocumentIngestion.Domain.Enums.IngestionStatus          |
| App\Modules\DocumentIngestion\Domain\Enums\DocumentKind             | App.Modules.DocumentIngestion.Domain.Enums.DocumentKind             |
Transformed 394 PHP types to TypeScript
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --debug

/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/DTO/ConfidenceSummaryData.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractedFieldData.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractedLineData.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/DTO/ExtractionResultData.php

 [OK] No errors
```

Deviations:
- None.

## Task 3: MediaOwnerType + upload/list/detail/reject endpoints + permissions + queue registration

Files created/modified:
- `apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionService.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/Controllers/DocumentIngestionController.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/Requests/StoreDocumentIngestionRequest.php`
- `apps/api/app/Modules/DocumentIngestion/Presentation/routes.php`
- `apps/api/app/Modules/DocumentIngestion/Domain/DocumentIngestion.php`
- `apps/api/app/Modules/Media/Domain/Enums/MediaOwnerType.php`
- `apps/api/config/horizon.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/tests/Feature/DocumentIngestion/IngestionUploadTest.php`
- `apps/api/tests/Unit/Modules/Catalog/Media/MediaOwnerTypeTest.php`
- `docs/sessions/TASK-LOG-scan-s1.md`

Test commands and outputs:

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionUploadTest.php

   FAIL  Tests\Feature\DocumentIngestion\IngestionUploadTest
  ⨯ upload creates ingestion media asset and attachment                  2.36s
  ⨯ duplicate same bytes upload returns validation envelope              0.55s
  ⨯ upload requires create permission                                    0.56s
  ⨯ bad kind uses validation envelope                                    0.59s
  ⨯ list filters by status                                               0.61s
  ⨯ detail includes signed source url                                    0.78s
  ⨯ reject flips needs review and conflicts from committed               0.54s
  ⨯ reextract failed ingestion moves back to extracting                  0.54s

  First failures: POST /api/v1/document-ingestions returned 404; MediaOwnerType::DocumentIngestion was undefined.
  Tests:    8 failed (5 assertions)
  Duration: 6.59s
```

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionUploadTest.php

   PASS  Tests\Feature\DocumentIngestion\IngestionUploadTest
  ✓ upload creates ingestion media asset and attachment                  2.50s
  ✓ duplicate same bytes upload returns validation envelope              0.58s
  ✓ upload requires create permission                                    0.67s
  ✓ bad kind uses validation envelope                                    0.80s
  ✓ list filters by status                                               0.87s
  ✓ detail includes signed source url                                    0.89s
  ✓ reject flips needs review and conflicts from committed               0.62s
  ✓ reextract failed ingestion moves back to extracting                  0.57s

  Tests:    8 passed (32 assertions)
  Duration: 7.57s
```

```text
$ cd apps/api && php artisan test tests/Unit/Config/HorizonQueueCoverageTest.php

   PASS  Tests\Unit\Config\HorizonQueueCoverageTest
  ✓ every dispatched queue is consumed by a horizon supervisor           0.47s
  ✓ every deploy environment has a horizon provisioning entry            0.05s

  Tests:    2 passed (8 assertions)
  Duration: 0.57s
```

```text
$ cd apps/api && php artisan test tests/Unit/Modules/Catalog/Media/MediaOwnerTypeTest.php

   PASS  Tests\Unit\Modules\Catalog\Media\MediaOwnerTypeTest
  ✓ document case and storage segments

  Tests:    1 passed (7 assertions)
  Duration: 0.05s
```

```text
$ cd apps/api && php artisan test tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php

   PASS  Tests\Unit\Modules\Catalog\Media\MediaEnumsTest
  ✓ enum values are stable strings

  Tests:    1 passed (7 assertions)
  Duration: 0.05s
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --debug

/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionService.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Presentation/Controllers/DocumentIngestionController.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Presentation/Requests/StoreDocumentIngestionRequest.php

 [OK] No errors
```

Regression paths rerun:

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionStateMachineTest.php

   PASS  Tests\Feature\DocumentIngestion\IngestionStateMachineTest
  Tests:    4 passed (8 assertions)
  Duration: 3.11s
```

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ExtractionResultDataTest.php

   PASS  Tests\Unit\DocumentIngestion\ExtractionResultDataTest
  Tests:    3 passed (9 assertions)
  Duration: 0.55s
```

Deviations:
- `ExtractDocumentJob` dispatch is deferred to Task 4, where the plan explicitly creates the job and client contract. Task 3 endpoints create/upload/reject/list/detail and move re-extractable rows to `extracting`; Task 4 will add the actual queued extraction dispatch.
