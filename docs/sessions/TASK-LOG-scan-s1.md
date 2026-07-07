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
