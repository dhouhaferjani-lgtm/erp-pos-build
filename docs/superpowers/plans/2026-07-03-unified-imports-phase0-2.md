# Unified Imports (Phases 0–2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Codex workers in this worktree: NO git write commands** (worktree index lives outside your sandbox). After each task's green verification, append the task number, exact file list, and red/green evidence to `docs/sessions/UNIFIED-IMPORTS-TASKLOG.md`. The orchestrator cuts commits.

**Goal:** Ship the unified two-file import — parties with signed opening balances, sell-ready products with price resolution and opening stock — per spec `docs/superpowers/specs/2026-07-02-unified-imports-design.md` (FINAL v3 + plan-phase addendum v4; the addendum is authoritative where they differ), phases 0–2 only. Phase 3 (enrichment) is a separate follow-up plan.

**Architecture:** New import type `parties` and extended `products` type inside the existing Import module engine. Two engine-level additions (row warnings channel, job options ingestion) land first, then the parties pipeline (partner code upsert → signed-balance → AR/AP open-item batches via repaired `ArApOpeningService`), then the products pipeline (price resolver → product upsert contract → opening stock via `OpeningBalancePostingService` → result workbook). Cross-module boundary: Import consumes `ArApOpeningService`, `OpeningBalanceBatchService`, and `OpeningBalancePostingService` as **module-public application services** (explicitly allowed by CLAUDE.md rule 6: "Shared/Contracts interfaces, Events, or a module's public Service class" — the spec's contracts table is amended accordingly); the one NEW Shared contract is `TaxDefaultResolverInterface` (TaxResolutionService sits in Taxation's Domain layer, not a public application service).

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (`RefreshDatabase` + `RolesAndPermissionsSeeder`), bcmath via `CurrencyScale`, React 19 + TanStack Query 5 + Vitest, PhpSpreadsheet.

## Global Constraints

- **NEVER run the full PHPUnit suite.** Run tests BY PATH only: `./vendor/bin/phpunit tests/Feature/Import/XyzTest.php` from `apps/api`.
- Money: `CurrencyScale::bcformatStrict($v, 3)` at storage scale 3 after `NumericFieldNormalizer`; intermediates at scale+1; quantity scale 4 (`QuantityScale`). Never a float on money/qty. Signed money regex `/^-?\d+(\.\d{1,3})?$/`; percent regex `/^-?\d+(\.\d{1,2})?$/`.
- `products.sale_price` is stored **TTC** (tax-inclusive). `purchase_price`/cost is HT. Margin = markup on cost: `HT = cost × (1 + m/100)`; `TTC = HT × (1 + tax/100)`.
- Constructor injection with `private readonly` ONLY — never `app()`.
- No `mixed` in PHP; no hardcoded FE strings — all UI text via `t()` with keys in `apps/web/src/locales/{en,fr}/import.json`. **Deliberate spec exception (recorded):** `ar/import.json` is a pre-existing 1-key stub and Arabic falls back to English for this namespace via the `{...enImport, ...arImport}` merge in `i18n.ts` — do not expand it in this plan; full ar translation is a standalone follow-up.
- Cross-module calls only via `App\Shared\Contracts\*` interfaces, events, or a module's public service.
- FE: `apiGet`/`apiPost` already unwrap `response.data.data` — never double-unwrap. Money/qty inputs emit strings.
- Every new `onQueue('x')` needs a `config/horizon.php` entry — this plan only uses the existing `imports` queue.
- PHPStan level 8 zero errors on new code: `./vendor/bin/phpstan analyse <changed paths> --memory-limit=1G`. Pint: `./vendor/bin/pint <changed paths>`.
- Run `php artisan typescript:transform` only if you change PHP DTOs used by the FE (Task 16 checks drift).
- All commands below run from `apps/api` unless stated otherwise. FE commands run from `apps/web`.

## File Structure (locked decomposition)

| Unit | File | Responsibility |
|---|---|---|
| AR/AP lifecycle fix | `app/Modules/Document/Application/Services/ArApOpeningService.php` | validate→post order; company currency |
| Warnings channel | `database/migrations/tenant/2026_07_03_200000_add_warnings_to_import_rows_table.php`, `app/Modules/Import/Domain/ImportRow.php`, `app/Modules/Import/Services/ImportService.php` | non-blocking row warnings |
| Options ingestion | `app/Modules/Import/Presentation/Controllers/ImportController.php`, `app/Modules/Import/Services/ImportService.php`, `app/Modules/Import/Providers/ImportServiceProvider.php` | job options create/patch |
| Parties type | `app/Modules/Import/Domain/Enums/ImportType.php` | enum case + rules/columns |
| Partner code upsert | `app/Modules/Partner/Application/Services/PartnerService.php`, `app/Shared/Contracts/PartnerServiceInterface.php` (docblock only) | code-first upsert key |
| Parties mapping | `app/Modules/Import/Services/PartiesRowMapper.php` | pure row→partner/balance payload + sign quadrants |
| Balances phase | `app/Modules/Import/Services/PartiesBalancesPhase.php`, `database/migrations/tenant/2026_07_03_200001_add_unique_import_reference_to_opening_balance_batches.php` | AR/AP batch orchestration + retry idempotency |
| Permission gate | `app/Modules/Import/Providers/ImportServiceProvider.php` | `can:imports.manage` on routes |
| Products price resolver | `app/Modules/Import/Services/ProductPriceResolver.php`, `app/Shared/Contracts/TaxDefaultResolverInterface.php`, `app/Modules/Taxation/Providers/*ServiceProvider.php` (binding) | TTC/HT/margin → canonical sale_price |
| Product upsert contract | `app/Modules/Product/Application/Services/ProductService.php` | file-sku→barcode precedence, generated sku, brand, type default |
| Products opening stock | `app/Modules/Import/Services/ProductOpeningStockPhase.php` | qty → OpeningBalancePostingService |
| Result workbook | `app/Modules/Import/Services/ResultWorkbookService.php`, `ImportController::downloadResultWorkbook` | 3-sheet XLSX |
| FE | `apps/web/src/features/import/{types.ts, api/importApi.ts, pages/ImportWizardPage.tsx, pages/ImportDashboardPage.tsx, pages/ImportHistoryPage.tsx}`, `apps/web/src/locales/{en,fr}/import.json` | parties card, options step, workbook download, Advanced section |

Execution wiring: `ImportService::finalizeImport()` (new) is called after the row loop by BOTH `ImportService::executeImport()` (sync) and `ProcessImportJob::processImport()` (async) — it runs the parties balances phase / products opening-stock phase per type.

---

### Task 1: Repair `ArApOpeningService::postBatch()` lifecycle order

**Files:**
- Modify: `app/Modules/Document/Application/Services/ArApOpeningService.php:320-330`
- Test: `tests/Feature/Document/ArApOpeningPostLifecycleTest.php` (create)

