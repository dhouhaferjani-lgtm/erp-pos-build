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

## Final Wave S1 verification

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionStateMachineTest.php tests/Unit/DocumentIngestion/ExtractionResultDataTest.php tests/Feature/DocumentIngestion/IngestionUploadTest.php tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php tests/Unit/DocumentIngestion/ErpMlExtractionClientTest.php tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php tests/Unit/Config/HorizonQueueCoverageTest.php tests/Unit/Modules/Catalog/Media/MediaOwnerTypeTest.php tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php

   PASS  Tests\Feature\DocumentIngestion\IngestionStateMachineTest
  ✓ state machine allows only declared transitions and persists status   1.98s
  ✓ uploaded cannot transition directly to committed                     0.28s
  ✓ failed ingestion can be retried                                      0.30s
  ✓ partial unique index ignores rejected and failed rows only           0.30s

   PASS  Tests\Unit\DocumentIngestion\ExtractionResultDataTest
  ✓ invoice fixture hydrates and round trips without numeric value coer… 0.07s
  ✓ delivery note fixture hydrates without prices                        0.05s
  ✓ numeric field values are rejected instead of cast to strings         0.05s

   PASS  Tests\Feature\DocumentIngestion\IngestionUploadTest
  ✓ upload creates ingestion media asset and attachment                  0.59s
  ✓ duplicate same bytes upload returns validation envelope              0.54s
  ✓ upload requires create permission                                    0.52s
  ✓ bad kind uses validation envelope                                    0.57s
  ✓ list filters by status                                               0.55s
  ✓ detail includes signed source url                                    0.57s
  ✓ reject flips needs review and conflicts from committed               0.55s
  ✓ reextract failed ingestion moves back to extracting                  0.54s

   PASS  Tests\Feature\DocumentIngestion\ExtractDocumentJobTest
  ✓ job is dispatched on ingestion queue                                 0.06s
  ✓ happy path persists extraction and moves to needs review             0.28s
  ✓ client failure marks ingestion failed with structured error and ret… 0.30s

   PASS  Tests\Unit\DocumentIngestion\ErpMlExtractionClientTest
  ✓ successful response returns extraction result data                   0.08s
  ✓ malformed success response throws extraction failed exception        0.04s
  ✓ server error throws extraction failed exception                      0.05s
  ✓ unauthorized response throws extraction failed exception             0.04s

   PASS  Tests\Unit\DocumentIngestion\ExtractionReconcilerTest
  ✓ tnd invoice fixture is consistent at currency scale
  ✓ invoice subtotal off by one millieme is flagged
  ✓ invoice line total mismatch identifies one based line number
  ✓ delivery note without prices is consistent when quantities exist

   PASS  Tests\Feature\DocumentIngestion\MatchSuggestionServiceTest
  ✓ suggestions rank supplier by vat product by sku and open receipt li… 0.32s

   PASS  Tests\Unit\Config\HorizonQueueCoverageTest
  ✓ every dispatched queue is consumed by a horizon supervisor           0.12s
  ✓ every deploy environment has a horizon provisioning entry            0.04s

   PASS  Tests\Unit\Modules\Catalog\Media\MediaOwnerTypeTest
  ✓ document case and storage segments

   PASS  Tests\Unit\Modules\Catalog\Media\MediaEnumsTest
  ✓ enum values are stable strings

  Tests:    31 passed (111 assertions)
  Duration: 8.83s
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --level=8

Note: Using configuration file /Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/phpstan.neon.
  0/19 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
 19/19 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

Notes:
- One attempted final sweep before the successful run failed only because the command path had a typo: `tests/Unit/DocumentInestion/ErpMlExtractionClientTest.php`.

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

## Task 4: ExtractionClient contract + ExtractDocumentJob

Files created/modified:
- `apps/api/app/Shared/Contracts/ExtractionClientInterface.php`
- `apps/api/app/Shared/Contracts/ExtractionHints.php`
- `apps/api/app/Shared/Contracts/ExtractionFailedException.php`
- `apps/api/app/Modules/DocumentIngestion/Infrastructure/ErpMlExtractionClient.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Jobs/ExtractDocumentJob.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionService.php`
- `apps/api/app/Modules/DocumentIngestion/Providers/DocumentIngestionServiceProvider.php`
- `apps/api/config/services.php`
- `apps/api/tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php`
- `apps/api/tests/Unit/DocumentIngestion/ErpMlExtractionClientTest.php`
- `docs/sessions/TASK-LOG-scan-s1.md`

Test commands and outputs:

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php

An error occurred inside PHPUnit.

Message:  Interface "App\Shared\Contracts\ExtractionClientInterface" not found
Location: /Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php:164
```

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ErpMlExtractionClientTest.php

   FAIL  Tests\Unit\DocumentIngestion\ErpMlExtractionClientTest
  ⨯ successful response returns extraction result data                   0.36s
  ⨯ malformed success response throws extraction failed exception        0.05s
  ⨯ server error throws extraction failed exception                      0.05s
  ⨯ unauthorized response throws extraction failed exception             0.04s

  First failure: Class "App\Modules\DocumentIngestion\Infrastructure\ErpMlExtractionClient" not found
```

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php

   PASS  Tests\Feature\DocumentIngestion\ExtractDocumentJobTest
  ✓ job is dispatched on ingestion queue                                 1.68s
  ✓ happy path persists extraction and moves to needs review             0.34s
  ✓ client failure marks ingestion failed with structured error and rethrows 0.28s

  Tests:    3 passed (8 assertions)
  Duration: 2.34s