**Interfaces:**
- Consumes: `OpeningBalanceBatchService::{createBatch, addImportRows, markBatchValidated, markRowsPosted}`, `ArApOpeningService::{validateBatch, postBatch}` — existing signatures, unchanged.
- Produces: a `postBatch()` that actually completes: batch ends `Validated`, all posted rows end `Posted`, one historical `Document` per valid row.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArApOpeningPostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // SETUP: Tests\TestCase has NO fixture helpers. Copy the exact setUp() from
    // tests/Feature/Accounting/OpeningBalanceBatchTest.php:51-107 into this class
    // (private $tenant/$company/$user properties, tenant DB bootstrapping, seeder call)
    // BEFORE writing the test body. Use $this->company / $this->user->id below.

    public function test_post_batch_completes_and_marks_rows_posted(): void
    {
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => 'CUST-001',
            'type' => 'customer',
        ]);

        $batchService = app(OpeningBalanceBatchService::class);
        $service = app(ArApOpeningService::class);

        $batch = $batchService->createBatch($company, OpeningBatchType::ArOpenItems, now(), 'TEST-AR', $this->user->id, 'phpunit');
        $batchService->addImportRows($batch, [[
            'partner_code' => 'CUST-001',
            'external_invoice_number' => 'LEG-1',
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'total' => '100.000',
            'open_amount' => '100.000',
            'currency' => $company->currency,
            'document_type' => 'invoice',
            'notes' => null,
        ]]);
        $service->validateBatch($batch->refresh());

        $result = $service->postBatch($batch->refresh(), $this->user->id);

        $this->assertSame(1, $result['documents_created']);
        $this->assertSame(OpeningBatchStatus::Validated, $batch->refresh()->status);
        $this->assertSame(1, $batch->rows()->where('status', OpeningImportRowStatus::Posted)->count());
        $this->assertSame(1, Document::where('company_id', $company->id)->where('is_historical', true)->count());
    }
}
```

Note for the implementer: `app()` in tests is fine (tests are not production code). Keep the assertion block as-is.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Document/ArApOpeningPostLifecycleTest.php`
Expected: FAIL — `RuntimeException: Cannot validate batch: no valid rows to process.` (thrown from `markBatchValidated` after `markRowsPosted` flipped rows to Posted)

- [ ] **Step 3: Swap the order in `postBatch()`**

In `ArApOpeningService::postBatch()`, the current tail is:

```php
            // Mark rows as posted
            $this->batchService->markRowsPosted($rowEntityMap);

            // Mark batch as validated (posted)
            $this->batchService->markBatchValidated($batch, $userId);
```

Replace with (validate while rows are still `Valid`, then mark them posted):

```php
            // Transition the batch first — markBatchValidated requires the rows
            // to still be in Valid status (a Posted row no longer counts as valid).
            $this->batchService->markBatchValidated($batch, $userId);

            $this->batchService->markRowsPosted($rowEntityMap);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Document/ArApOpeningPostLifecycleTest.php`
Expected: PASS

- [ ] **Step 5: Guards**

Run: `./vendor/bin/phpstan analyse app/Modules/Document/Application/Services/ArApOpeningService.php tests/Feature/Document/ArApOpeningPostLifecycleTest.php --memory-limit=1G` → 0 errors; `./vendor/bin/pint app/Modules/Document tests/Feature/Document` → clean.

- [ ] **Step 6: Log the task**

Append to `docs/sessions/UNIFIED-IMPORTS-TASKLOG.md`: task number, files touched, the red output line and the green summary line.

### Task 2: Company currency instead of hardcoded `'TND'` in AR/AP rows

**Files:**
- Modify: `app/Modules/Document/Application/Services/ArApOpeningService.php` (validateRow ~L215 `'currency' => $mappedData['currency'] ?? 'TND'` and the Document-create `'currency' => $mappedData['currency'] ?? 'TND'`)
- Test: extend `tests/Feature/Document/ArApOpeningPostLifecycleTest.php`

**Interfaces:**
- Produces: rows with no `currency` value resolve to `$batch->company->currency`; a row whose currency ≠ company currency fails row validation with error `Currency must match company currency (<code>)`.

- [ ] **Step 1: Write the failing test** (add to the Task 1 test class)

```php
    public function test_missing_currency_defaults_to_company_currency_and_foreign_currency_is_rejected(): void
    {
        // setup identical to test 1 (extract a private helper makeArBatch(array $rowOverrides) while you're here)
        // Row A: no 'currency' key at all      → after validateBatch: row Valid; after postBatch: Document currency === $company->currency (NOT 'TND' — set the test company currency to 'EUR' in setup to prove it)
        // Row B: 'currency' => 'USD' (company EUR) → after validateBatch: row Invalid with a currency error
        // Assert both.
    }
```

Write this as real code following the Task 1 shape — two rows through `addImportRows`, assertions on `OpeningBalanceImportRow::status`, `validation_errors`, and the created `Document->currency`. The company factory must set `'currency' => 'EUR'`.

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/phpunit tests/Feature/Document/ArApOpeningPostLifecycleTest.php --filter currency` → FAIL (document currency is `TND`).

- [ ] **Step 3: Implement**

In `validateRow()` (has access to the batch via caller — pass company currency in): change `ArApOpeningService::validateBatch()` to load `$companyCurrency = $batch->company->currency;` once and pass it to `validateRow()`. Inside `validateRow()`:

```php
        $currency = trim((string) ($rawData['currency'] ?? ''));
        if ($currency === '') {
            $currency = $companyCurrency;
        } elseif ($currency !== $companyCurrency) {
            $errors[] = "Currency must match company currency ({$companyCurrency}).";
        }
        // mapped_data gets 'currency' => $currency
```

In `postBatch()` replace `'currency' => $mappedData['currency'] ?? 'TND'` with `'currency' => $mappedData['currency']` (validateRow now guarantees it). Update the `validateRow` docblock row-keys list.

- [ ] **Step 4: Run to verify pass** — same filter → PASS. Re-run the full file: `./vendor/bin/phpunit tests/Feature/Document/ArApOpeningPostLifecycleTest.php` → PASS. Also run the existing neighbors to catch regressions: `./vendor/bin/phpunit tests/Feature/Accounting/OpeningBalanceBatchTest.php` → PASS.

- [ ] **Step 5: Guards + log** — phpstan/pint on changed paths; append to TASKLOG.

### Task 3: Row warnings channel (engine)

**Files:**
- Create: `database/migrations/tenant/2026_07_03_200000_add_warnings_to_import_rows_table.php`
- Modify: `app/Modules/Import/Domain/ImportRow.php` (fillable + casts), `app/Modules/Import/Services/ImportService.php` (helper), `app/Modules/Import/Presentation/Controllers/ImportController.php` (`formatJob` + `errors` payload)
- Test: `tests/Feature/Import/ImportRowWarningsTest.php` (create)

**Interfaces:**
- Produces: `ImportRow->warnings: ?array` (list of `{code: string, detail: string}`); `ImportService::addRowWarning(ImportRow $row, string $code, string $detail): void` (append, persists); job API payloads expose `warning_rows` (count of rows with ≥1 warning) on `formatJob`, and each row in `GET /imports/{id}/errors` response gains a `warnings` key. Warnings NEVER affect `is_valid`, `is_imported`, `failed_rows`, or the failed-rows CSV.

- [ ] **Step 1: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->jsonb('warnings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dropColumn('warnings');
        });
    }
};
```

- [ ] **Step 2: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportRowWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warnings_accumulate_and_do_not_affect_validity_or_failed_counts(): void
    {
        // setup: copy the job-creation pattern from tests/Feature/Import/ImportTypesTest.php (read it first)
        $service = app(ImportService::class);
        $job = /* createJob(..., ImportType::Partners, ...) */;
        $row = $service->addRow($job, 1, ['name' => 'ACME', 'type' => 'customer']);

        $service->addRowWarning($row, 'price_conflict', 'provided 12.00 vs derived 11.90');
        $service->addRowWarning($row, 'balance_not_posted', 'opening balances locked');

        $row->refresh();
        $this->assertCount(2, $row->warnings);
        $this->assertSame('price_conflict', $row->warnings[0]['code']);

        $service->validateJob($job);
        $this->assertTrue($row->refresh()->is_valid);          // warnings don't invalidate
        $this->assertSame(0, $job->refresh()->failed_rows);    // nor count as failed
    }
}
```

- [ ] **Step 3: Run → FAIL** (`addRowWarning` undefined / unknown column). **Step 4: Implement** — `ImportRow`: add `'warnings'` to `$fillable`, `'warnings' => 'array'` to casts. `ImportService`:

```php
    /**
     * Append a non-blocking warning to a row. Warnings never affect validity,
     * import success, failed-row counts, or the failed-rows export.
     */
    public function addRowWarning(ImportRow $row, string $code, string $detail): void
    {
        $warnings = $row->warnings ?? [];
        $warnings[] = ['code' => $code, 'detail' => $detail];
        $row->update(['warnings' => $warnings]);
    }
```

`ImportController::formatJob()`: add `'warning_rows' => $job->rows()->whereRaw("jsonb_array_length(warnings) > 0")->count(),` (convention: warnings are cleared by setting `null`, never `[]` — jsonb_array_length guards against empty arrays regardless). In `errors()`, include `'warnings' => $row->warnings,` per row. Confirm `FailedRowsExportService::generateFailedRowsCsv` query (`is_valid=false OR import_error IS NOT NULL`) is untouched — warnings-only rows must not appear; assert that in the test with one warnings-only row + `generateFailedRowsCsv()` returning null.

- [ ] **Step 5: Run → PASS; guards; TASKLOG.**

Run: `./vendor/bin/phpunit tests/Feature/Import/ImportRowWarningsTest.php`; phpstan/pint changed paths.

### Task 4: Job options ingestion (`POST /imports` + `PATCH /imports/{id}/options`)

**Files:**
- Modify: `app/Modules/Import/Services/ImportService.php` (`createJob` signature), `app/Modules/Import/Presentation/Controllers/ImportController.php` (store validation + new `updateOptions`), `app/Modules/Import/Providers/ImportServiceProvider.php` (route)
- Test: `tests/Feature/Import/ImportJobOptionsTest.php` (create)

**Interfaces:**
- Produces: `createJob(..., ?array $columnMapping = null, ?array $options = null)` persisting to existing `import_jobs.options` jsonb; `POST /imports` accepts optional `options` object `{location_code?: string, enrichment_enabled?: bool, price_authority?: 'ttc'|'ht'|'margin'}`; `PATCH /imports/{id}/options` (same validation) allowed only while `status` in `pending|validating|validated` → else 409; `formatJob` exposes `options`.

- [ ] **Step 1: Failing test** — HTTP feature test (copy auth/company-header setup from `tests/Feature/Import/ImportTypesTest.php`):
  - POST /imports with `options[price_authority]=ttc` → job persisted with options.
  - POST with `options[price_authority]=bogus` → 422.
  - PATCH options on a pending job → 200, options updated.
  - PATCH after forcing `status=importing` on the model → 409.

Write all four as real assertions (`postJson`/`patchJson`, `assertStatus`, DB assertion on `import_jobs.options`).

- [ ] **Step 2: Run → FAIL. Step 3: Implement.**

Store validation addition:

```php
            'options' => ['sometimes', 'array'],
            'options.location_code' => ['sometimes', 'string', 'max:100'],
            'options.enrichment_enabled' => ['sometimes', 'boolean'],
            'options.price_authority' => ['sometimes', 'in:ttc,ht,margin'],
```

Controller `updateOptions(Request $request, string $id)`: resolve job (tenant-scoped, same as `show`), reject with `response()->json(['error' => ['code' => 'IMPORT_ALREADY_STARTED']], 409)` when `! in_array($job->status, [ImportStatus::Pending, ImportStatus::Validating, ImportStatus::Validated], true)`; validate with `'options' => ['required', 'array']` (+ the same `options.*` rules — required on PATCH, unlike store, so `$validated['options']` can never be undefined); `$job->update(['options' => array_merge($job->options ?? [], $validated['options'])])`. Route in provider: `Route::patch('/imports/{id}/options', [ImportController::class, 'updateOptions']);` inside the existing middleware group.

- [ ] **Step 4: Run → PASS; guards; TASKLOG.**

### Task 5: `ImportType::Parties` enum case + validation + template

**Files:**
- Modify: `app/Modules/Import/Domain/Enums/ImportType.php`; `app/Modules/Import/Services/MigrationWizardService.php` — it has SIX type-keyed structures that ALL need a `parties` entry: recommended order (~L23-31: parties FIRST, before products), dependencies (~L44-82: parties has none), header aliases (~L153-173: add FR aliases `solde`→`opening_balance`, `solde_client`→`opening_balance_customer`, `solde_fournisseur`→`opening_balance_supplier`, `nom`→`name`, `téléphone`→`phone`), example rows (~L198-323: one customer row with positive balance + one supplier row with negative balance), migration status (~L347-376), metadata (~L384-417) — `UnhandledMatchError` at runtime if any `match` arm is missed; grep `ImportType::` across `app/` for exhaustive matches after editing; `app/Modules/Import/Services/ImportService.php` (`importRow` match arm — temporary `throw` until Task 8 wires it)
- Test: `tests/Feature/Import/PartiesImportTypeTest.php` (create)

**Interfaces:**
- Produces: `ImportType::Parties` (`'parties'`) with `getRequiredColumns() = ['name','type']`, `getOptionalColumns() = ['code','email','phone','tax_id','address_line1','address_city','address_postal_code','address_country','opening_balance','opening_balance_customer','opening_balance_supplier','balance_date','reference']`, validation rules:

```php
            self::Parties => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'in:customer,supplier,both'],
                'code' => ['nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'tax_id' => ['nullable', 'string', 'max:50'],
                'opening_balance' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'opening_balance_customer' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'opening_balance_supplier' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'balance_date' => ['nullable', 'date'],
                'reference' => ['nullable', 'string', 'max:100'],
            ],