```

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ErpMlExtractionClientTest.php

   PASS  Tests\Unit\DocumentIngestion\ErpMlExtractionClientTest
  ✓ successful response returns extraction result data                   0.36s
  ✓ malformed success response throws extraction failed exception        0.05s
  ✓ server error throws extraction failed exception                      0.04s
  ✓ unauthorized response throws extraction failed exception             0.04s

  Tests:    4 passed (7 assertions)
  Duration: 0.55s
```

Regression paths rerun:

```text
$ cd apps/api && php artisan test tests/Feature/DocumentIngestion/IngestionUploadTest.php

   PASS  Tests\Feature\DocumentIngestion\IngestionUploadTest
  Tests:    8 passed (32 assertions)
  Duration: 6.39s
```

```text
$ cd apps/api && php artisan test tests/Unit/Config/HorizonQueueCoverageTest.php

   PASS  Tests\Unit\Config\HorizonQueueCoverageTest
  Tests:    2 passed (8 assertions)
  Duration: 0.70s
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --debug

/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Application/Jobs/ExtractDocumentJob.php
/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/app/Modules/DocumentIngestion/Infrastructure/ErpMlExtractionClient.php

 [OK] No errors
```

Deviations:
- Task 4 stores `provider='erp_ml'` and `provider_model=null` because `ExtractionClientInterface::extract()` returns only `ExtractionResultData` per the plan signature. The erp-ml response provider/model are validated by the client boundary but not surfaced through the interface yet.
- Reconciler and suggestions are not invoked in the job until Task 5 creates those services.

## Task 5: bcmath reconciliation + match-or-suggest

Files created/modified:
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/ReconciliationData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/DTO/SuggestionsData.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/ExtractionReconciler.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/MatchSuggestionService.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Jobs/ExtractDocumentJob.php`
- `apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionService.php`
- `apps/api/tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php`
- `apps/api/tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php`
- `apps/api/tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php`
- `packages/shared/types/generated.d.ts`
- `docs/sessions/TASK-LOG-scan-s1.md`

Test commands and outputs:

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php

   FAIL  Tests\Unit\DocumentIngestion\ExtractionReconcilerTest
  ⨯ tnd invoice fixture is consistent at currency scale                  0.01s
  ⨯ invoice subtotal off by one millieme is flagged
  ⨯ invoice line total mismatch identifies one based line number
  ⨯ delivery note without prices is consistent when quantities exist

   FAIL  Tests\Feature\DocumentIngestion\MatchSuggestionServiceTest
  ⨯ suggestions rank supplier by vat product by sku and open receipt li… 2.00s

   FAILED  Tests\Unit\DocumentIngestion\ExtractionReconcilerTest > tnd invoice fixture is consistent at currency scale   Error
  Class "App\Modules\DocumentIngestion\Application\Services\ExtractionReconciler" not found

   FAILED  Tests\Feature\DocumentIngestion\MatchSuggestionServiceTest
  Target class [App\Modules\DocumentIngestion\Application\Services\MatchSuggestionService] does not exist.

  Tests:    5 failed (2 assertions)
  Duration: 2.08s
```

```text
$ cd apps/api && php artisan test tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php

   PASS  Tests\Unit\DocumentIngestion\ExtractionReconcilerTest
  ✓ tnd invoice fixture is consistent at currency scale
  ✓ invoice subtotal off by one millieme is flagged
  ✓ invoice line total mismatch identifies one based line number
  ✓ delivery note without prices is consistent when quantities exist

   PASS  Tests\Feature\DocumentIngestion\MatchSuggestionServiceTest
  ✓ suggestions rank supplier by vat product by sku and open receipt li… 2.07s

   PASS  Tests\Feature\DocumentIngestion\ExtractDocumentJobTest
  ✓ job is dispatched on ingestion queue                                 0.06s
  ✓ happy path persists extraction and moves to needs review             0.28s
  ✓ client failure marks ingestion failed with structured error and ret… 0.29s

  Tests:    8 passed (33 assertions)
  Duration: 2.73s
```

```text
$ cd apps/api && ./vendor/bin/phpstan analyse app/Modules/DocumentIngestion --level=8

Note: Using configuration file /Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc/apps/api/phpstan.neon.
  0/19 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
 19/19 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

```text
$ cd apps/api && CACHE_STORE=array php artisan typescript:transform

| App\Modules\DocumentIngestion\Application\DTO\SuggestionsData      | App.Modules.DocumentIngestion.Application.DTO.SuggestionsData      |
| App\Modules\DocumentIngestion\Application\DTO\ReconciliationData   | App.Modules.DocumentIngestion.Application.DTO.ReconciliationData   |
| App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData | App.Modules.DocumentIngestion.Application.DTO.ExtractionResultData |
Transformed 396 PHP types to TypeScript
```

```text
$ cd apps/api && ./vendor/bin/pint app/Modules/DocumentIngestion tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php

{"result":"fixed","files":[{"path":"app\/Modules\/DocumentIngestion\/Application\/Jobs\/ExtractDocumentJob.php","fixers":["braces_position"]},{"path":"app\/Modules\/DocumentIngestion\/Application\/Services\/IngestionService.php","fixers":["unary_operator_spaces","braces_position","not_operator_with_successor_space","single_line_empty_body","ordered_imports"]}]}
```

Deviations:
- None.