```

Every other `match ($this)` in the enum AND every `match ($job->type)` in the codebase must gain a `Parties` arm (grep `ImportType::` for exhaustive matches — PHPStan will also flag them). `ImportService::importRow` gets `ImportType::Parties => $this->importPartner($job->tenant_id, $this->partiesRowToPartnerData($row->data), $companyId)` — see Task 7 for `partiesRowToPartnerData` (in this task, add the arm calling the yet-unwritten private method and write the method as a thin passthrough mapping `tax_id`→`vat_number`, `address_line1`→`street_address`, `address_city`→`city`, `address_postal_code`→`postal_code`, `address_country`→`country`).

- [ ] **Step 1: Failing test** — assert `ImportType::from('parties')` columns/rules; template endpoint `GET /migration-wizard/template/parties` returns 200 CSV containing `opening_balance` header; `POST /imports` with type `parties` + a small CSV (name,type,opening_balance) creates a validated job (reuse the multipart upload pattern from `tests/Feature/Import/MigrationWizardTest.php`).
- [ ] **Step 2: Run → FAIL (ValueError). Step 3: Implement per above. Step 4: Run → PASS.** Also re-run `./vendor/bin/phpunit tests/Feature/Import/ImportTypesTest.php tests/Feature/Import/MigrationWizardTest.php` (regression on exhaustive matches).
- [ ] **Step 5: Guards; TASKLOG.**

### Task 6: Partner `code`-first upsert contract

**Files:**
- Modify: `app/Modules/Partner/Application/Services/PartnerService.php` (`upsertWithTypeMerge`), `app/Shared/Contracts/PartnerServiceInterface.php` (docblock only — signature unchanged)
- Test: `tests/Feature/Partner/PartnerCodeUpsertTest.php` (create)

**Interfaces:**
- Produces: `upsertWithTypeMerge($tenantId, $companyId, $data)` where `$data['code']` (when non-empty) is the company-scoped match key (precedence: `code` → `vat_number` → `name`), `code` is persisted, and a code-match with different name updates the name (code wins). Type merging behavior unchanged (customer+supplier→both).

- [ ] **Step 1: Failing test** — four cases as separate test methods, real assertions on the Partner table:
  1. new code → creates partner with code persisted;
  2. same code re-import with different name/email → same partner id, name updated, no duplicate;
  3. no code, vat match → existing behavior preserved (regression);
  4. code present but blank string → falls back to vat/name path.
- [ ] **Step 2: Run → FAIL (code not persisted). Step 3: Implement:**

```php
        $code = ! empty($data['code']) ? trim((string) $data['code']) : null;
        // search precedence: code → vat_number → name
        $existing = Partner::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($code !== null, fn ($q) => $q->where('code', $code))
            ->when($code === null && $vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
            ->when($code === null && $vatNumber === null, fn ($q) => $q->where('name', $data['name']))
            ->first();
```

`$searchCriteria` gets the same precedence (`['code' => $code]` variant first); add `'code' => $code,` to the write payload. Keep everything else identical.

- [ ] **Step 4: Run → PASS.** Regression: `./vendor/bin/phpunit tests/Feature/Import/ImportTypesTest.php --filter -i partner` if partner import tests exist there (grep first); run any `tests/Feature/Partner/*.php` neighbors by path.
- [ ] **Step 5: Guards; TASKLOG.**

### Task 7: `PartiesRowMapper` — sign quadrants + type-scoped validation (pure unit)

**Files:**
- Create: `app/Modules/Import/Services/PartiesRowMapper.php`
- Modify: `app/Modules/Import/Services/ImportService.php` (`validateJob` type hook; replace the Task 5 thin mapping with the mapper), `app/Modules/Import/Providers/ImportServiceProvider.php` (**REQUIRED: `ImportService` is constructed MANUALLY in the provider (`new ImportService(...)` ~L34) — every constructor change must update that argument list or the container fails before any test runs**)
- Test: `tests/Unit/Import/PartiesRowMapperTest.php` (create)

**Interfaces:**
- Produces (consumed by Task 8):

```php
namespace App\Modules\Import\Services;

final class PartiesRowMapper
{
    /** @param array<string, mixed> $data @return array<string, mixed> partner payload for PartnerServiceInterface */
    public function toPartnerData(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array{ar: ?array<string,mixed>, ap: ?array<string,mixed>} open-item payloads (keys:
     *   partner_code, external_invoice_number, document_date, due_date, total, open_amount, document_type, notes)
     *   — currency intentionally omitted; PartiesBalancesPhase injects company currency.
     */
    public function toBalancePayloads(array $data, string $defaultBalanceDate, string $partnerCode): array;

    /** @param array<string, mixed> $data @return list<string> extra validation errors (empty = ok) */
    public function extraValidationErrors(array $data): array;
}
```

Mapping rules (exact):
- partner payload: `name,type,email,phone` pass through; `code` → `code`; `tax_id` → `vat_number`; `address_line1` → `street_address`; `address_city` → `city`; `address_postal_code` → `postal_code`; `address_country` → `country`.
- balances: effective customer balance = `opening_balance` when `type=customer` else `opening_balance_customer`; supplier symmetric. `'0'`/`'0.000'`/empty/null → null (no entry). Quadrants: customer positive → `ar` `document_type=invoice`; customer negative → `ar` `credit_note`; supplier positive → `ap` `invoice`; supplier negative → `ap` `credit_note`. Magnitude `= CurrencyScale::bcformatStrict(ltrim($value, '-'), 3)` into BOTH `total` and `open_amount`. `document_date = due_date = data['balance_date'] ?: $defaultBalanceDate`. `external_invoice_number = data['reference'] ?: null`, `notes = null`.
- `extraValidationErrors`: `type=both` AND `opening_balance` non-empty → `"For partners of type 'both', use opening_balance_customer / opening_balance_supplier instead of opening_balance."`; `type=customer` AND `opening_balance_supplier` non-empty → error (symmetric for supplier/customer column misuse).

- [ ] **Step 1: Failing unit test** — cover: all four sign quadrants (assert document_type + magnitude `'100.000'` from input `'-100'`), zero/empty → null, both-row error, misuse error, balance_date default fallback, tax_id→vat_number mapping. Pure `new PartiesRowMapper()` — no DB.
- [ ] **Step 2: Run → FAIL. Step 3: Implement (pure PHP, bcmath only via CurrencyScale). Step 4: Run → PASS:** `./vendor/bin/phpunit tests/Unit/Import/PartiesRowMapperTest.php`
- [ ] **Step 5: Wire the validation hook** — in `ImportService::validateJob()`, after the generic chunked validation, add:

```php
        if ($job->type === ImportType::Parties) {
            $this->applyPartiesExtraValidation($job); // iterates rows where is_valid, runs $this->partiesRowMapper->extraValidationErrors(), flips is_valid=false + merges into errors, decrements/recounts successful_rows/failed_rows
        }
```

Implement `applyPartiesExtraValidation` accordingly (chunked, batch update, then recount `successful_rows`/`failed_rows` from the table — don't drift the counters). Inject `PartiesRowMapper` via constructor. Extend `PartiesImportTypeTest` (Task 5 file) with one both+opening_balance row → job has that row invalid with the exact message.
- [ ] **Step 6: Run both test files → PASS; guards; TASKLOG.**

### Task 8: `PartiesBalancesPhase` — AR/AP batch orchestration + `finalizeImport` wiring

**Files:**
- Create: `app/Modules/Import/Services/PartiesBalancesPhase.php`, `database/migrations/tenant/2026_07_03_200001_add_unique_import_reference_to_opening_balance_batches.php`
- Modify: `app/Modules/Import/Services/ImportService.php` (`finalizeImport` + call in `executeImport`), `app/Modules/Import/Application/Jobs/ProcessImportJob.php` (call `finalizeImport` after row loop), `app/Modules/Import/Providers/ImportServiceProvider.php` (constructor args — see Task 7 warning)
- Test: `tests/Feature/Import/PartiesImportBalancesTest.php` (create)

**Interfaces:**
- Consumes: `PartiesRowMapper` (Task 7), `ArApOpeningService::{validateBatch, postBatch}` (Tasks 1-2), `OpeningBalanceBatchService::{createBatch, addImportRows, updateFileReference, hasUnlockedBatch, deleteBatch/clearImportRows}`, `ImportService::addRowWarning` (Task 3).
- Produces: `PartiesBalancesPhase::run(ImportJob $job, string $companyId): void` — called by `ImportService::finalizeImport`. Also `ImportService::finalizeImport(ImportJob $job, string $companyId): void` — dispatches per type (`Parties` → balances phase; `Products` → Task 14's phase; others → no-op). Sub-results ride `row->data['_results']` (`['partner' => 'ok', 'ar_balance' => 'ok'|'error: …'|'skipped', 'ap_balance' => …]`).

**`import_file_reference` is a `jsonb` column cast to array** (`OpeningBalanceBatch` casts, `updateFileReference(OpeningBalanceBatch $batch, array $fileReference)`). The stored shape for import-created batches is EXACTLY `['import_job_id' => $job->id, 'source' => 'unified-import']`. Lookup: `OpeningBalanceBatch::where('type', $sideType)->where('import_file_reference->import_job_id', $job->id)->first()`. Migration (unique expression index over the jsonb path):

```php
DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS obb_import_ref_type_unique ON opening_balance_batches (((import_file_reference->>'import_job_id')), type) WHERE import_file_reference IS NOT NULL");
```

(down: `DROP INDEX IF EXISTS obb_import_ref_type_unique`). Use the anonymous-class migration shape; `use Illuminate\Support\Facades\DB;`.

Behavior of `run()` (implement exactly):
1. Collect imported rows (`is_imported=true`) whose data yields balance payloads via `PartiesRowMapper::toBalancePayloads($row->data, $defaultDate, $partnerCode)` where `$partnerCode = $row->data['code']` (guaranteed non-empty for balance rows — see code generation below) and `$defaultDate` = the company's current fiscal-year start: `CarbonImmutable::create(now()->year, $company->fiscal_year_start_month, 1)`, minus one year if that lands after today (`Company::$fiscal_year_start_month` exists; à-nouveaux are dated at fiscal-year start).
   **Partner code generation happens BEFORE the partner phase, never via model writes from Import:** in `ImportService::importRow` for Parties, when any balance column is non-empty and `code` is empty, set `code = 'IMP-'.substr($job->id,0,8).'-'.$row->row_number` in the mapped partner data AND persist it back into `row->data['code']` (single `$row->update(['data' => …])`) so the balances phase and `ArApOpeningService.validateRow` (which resolves partners by `partner_code`) both see it. Do NOT touch the `Partner` model directly from the Import module.
2. If no balance rows → return.
3. If opening balances are locked for the company (`OpeningBalanceBatchService` lock state — use the same check the Advanced wizard uses; grep `isLocked`/`lockBatch` usage in `OpeningBalanceBatchController` and reuse) → warning `balance_not_posted` ("opening balances are locked") on every balance row → return. NOTE: the spec wants locked detected at *validation* time too — add the same check in `applyPartiesExtraValidation` (Task 7 hook) flipping rows with balance columns to invalid with error `Opening balances are locked for this company.` The finalize-time check here is the race guard.
4. Per side (AR from `ar` payloads, AP from `ap`): skip side if empty. Retry lookup: `OpeningBalanceBatch::where('type', $sideType)->where('import_file_reference->import_job_id', $job->id)->first()` — if found and status Validated/locked → rows get `data._results[side]='ok'` (already posted; idempotent no-op); if found in Draft → `clearImportRows` + re-add; if a **different** unlocked batch of that type exists (`hasUnlockedBatch` true but not ours) → every side row gets warning `balance_not_posted` ("an unlocked {AR|AP} opening batch already exists — post or delete it, then re-run") and `_results[side]='error: batch_conflict'`; skip side.
5. Else `createBatch($company, $sideType, $fiscalYearStart, 'IMPORT-'.substr($job->id,0,8).'-'.$side, $job->user_id, 'unified-import')` + `updateFileReference($batch, ['import_job_id' => $job->id, 'source' => 'unified-import'])` + `addImportRows($batch, $payloadsWithCurrency)` (inject `'currency' => $company->currency` per row) + `validateBatch` + `postBatch`.
6. Row-level failures from validateBatch (invalid rows) map back by row order → warning `balance_not_posted` with the row's validation error; valid rows post. If `postBatch` throws → catch, warning `balance_not_posted` (exception message) on all side rows, `_results[side]='error: post_failed'`.
7. Write `_results` into each row's `data` jsonb (merge, single update per row).

- [ ] **Step 1: Failing feature test** — end-to-end through `ImportService` (sync path): CSV-equivalent rows added via `addRowsBatch`, `validateJob`, `executeImport`, then assert:
  - customer +100 → AR batch posted, 1 historical Document (`invoice`, total `100.000`), partner created;
  - customer −50 → AR `credit_note` document;
  - supplier +80 → AP batch posted;
  - partner-only row (no balance) → no batch row, no warnings;
  - batch `import_file_reference['import_job_id'] === $job->id` and `import_file_reference['source'] === 'unified-import'` (jsonb array cast), names `IMPORT-…-AR/AP`;
  - warnings empty; `_results.ar_balance === 'ok'` on the +100 row.
  Second test: pre-create an unlocked AR batch (Advanced-wizard style) → import runs, partners import fine, balance rows carry `balance_not_posted` warning, no new AR batch.
  Third test: re-run `finalizeImport` on the same job (retry) → no duplicate documents (count unchanged).
- [ ] **Step 2: Run → FAIL. Step 3: Implement (service + migration + wiring).** `executeImport` calls `$this->finalizeImport($job, $companyId ?? $this->companyContext->requireCompanyId())` right before the final status update; `ProcessImportJob::processImport` calls `$importService->finalizeImport($job, $this->companyId)` after its loop (inside tenant context). `PartiesBalancesPhase` constructor: `ArApOpeningService`, `OpeningBalanceBatchService`, `ImportService`?? — NO circular dependency: inject `PartiesRowMapper` + the two accounting/document services; pass warnings back via a callable or return list consumed by ImportService. Simplest non-circular shape: `run()` returns `list<array{row_id: string, code: string, detail: string, results: array<string,string>}>` and `ImportService::finalizeImport` applies them via `addRowWarning` + data merge. Lock that shape in.
- [ ] **Step 4: Run → PASS:** `./vendor/bin/phpunit tests/Feature/Import/PartiesImportBalancesTest.php` and re-run Tasks 1-3 test files by path.
- [ ] **Step 5: Async parity smoke** — extend `tests/Feature/Import/ProcessImportJobStatusTest.php` with one Parties job through the queued path (`ProcessImportJob` synchronously via `handle()`) asserting a posted AR batch exists. Run it.
- [ ] **Step 6: Guards; TASKLOG.**

### Task 9: Gate import routes with `can:imports.manage`

**Files:**
- Modify: `app/Modules/Import/Providers/ImportServiceProvider.php`
- Test: `tests/Feature/Import/ImportPermissionGateTest.php` (create)

**Interfaces:**
- Produces: every `/imports*` and `/migration-wizard*` route additionally runs `can:imports.manage` (permission already seeded at `RolesAndPermissionsSeeder` L389; admin role has all permissions; do NOT grant manager).

- [ ] **Step 1: Failing test** — user WITHOUT `imports.manage` → `GET /api/v1/imports` 403 and `POST /api/v1/imports` 403 and `GET /api/v1/migration-wizard/order` 403; user WITH it (admin) → 200. Routes live under `prefix('api/v1')` — never assert bare `/imports`. Copy the role-assignment pattern from an existing permission test (grep `can:` usages' tests, e.g. Contact module tests).
- [ ] **Step 2: Run → FAIL (currently 200 for both). Step 3: Implement** — append `'can:imports.manage'` to the middleware array in the provider's route group.
- [ ] **Step 4: Run → PASS. Re-run the import feature tests as an EXPLICIT FILE LIST** (never a bare directory): `./vendor/bin/phpunit tests/Feature/Import/ImportTypesTest.php tests/Feature/Import/MigrationWizardTest.php tests/Feature/Import/ImportInfrastructureTest.php tests/Feature/Import/ProcessImportJobStatusTest.php tests/Feature/Import/ColumnMappingTest.php tests/Feature/Import/ImportPreviewTest.php tests/Feature/Import/ImportRowWarningsTest.php tests/Feature/Import/ImportJobOptionsTest.php tests/Feature/Import/PartiesImportTypeTest.php tests/Feature/Import/PartiesImportBalancesTest.php` (all import feature tests must now authenticate with a permitted user — fix any test setup lacking the permission by using the admin role; this is expected fallout, fix tests not the gate).
- [ ] **Step 5: Guards; TASKLOG.**

### Task 10: FE — parties card, types, Advanced section, parapharmacy hiding, i18n

**Files:**
- Modify: `apps/web/src/features/import/types.ts`, `apps/web/src/features/import/pages/ImportWizardPage.tsx` (TARGET_COLUMNS), `apps/web/src/features/import/pages/ImportDashboardPage.tsx`, `apps/web/src/locales/en/import.json`, `apps/web/src/locales/fr/import.json`
- Test: `apps/web/src/features/import/__tests__/ImportDashboardPage.test.tsx` (create)

**Interfaces:**
- Produces: `ImportType` union gains `'parties'`. Dashboard: two primary cards (`parties` "Business partners", `products`) rendered prominently; legacy tiles (`partners`, `opening_balances`, `product_images`, `composite_items`, link to the accounting batch wizard if present) inside a collapsed `<details>`/accordion "Advanced imports" section that is **not rendered at all** when `useCompanyConfig().config?.vertical === 'parapharmacy'`. TARGET_COLUMNS for `parties` mirror Task 5 columns (`name*`, `type*`, code, email, phone, tax_id, address_line1, address_city, address_postal_code, address_country, opening_balance, opening_balance_customer, opening_balance_supplier, balance_date, reference).

- [ ] **Step 1: Failing Vitest** — render `ImportDashboardPage` with mocked `useCompanyConfig` (see `apps/web/src/contexts/__tests__/CompanyConfigContext.test.tsx` fixtures): (a) generic vertical → 'Advanced imports' section present, parties card present; (b) parapharmacy → advanced section absent, parties card still present. Component tests may `vi.mock` hooks/providers.
- [ ] **Step 2: Run → FAIL:** `pnpm vitest run src/features/import/__tests__/ImportDashboardPage.test.tsx` (from `apps/web`). **Step 3: Implement** — dashboard restructure + i18n keys (add to BOTH en and fr `import.json`: `dashboard.primaryTitle`, `dashboard.advancedTitle`, `dashboard.advancedHint`, `types.parties.title` "Business partners" / fr "Partenaires commerciaux", `types.parties.description` incl. "with opening balances" / fr "avec soldes d'ouverture", `dashboard.orderHint` "Import business partners first, then products." + fr). New i18n keys ONLY via `t('import:…')`.
- [ ] **Step 4: Run → PASS. Step 5:** `pnpm typecheck && pnpm lint src/features/import --no-warn-ignored && pnpm vitest run src/features/import` → clean. TASKLOG.

### Task 11: Products enum extension (new columns + type default)

**Files:**
- Modify: `app/Modules/Import/Domain/Enums/ImportType.php` (Products arms), `app/Modules/Import/Services/ImportService.php` (`importProduct` passes type default)
- Test: extend `tests/Feature/Import/ImportTypesTest.php`

**Interfaces:**
- Produces: Products optional columns += `sale_price_incl_tax`, `sale_price_excl_tax`, `margin`, `quantity`, `location_code`, `brand`; required columns become `['name']` **for now** (phase 2 keeps name required; `sku` and `type` leave required → optional). Validation rules:

```php
                'sku' => ['nullable', 'string', 'max:100'],
                'type' => ['nullable', 'in:part,service,consumable'],
                'sale_price_incl_tax' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'sale_price_excl_tax' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'margin' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
                'quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
                'location_code' => ['nullable', 'string', 'max:100'],
                'brand' => ['nullable', 'string', 'max:255'],
```

(keep existing name/sale_price/purchase_price/tax_rate/unit/is_active rules; name stays `required`).

- [ ] Steps: failing test (template contains new headers; a row with only `name` valid; `margin` `'12.5'` valid, `'12.555'` invalid) → run FAIL → implement → run PASS → guards → TASKLOG.

### Task 12: `TaxDefaultResolverInterface` + `ProductPriceResolver`

**Files:**
- Create: `app/Shared/Contracts/TaxDefaultResolverInterface.php`, `app/Modules/Import/Services/ProductPriceResolver.php`
- Modify: the Taxation module service provider (grep `app/Modules/Taxation/Providers/` for the provider class) — bind interface to `TaxResolutionService`
- Test: `tests/Unit/Import/ProductPriceResolverTest.php` (create; DB-free — fake the contract)

**Interfaces:**

```php
namespace App\Shared\Contracts;

use App\Modules\Company\Domain\Company;

interface TaxDefaultResolverInterface
{
    /** Default tax rate (percent, numeric-string) for a new product in this company/category. */
    public function getDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): string;
}
```

(`TaxResolutionService` already has exactly this method — add `implements TaxDefaultResolverInterface`.)

```php
namespace App\Modules\Import\Services;

final class ProductPriceResolver
{
    /**
     * @param array<string, mixed> $row  normalized row data
     * @param 'ttc'|'ht'|'margin' $authority
     * @param string $taxRate percent numeric-string (already resolved by caller)
     * @return array{sale_price: ?string, warnings: list<array{code: string, detail: string}>}
     */
    public function resolve(array $row, string $authority, string $taxRate): array;
}
```

Rules (exact, bcmath at scale 4 intermediates, final `CurrencyScale::bcformatStrict($ttc, 3)`):
- Candidates: `ttc = row['sale_price_incl_tax'] ?: row['sale_price']` (legacy `sale_price` column stays TTC-equivalent), `ht = row['sale_price_excl_tax']`, `margin = row['margin']` (needs `purchase_price`).
- Derivations: `ttcFromHt = ht × (1 + taxRate/100)`; `htFromMargin = purchase_price × (1 + margin/100)` then `ttcFromMargin = htFromMargin × (1 + taxRate/100)`.
- Authority present → its TTC wins. Authority absent from the row → fall back in order ttc → ht → margin (first present).
- Every OTHER present candidate: derive its TTC; if `|derived − chosen| > 0.001` (one unit at scale 3, compare with `bccomp` at scale 3) → warning `price_conflict` `"{field}: provided implies {derived}, kept {chosen}"`.
- `margin` present but `purchase_price` empty → warning `margin_without_cost`, margin candidate skipped.
- No candidate present → `sale_price: null`, no warning.

- [ ] **Step 1: Failing unit test matrix** — minimum cases: authority ttc with all three consistent (no warnings); authority ttc with conflicting ht (warning, ttc kept); authority ht (ttc derived = ht×1.19 at taxRate '19.00', formatted 3dp); authority margin (cost '10.000', margin '30' → ht 13, ttc 15.470 at 19%); margin without cost → warning + fallback to next candidate; only legacy `sale_price` → used as ttc; nothing → null. Use exact expected strings (`'15.470'`).
- [ ] **Step 2: Run → FAIL. Step 3: Implement + provider binding.** Caller-side tax resolution comes in Task 14 — resolver itself takes `$taxRate` as input (row `tax_rate` or default, decided by caller).
- [ ] **Step 4: Run → PASS:** `./vendor/bin/phpunit tests/Unit/Import/ProductPriceResolverTest.php`. **Guards; TASKLOG.**

### Task 13: Product upsert contract — file-sku → barcode precedence, generated sku, brand, type default

**Files:**
- Modify: `app/Modules/Product/Application/Services/ProductService.php` (`upsert`)
- Test: `tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php` (create)

**Interfaces:**
- Produces: `upsert($tenantId, $companyId, $data)` semantics:
  1. `$fileSku = trim((string)($data['sku'] ?? '')) ?: null`.
  2. Match precedence (ALL deterministic — re-import must be idempotent, spec requirement): by `sku=$fileSku` when provided; ELSE by `barcode` when non-empty (company-scoped); ELSE by the **name-derived sku** `$nameSku = strtoupper(substr(\Illuminate\Support\Str::slug((string) $data['name'], '-'), 0, 100))`.
  3. No match → create with `sku = $fileSku ?? ($barcode ?: $nameSku)`. NO random/ULID generation anywhere — a name-only row re-imported matches its own derived sku and updates instead of duplicating.
  4. `type` default: `$data['type'] ?? 'part'` (keep `ProductType::from`).
  5. `brand`: when `$data['brand']` non-empty → resolve via **`BrandResolutionService`** (the service `EnrichmentReviewService::accept()` delegates to at ~L163-177 — inject it, do NOT hand-roll `Brand::firstOrCreate`; the raw firstOrCreate in `CatalogEnrichmentService` is the pattern to avoid duplicating) → set `brand_id`.
  6. Everything else unchanged (tax fallback, category, is_active parsing).

- [ ] **Step 1: Failing tests** — (a) existing product barcode X sku Y; import row barcode X, blank sku, new name → SAME product updated, sku stays Y (the round-2 review's duplicate case); (b) blank sku + barcode → created with sku == barcode; (c) blank sku + no barcode, name "Crème Solaire 50" → created with sku `CREME-SOLAIRE-50`, and **upserting the same data again updates the same product (count stays 1 — idempotency)**; (d) missing type → `part`; (e) brand "Nivea" twice → one Brand row, product.brand_id set. Real DB assertions.
- [ ] **Step 2: Run → FAIL. Step 3: Implement. Step 4: Run → PASS.** Regression by path: `./vendor/bin/phpunit tests/Feature/Product/CreateProductWithOpeningTest.php tests/Feature/Import/ImportTypesTest.php`.
- [ ] **Step 5: Guards; TASKLOG.**

### Task 14: Products execution pipeline — price wiring + opening stock phase

**Files:**
- Create: `app/Modules/Import/Services/ProductOpeningStockPhase.php`
- Modify: `app/Modules/Import/Services/ImportService.php` (`importProduct` price resolution; `finalizeImport` products arm), `app/Modules/Import/Providers/ImportServiceProvider.php` (constructor args — see Task 7 warning)
- Test: `tests/Feature/Import/ProductsImportPipelineTest.php` (create)

**Interfaces:**
- Consumes: `ProductPriceResolver` (12), `TaxDefaultResolverInterface` (12), `OpeningBalancePostingService::post(OpeningBalancePosting)` + DTOs `OpeningBalancePosting`/`OpeningBalanceLine::make` (namespace `App\Modules\Inventory\Application\DTOs`), `LocationServiceInterface::findIdByCode`, `ImportService::addRowWarning`.
- Produces: during `importProduct`, row price columns collapse to canonical `sale_price` before `ProductServiceInterface::upsert` (authority from `$job->options['price_authority'] ?? 'ttc'`; tax rate = row `tax_rate` ?: `TaxDefaultResolverInterface` default; when default used AND ht/margin authority applied → warning `tax_unresolved` is NOT correct — that warning is only for *no resolvable rate*; since the default resolver always returns a rate, record provenance `data._results.tax_source = 'default'` instead). Price warnings buffer into the row after upsert. `ProductOpeningStockPhase::run(ImportJob, string $companyId): list<warning-shape>` (same return contract as Task 8): for each imported row with `quantity > 0`:
  - product `type == 'service'` → warning `qty_without_cost`? NO — warning code `quantity_ignored_service` "quantity ignored for service products", skip;
  - `purchase_price` empty/zero → warning `qty_without_cost`, skip;
  - product `requires_batch_tracking` → warning `quantity_batch_tracked` "batch-tracked products: use stock flows", skip;
  - location: `row['location_code'] ?: $job->options['location_code'] ?? null` → `LocationServiceInterface::findIdByCode`; unresolvable → warning `location_unresolved`, skip;
  - else resolve the company currency scale exactly like `ProductController` does at ~L489-510 (inject `CurrencyScaleResolverInterface`, `$scale = $scaleResolver->getScale($company->currency)`) and `OpeningBalancePostingService::post(new OpeningBalancePosting(tenantId, companyId, $job->user_id, now(), true, 'import', $row->id, 'IMPORT-'.substr($job->id,0,8), null, [OpeningBalanceLine::make($productId, null, $locationId, $qty4, $costAtScale, $scale)]))` — do NOT hardcode scale 3 here; the DTO validates against company scale;
  - `OpeningAlreadyExistsException` → warning `opening_exists`, skip; any other `\Throwable` from `post()` → warning `opening_failed` with the exception message, skip. **There is NO opening-period lock on the product-level path today** (`OpeningBalancePostingService` has no lock check; `ProductController` posts without one) — the spec's `opening_locked` warning is reserved for a future lock mechanism and is NOT implemented in this plan (spec deviation recorded in Appendix B addendum). `_results.opening_stock = 'ok'|'skipped: <code>'`.

- [ ] **Step 1: Failing feature test** — one import with 6 rows exercising: happy path (product + StockMovement Opening + StockLevel qty + WAC seeded from purchase_price + `_results.opening_stock='ok'`); qty-no-cost warning; service-type warning; duplicate opening (pre-post one) → `opening_exists`; ht-authority price (`options.price_authority='ht'`, assert stored `sale_price` TTC exact string); margin+conflict warning row. (No locked-period test — no lock exists on this path, see step 3.) Assert `warning_rows` on formatJob output too (reuse HTTP execute endpoint for one of these to cover the sync API path end-to-end).
- [ ] **Step 2: Run → FAIL. Step 3: Implement. Step 4: Run → PASS** + re-run Task 8 file (finalizeImport now branches two types). **Guards; TASKLOG.**

### Task 15: Result workbook (service + endpoint + FE buttons)

**Files:**
- Create: `app/Modules/Import/Services/ResultWorkbookService.php`
- Modify: `app/Modules/Import/Presentation/Controllers/ImportController.php` (+`downloadResultWorkbook`), `app/Modules/Import/Providers/ImportServiceProvider.php` (route `GET /imports/{id}/result-workbook`), `apps/web/src/features/import/api/importApi.ts` (+`downloadResultWorkbookUrl(jobId)`), `apps/web/src/features/import/pages/{ImportWizardPage,ImportHistoryPage}.tsx` (download buttons)
- Test: `tests/Feature/Import/ResultWorkbookTest.php` (create)

**Interfaces:**
- Produces: `ResultWorkbookService::generate(ImportJob $job): string` (returns absolute temp file path of an `.xlsx` built with `PhpOffice\PhpSpreadsheet`); sheets: `Imported` and `Rejected` (rows `is_valid=false OR import_error NOT NULL` + reason columns), each sheet = original data columns + `warnings` column (join `code: detail` with `; `). **Phase-scoped deviation (deliberate):** the spec's three-sheet split (enriched / pending-enrichment / rejected) collapses to two sheets in phase 2 because enrichment states don't exist until phase 3 — phase 3 owns splitting `Imported` into sheets 1+2. Reviewers should NOT expect three sheets here. Controller streams it (`response()->download($path, "import-{$job->id}-result.xlsx")->deleteFileAfterSend()`), 404 when job not found (tenant-scoped), route inside the gated group.

- [ ] Steps: failing test (generate for a job with 1 imported+warned row and 1 rejected row → open the file back with PhpSpreadsheet in the test, assert sheet names, cell values incl. warning text; HTTP test asserts 200 + content-type + `can:imports.manage` 403 for unpermitted) → FAIL → implement → PASS → FE buttons (Done step + History row action, i18n keys `results.downloadWorkbook` en "Download result workbook" / fr "Télécharger le rapport d'import") → `pnpm vitest run src/features/import` + typecheck → guards → TASKLOG.

### Task 16: FE products options step + PATCH wiring + final sweep

**Files:**
- Modify: `apps/web/src/features/import/pages/ImportWizardPage.tsx` (WizardStep union + STEPS + options UI), `apps/web/src/features/import/api/importApi.ts` (+`updateOptions(jobId, options)`), `apps/web/src/features/import/types.ts` (+`ImportJobOptions`), locales en/fr
- Test: `apps/web/src/features/import/__tests__/importApi.options.test.ts` (create)

**Interfaces:**
- Produces: wizard step flow `upload → mapping → options → validation → execute → complete` — the `options` step renders ONLY for `products` when ≥2 of {sale_price_incl_tax, sale_price_excl_tax, margin} are mapped (else auto-skips); radio group for price authority (default `ttc`), persisted via `importApi.updateOptions(jobId, {price_authority})` → `apiPatch`-equivalent (check the axios helper set: use `api.patch` and return `response.data` if no `apiPatch` exists). TARGET_COLUMNS for products += the Task 11 columns (`sku`/`type` no longer marked required; add sale_price_incl_tax, sale_price_excl_tax, margin, quantity, location_code, brand).
- i18n keys: `wizard.steps.options` (en "Options" / fr "Options"), `options.priceAuthorityTitle` (en "Which price is authoritative?" / fr "Quel prix fait foi ?"), `options.priceAuthority.ttc` (en "Price incl. tax (TTC)" / fr "Prix TTC"), `options.priceAuthority.ht` (en "Price excl. tax (HT)" / fr "Prix HT"), `options.priceAuthority.margin` (en "Margin on cost" / fr "Marge sur coût"), `options.priceAuthorityHint` (en "When several price columns disagree, this one wins; others produce warnings." / fr "En cas de conflit entre colonnes de prix, celle-ci l'emporte ; les autres génèrent des avertissements.").

- [ ] Steps: failing Vitest for `updateOptions` (axios-level, no fake payload beyond echo) and a wizard step-visibility test (mapping with 2 price columns → options step appears; 1 → skipped) → FAIL → implement → PASS.
- [ ] **Final sweep (orchestrator-assisted):**
  1. `php artisan typescript:transform` (from `apps/api`) → `git diff packages/shared/types/` — commit drift if any.
  2. From `apps/web`: `pnpm typecheck && pnpm lint src/features/import --no-warn-ignored && pnpm vitest run src/features/import` → all green.
  3. From `apps/api`: re-run ALL plan test files by explicit path list (Tasks 1-15) → green; `./vendor/bin/phpstan analyse app/Modules/Import app/Modules/Document/Application/Services/ArApOpeningService.php app/Modules/Partner/Application/Services/PartnerService.php app/Modules/Product/Application/Services/ProductService.php app/Shared/Contracts/TaxDefaultResolverInterface.php --memory-limit=1G` → 0 errors; `./vendor/bin/pint` on changed paths.
  4. Live E2E (orchestrator): boot API + web per `reference_local_db_per_tenant_demo_launch` recipe; Playwright: upload a semicolon-delimited FR-locale parties CSV (incl. one negative customer balance `-50,000`), run wizard end-to-end, verify partner + AR credit-note document in UI/DB; products file with TTC/HT conflict → warning visible on Done step; download result workbook.
  5. TASKLOG completeness check: every task logged with red+green evidence.

---

## Self-review notes (spec → plan coverage)

- Spec §Prereq 0 → Tasks 1-2. §Contracts table → Tasks 6 (Partner), 12 (Tax contract), 13 (Product), 8/14 (opening services consumed as-is via their public classes — Document/Accounting/Inventory service classes are module-public services per rule 6). §1 Parties → Tasks 5-8 (+9 permission, 10 FE). §2 Products → Tasks 11-14 (+15 workbook, 16 FE options). §Job options & warnings → Tasks 3-4. §3 Dashboard/permissions/parapharmacy → Tasks 9-10. §Data/schema → migrations in 3, 8 (products placeholder column `is_enrichment_placeholder` is Phase 3 — NOT in this plan by design). §Error handling table → warning codes in 7, 8, 12, 14 (note: `quantity_ignored_service`/`quantity_batch_tracked`/`location_unresolved` are finer-grained than the spec's single list — spec names are kept where they exist). §Testing → per-task tests + Task 16 sweep. §Build order → task order. Landing → orchestrator merges `feat/unified-imports` → local `post-demo`, pushes `origin/post-demo`.
- Deliberately OUT (Phase 3 plan): enrichment prefill, placeholders + `is_enrichment_placeholder`, `barcode OR name` validation relaxation (name stays required — Task 11), deterministic submit idempotency key, workbook sheet 1/2 split by enrichment state, REALIGNMENT-LOG entry.
- PATCH options + wizard order → Task 4 + 16. Signed regex only on parties balance columns (products prices stay unsigned min:0).

## Plan-review round 1 dispositions (2026-07-03, `docs/superpowers/audits/2026-07-03-unified-imports-plan-review.md`, verdict NOT-READY → repaired)

- B1 jsonb `import_file_reference` → Task 8 rewritten: exact array shape, `->where('import_file_reference->import_job_id', …)` lookup, expression unique index.
- B2 manual provider wiring → ImportServiceProvider added to Tasks 7/8/14 file lists with explicit warning.
- B3 Task 1 fixture → copy `OpeningBalanceBatchTest` setUp verbatim; `$this->user->id`.
- B4 non-deterministic ULID sku → name-derived deterministic sku (`STR::slug` uppercased, 100 cap) participates as LAST match key; idempotent re-import test added.
- M1 boundary → architecture note: module-public application services per CLAUDE.md rule 6; spec contracts table amended (see spec Appendix B addendum v4 note).
- M2 direct Partner writes → deleted; pre-partner-phase code generation is the only path.
- M3 balance_date default → company fiscal-year start from `fiscal_year_start_month` (minus a year if in the future); spec amended.
- M4 phantom opening lock → no lock exists on the product-level path; `opening_failed` for unexpected throwables; `opening_locked` reserved for a future lock; spec deviation recorded.
- M5 hardcoded scale → company scale via `CurrencyScaleResolverInterface`, ProductController pattern.
- M6 MigrationWizardService touchpoints → six structures enumerated with FR aliases in Task 5.
- M7 `/api/v1` prefixes → Task 9 fixed. M8 directory run → explicit file list. M9 PATCH options required. M10 ar fallback = recorded deliberate exception.
- N1 BrandResolutionService named. N2 `jsonb_array_length` + null-clear convention. N3 two-sheet phase deviation stated in Task 15.
