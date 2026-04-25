# Document Line Designation Override — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users override the customer-facing designation and add a per-line additional description on all five document types (quotes, sales orders, invoices, credit notes, delivery notes), flowing both fields through PDF / print / e-invoicing while preserving `product_id` as the authoritative internal linkage.

**Architecture:**
Formalize the existing `document_lines.description` as the overridable designation (primary line text) and activate the existing `document_lines.notes` as the customer-facing additional description (secondary subtext). Add one nullable column `designation_default_snapshot` to drive a drift-free "overridden" indicator. Switch the PDF template from live `product.name` lookup to the stored `description`. Split the workshop converter's em-dash concatenation into two separate fields. Ship behind a feature flag.

**Tech Stack:**
- Backend: Laravel 12, PHP 8.2+ strict types, PHPUnit with `RefreshDatabase`, PHPStan level 8, Pint.
- Frontend: React 19, TypeScript strict, TanStack Query 5, Zustand 5, Vitest + React Testing Library, react-i18next.
- Database: PostgreSQL 16, schema-based multi-tenancy.
- Testing: TDD (red/green/refactor), real Eloquent models + seeded permissions, no mocked API responses.
- Supporting: `php artisan typescript:transform` for type generation, `./scripts/preflight.sh` as quality gate.

**Approved spec:** `docs/superpowers/specs/2026-04-24-document-line-designation-override-design.md`

---

## Prerequisites

### Branch setup

- [ ] **Step 0.1: Create a feature branch off `dev`**

```bash
git checkout dev
git pull --ff-only
git checkout -b feat/document-line-designation-override
```

- [ ] **Step 0.2: Confirm preflight passes on the clean branch before any changes**

```bash
./scripts/preflight.sh
```
Expected: all checks pass (PHPStan level 8, Pint, PHPUnit, TypeScript, ESLint). If anything fails on the baseline, fix or flag before starting.

---

## Phase 0 — Discovery

Four short, read-only investigations. Each task produces a short note appended to a scratch file `docs/sessions/designation-override-discovery.md` (ephemeral per rule #15). These notes are NOT committed on their own — they inform the implementation steps below and the scratch file is gitignored.

### Task D0: Locate the posted-line guard

**Files:**
- Read-only: `apps/api/app/Modules/Document/` (whole tree)
- Note to: `docs/sessions/designation-override-discovery.md`

- [ ] **Step D0.1: Grep for the posted / immutable lifecycle guard**

```bash
cd apps/api
rg -n "isPosted|is_posted|isFinalized|posted()|DocumentStatus::" app/Modules/Document/ | head -40
rg -n "cannot update|line.*locked|immutable|HashChained" app/Modules/Document/ | head -40
```

- [ ] **Step D0.2: Read the top-matching service / policy / observer and note**

Write to the scratch file:
- Exact class + method that rejects line updates on posted documents.
- Whether its predicate covers `description` and `notes` fields (or only `quantity` / `unit_price`).
- Example: `DocumentLine::boot() saving observer in app/Modules/Document/Domain/DocumentLine.php rejects any update when parent document status ∈ {Posted, Finalized}`.

- [ ] **Step D0.3: If the guard does NOT cover description / notes, mark Task T8 as required**

Otherwise, mark Task T8 as "confirm-only" (a regression test is enough).

### Task D1: PDF cache behavior

- [ ] **Step D1.1: Grep for PDF cache handling**

```bash
cd apps/api
rg -n "DocumentPdfService|PdfService|renderPdf" app/ | head
rg -n "Cache::|cache\(" app/Modules/Document/ | head
rg -n "pdf" app/Modules/Document/ | rg -i "cache|store|disk" | head
```

- [ ] **Step D1.2: Determine and note**

Write to the scratch file:
- Is there a cache layer (Redis / filesystem / eloquent cache) around rendered draft PDFs? YES / NO.
- If YES: exact class + method that would need invalidation on line update.
- If NO: Task T12 becomes a no-op (skip).

### Task D2: Domain events on line updates

- [ ] **Step D2.1: Grep for existing line events**

```bash
cd apps/api
find app/Modules/Document -path '*/Events/*' -name '*.php' | sort
rg -n "event\(" app/Modules/Document/ | rg -i "line" | head
```

- [ ] **Step D2.2: Determine and note**

Write to the scratch file:
- Do `DocumentLineCreated` / `DocumentLineUpdated` (or equivalents) exist and fire on the write path? YES / NO.
- If YES: their FQCN and payload shape.
- If NO: plan to add `DocumentLineUpdatedV1` (per rule #8 versioning) — Task T7 captures this.

### Task D3: E-invoicing builders audit

- [ ] **Step D3.1: Grep for e-invoicing builders**

```bash
cd apps/api
rg -ni "el.?fatura|factur.?x|ubl|peppol|e.?invoic" app/ | head
```

- [ ] **Step D3.2: Determine and note**

Write to the scratch file:
- Do any e-invoicing payload builders currently exist? Expected answer: NO (per spec §7.5).
- If YES (unexpected): add Task T11b — update those builders to use `description` for item name.

---

## Phase 1 — Feature Flag + Schema

### Task T1: Add the feature flag

**Files:**
- Modify: `apps/api/config/features.php` (or the project's canonical feature-flag config — grep for it)
- Test: none yet (flag read at runtime; its consumers get tested)

- [ ] **Step T1.1: Locate the feature-flag registry**

```bash
cd apps/api
rg -n "config\('features\." app/ | head
find config -name "features*.php" -o -name "feature_flags*.php"
```

- [ ] **Step T1.2: Add the flag with default off**

Edit the registry file found in T1.1 (example shown for `config/features.php` — adapt to actual registry):

```php
'documents' => [
    // existing flags...
    'line_designation_override' => [
        'enabled' => env('FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE', false),
        'description' => 'Per-line designation override + additional description on documents.',
    ],
],
```

- [ ] **Step T1.3: Confirm flag reads correctly**

Run a quick tinker check:

```bash
cd apps/api
php artisan tinker --execute="dump(config('features.documents.line_designation_override.enabled'));"
```
Expected: `false`

- [ ] **Step T1.4: Commit**

```bash
git add apps/api/config/
git commit -m "feat(documents): register line_designation_override feature flag"
```

### Task T2: Migration — add `designation_default_snapshot`

**Files:**
- Create: `apps/api/database/migrations/YYYY_MM_DD_HHMMSS_add_designation_default_snapshot_to_document_lines_table.php` (generate via artisan)
- Test: `apps/api/tests/Unit/Modules/Document/DesignationSnapshotMigrationTest.php`

- [ ] **Step T2.1: Write the failing test**

Create `apps/api/tests/Unit/Modules/Document/DesignationSnapshotMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DesignationSnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_lines_has_designation_default_snapshot_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('document_lines', 'designation_default_snapshot'),
            'Expected document_lines.designation_default_snapshot column to exist.'
        );
    }

    public function test_designation_default_snapshot_is_nullable(): void
    {
        $columnType = Schema::getColumnType('document_lines', 'designation_default_snapshot');
        $this->assertContains($columnType, ['string', 'text', 'varchar'], 'Expected string/text type.');

        // Insert with nullable value
        \DB::table('document_lines')->insert([
            'id' => 1,
            'document_id' => 1,
            'line_number' => 1,
            'description' => 'test',
            'quantity' => 1,
            'unit_price' => 0,
            'tax_rate' => 0,
            'line_total' => 0,
            'designation_default_snapshot' => null,
        ]);
        $this->assertSame(1, \DB::table('document_lines')->count());
    }
}
```

- [ ] **Step T2.2: Run test to verify it fails**

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Modules/Document/DesignationSnapshotMigrationTest.php
```
Expected: FAIL — column does not exist.

- [ ] **Step T2.3: Generate the migration**

```bash
cd apps/api
php artisan make:migration add_designation_default_snapshot_to_document_lines_table --table=document_lines
```

Edit the generated file:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->string('designation_default_snapshot', 500)
                ->nullable()
                ->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn('designation_default_snapshot');
        });
    }
};
```

- [ ] **Step T2.4: Run test to verify it passes**

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Modules/Document/DesignationSnapshotMigrationTest.php
```
Expected: PASS.

- [ ] **Step T2.5: Commit**

```bash
git add apps/api/database/migrations/ apps/api/tests/Unit/Modules/Document/DesignationSnapshotMigrationTest.php
git commit -m "feat(documents): add designation_default_snapshot column to document_lines"
```

---

## Phase 2 — Backend Data Layer

### Task T3: Update `DocumentLine` model

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/DocumentLine.php`
- Test: `apps/api/tests/Unit/Modules/Document/DocumentLineModelTest.php` (create if not exists; otherwise extend)

- [ ] **Step T3.1: Write the failing test**

Create / extend `DocumentLineModelTest.php`:

```php
public function test_designation_default_snapshot_is_fillable_and_cast_to_string(): void
{
    $line = new \App\Modules\Document\Domain\DocumentLine();
    $this->assertContains('designation_default_snapshot', $line->getFillable());

    $line->fill(['designation_default_snapshot' => 'Original Name']);
    $this->assertSame('Original Name', $line->designation_default_snapshot);
}
```

- [ ] **Step T3.2: Run to verify it fails**

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Modules/Document/DocumentLineModelTest.php --filter designation_default_snapshot
```
Expected: FAIL.

- [ ] **Step T3.3: Add to `$fillable`**

Edit `apps/api/app/Modules/Document/Domain/DocumentLine.php` — add `'designation_default_snapshot'` to the `$fillable` array.

- [ ] **Step T3.4: Run to verify PASS**

Same command as T3.2. Expected: PASS.

- [ ] **Step T3.5: Commit**

```bash
git add apps/api/app/Modules/Document/Domain/DocumentLine.php apps/api/tests/Unit/Modules/Document/DocumentLineModelTest.php
git commit -m "feat(documents): expose designation_default_snapshot on DocumentLine model"
```

### Task T4: Update the `DocumentLine` factory

**Files:**
- Modify: `apps/api/database/factories/DocumentLineFactory.php` (path may differ — locate with `find apps/api -name "DocumentLineFactory.php"`)

- [ ] **Step T4.1: Locate the factory**

```bash
cd apps/api
find . -name "DocumentLineFactory.php"
```

- [ ] **Step T4.2: Update factory default**

In the factory's `definition()` method, add:

```php
'designation_default_snapshot' => $this->faker->boolean(80)
    ? $this->faker->words(3, true)
    : null,
```

- [ ] **Step T4.3: Verify factory works**

```bash
cd apps/api
php artisan tinker --execute="dump(\App\Modules\Document\Domain\DocumentLine::factory()->make()->designation_default_snapshot);"
```
Expected: string or null (non-error output).

- [ ] **Step T4.4: Commit**

```bash
git add apps/api/database/factories/DocumentLineFactory.php
git commit -m "test(documents): add designation_default_snapshot to DocumentLine factory"
```

---

## Phase 3 — Request Validation + Persistence

### Task T5: Update create-line FormRequest validation

**Files:**
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/` — locate the Create/Update line request classes with `rg -l "description.*max" app/Modules/Document/`
- Test: `apps/api/tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php`

- [ ] **Step T5.1: Write failing validation tests**

Create `apps/api/tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateDocumentLineValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rejects_empty_description(): void
    {
        // Arrange: authenticate as a user with invoice edit permission; create a draft invoice.
        // (Use existing test helpers in this codebase — adapt the setup to match.)
        $user = $this->createUserWithPermission('invoices.update');
        $invoice = $this->createDraftInvoice($user);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->uuid}/lines", [
            'description' => '',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_rejects_whitespace_only_description(): void
    {
        $user = $this->createUserWithPermission('invoices.update');
        $invoice = $this->createDraftInvoice($user);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->uuid}/lines", [
            'description' => '   ',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_rejects_description_longer_than_500_chars(): void
    {
        $user = $this->createUserWithPermission('invoices.update');
        $invoice = $this->createDraftInvoice($user);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->uuid}/lines", [
            'description' => str_repeat('a', 501),
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_rejects_notes_longer_than_1000_chars(): void
    {
        $user = $this->createUserWithPermission('invoices.update');
        $invoice = $this->createDraftInvoice($user);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->uuid}/lines", [
            'description' => 'Valid',
            'notes' => str_repeat('a', 1001),
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['notes']);
    }

    public function test_ignores_client_supplied_designation_default_snapshot(): void
    {
        $user = $this->createUserWithPermission('invoices.update');
        $invoice = $this->createDraftInvoice($user);
        $product = $this->createProduct(['name' => 'Real Product Name']);

        $response = $this->actingAs($user)->postJson("/api/invoices/{$invoice->uuid}/lines", [
            'product_id' => $product->uuid,
            'description' => 'Overridden',
            'designation_default_snapshot' => 'Malicious Snapshot',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $response->assertStatus(201);
        $line = $invoice->fresh()->lines()->latest('id')->first();
        $this->assertSame('Real Product Name', $line->designation_default_snapshot);
    }
}
```

> **Note:** Helper methods `createUserWithPermission`, `createDraftInvoice`, `createProduct` — either reuse the existing test helpers in this codebase (grep `tests/` for similar patterns in nearby test classes) or define them at the top of the test file per the existing convention.

- [ ] **Step T5.2: Run — expect fails**

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php
```
Expected: all 5 tests fail.

- [ ] **Step T5.3: Update the FormRequest rules**

Locate the request class (e.g., `StoreDocumentLineRequest` or similar) via:

```bash
cd apps/api
rg -l "'description'.*required" app/Modules/Document/Presentation/
```

In the `rules()` method, set:

```php
return [
    // existing rules...
    'description' => ['required', 'string', 'min:1', 'max:500'],
    'notes' => ['nullable', 'string', 'max:1000'],
    // designation_default_snapshot is NOT in the rules list — it's never accepted from client input.
];
```

Also add a `prepareForValidation()` hook to trim whitespace:

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'description' => is_string($this->input('description'))
            ? trim($this->input('description'))
            : $this->input('description'),
        'notes' => is_string($this->input('notes'))
            ? trim($this->input('notes'))
            : $this->input('notes'),
    ]);
}
```

Repeat for the update-line request class (same rules for both fields).

- [ ] **Step T5.4: Run — expect first four pass**

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php --filter "test_rejects|test_notes"
```
Expected: PASS.

- [ ] **Step T5.5: Ensure snapshot-stripping (for the fifth test) happens in the service layer**

The fifth test asserts server-side capture, not validation. Task T6 implements that. For now, the fifth test remains red until T6.

- [ ] **Step T5.6: Commit**

```bash
git add apps/api/app/Modules/Document/Presentation/ apps/api/tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php
git commit -m "feat(documents): enforce description/notes length + non-empty validation on line create/update"
```

### Task T6: `DraftPersistenceService` — capture snapshot, accept notes

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php` (lines ~189–229 and ~353 per prior exploration — grep for `'description' => $productName` to find exact insertion points)
- Test: extend `CreateDocumentLineValidationTest.php` above + add `DraftPersistenceServiceTest.php`

- [ ] **Step T6.1: Write the failing unit test**

Create `apps/api/tests/Unit/Modules/Document/DraftPersistenceServiceTest.php` (skeleton — adapt constructor injection to the service's actual dependencies):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Document\Domain\Services\DraftPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DraftPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_line_with_snapshot_from_product_name(): void
    {
        $product = $this->createProduct(['name' => 'Oil Filter 2.0L']);
        $document = $this->createDraftInvoice();
        $service = $this->app->make(DraftPersistenceService::class);

        $line = $service->addLine($document, [
            'product_id' => $product->uuid,
            'quantity' => 1,
            'unit_price' => 10.00,
        ]);

        $this->assertSame('Oil Filter 2.0L', $line->description);
        $this->assertSame('Oil Filter 2.0L', $line->designation_default_snapshot);
    }

    public function test_creates_line_with_overridden_description_but_original_snapshot(): void
    {
        $product = $this->createProduct(['name' => 'Oil Filter 2.0L']);
        $document = $this->createDraftInvoice();
        $service = $this->app->make(DraftPersistenceService::class);

        $line = $service->addLine($document, [
            'product_id' => $product->uuid,
            'description' => 'Custom Oil Filter Name',
            'quantity' => 1,
            'unit_price' => 10.00,
        ]);

        $this->assertSame('Custom Oil Filter Name', $line->description);
        $this->assertSame('Oil Filter 2.0L', $line->designation_default_snapshot);
    }

    public function test_creates_line_for_service_captures_service_name_in_snapshot(): void
    {
        $svc = $this->createService(['name' => 'Brake Labor']);
        $document = $this->createDraftInvoice();
        $service = $this->app->make(DraftPersistenceService::class);

        $line = $service->addLine($document, [
            'service_id' => $svc->uuid,
            'quantity' => 1,
            'unit_price' => 60.00,
        ]);

        $this->assertSame('Brake Labor', $line->designation_default_snapshot);
    }

    public function test_notes_is_persisted_on_creation(): void
    {
        $product = $this->createProduct(['name' => 'Widget']);
        $document = $this->createDraftInvoice();
        $service = $this->app->make(DraftPersistenceService::class);

        $line = $service->addLine($document, [
            'product_id' => $product->uuid,
            'description' => 'Widget',
            'notes' => 'Installed by John on front axle',
            'quantity' => 1,
            'unit_price' => 5.00,
        ]);

        $this->assertSame('Installed by John on front axle', $line->notes);
    }
}
```

- [ ] **Step T6.2: Run — expect fails**

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Modules/Document/DraftPersistenceServiceTest.php
```
Expected: fails (snapshot not captured; notes may or may not be persisted already).

- [ ] **Step T6.3: Update `DraftPersistenceService`**

Find the product-branch around line ~206:

```php
// BEFORE
$productName = $product !== null ? $product->name : '';
// ...
'description' => $productName,
```

Change to:

```php
// AFTER
$productName = $product !== null ? (string) $product->name : '';
// ...
'description' => $lineData['description'] ?? $productName,
'designation_default_snapshot' => $productName !== '' ? mb_substr($productName, 0, 500) : null,
'notes' => isset($lineData['notes']) ? (string) $lineData['notes'] : null,
```

Repeat for the service-branch (around line ~353, where `$batchProductName` or service-name is used). Use `$service->name` instead of `$product->name`.

> **CRITICAL:** `designation_default_snapshot` is always captured from `product.name` / `service.name` server-side, never from the `$lineData` payload. This is what makes the fifth T5 test pass.

- [ ] **Step T6.4: Run — expect PASS for T6 tests and T5 fifth test**

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Modules/Document/DraftPersistenceServiceTest.php
./vendor/bin/phpunit tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php --filter snapshot
```
Expected: all PASS.

- [ ] **Step T6.5: Commit**

```bash
git add apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php apps/api/tests/Unit/Modules/Document/DraftPersistenceServiceTest.php
git commit -m "feat(documents): capture designation snapshot and persist notes on draft line creation"
```

### Task T7: Domain event coverage

**Files:**
- Depends on D2 findings.
- If event exists: verify its payload already includes `description` / `notes`.
- If event does not exist: add `DocumentLineUpdatedV1`.

- [ ] **Step T7.1: Based on D2 note, pick the branch**

**Branch A — event exists and covers the fields.** Write a regression test asserting payload includes `description`, `notes`, `designation_default_snapshot` when a line is created/updated. Commit.

**Branch B — no event exists.** Create `apps/api/app/Modules/Document/Domain/Events/DocumentLineUpdatedV1.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Modules\Document\Domain\DocumentLine;
use Illuminate\Foundation\Events\Dispatchable;

final class DocumentLineUpdatedV1
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentUuid,
        public readonly string $lineUuid,
        public readonly string $description,
        public readonly ?string $notes,
        public readonly ?string $designationDefaultSnapshot,
        public readonly ?string $productId,
        public readonly ?string $serviceId,
    ) {
    }

    public static function fromLine(DocumentLine $line): self
    {
        return new self(
            documentUuid: (string) $line->document->uuid,
            lineUuid: (string) $line->uuid,
            description: (string) $line->description,
            notes: $line->notes,
            designationDefaultSnapshot: $line->designation_default_snapshot,
            productId: $line->product_id,
            serviceId: $line->service_id,
        );
    }
}
```

Wire its dispatch inside `DraftPersistenceService::updateLine()` (or the canonical update method). Write a test asserting dispatch.

- [ ] **Step T7.2: Commit**

```bash
git add apps/api/app/Modules/Document/Domain/Events/ apps/api/tests/Unit/Modules/Document/
git commit -m "feat(documents): emit DocumentLineUpdatedV1 with designation + notes + snapshot"
```
(If Branch A, commit message: `test(documents): regression for line update event payload`)

### Task T8: Extend posted-line guard if needed

**Files:**
- Depends on D0 findings.

- [ ] **Step T8.1: Write the regression test**

Regardless of whether the guard needed extending, add:

```php
public function test_cannot_update_description_on_posted_line(): void
{
    $user = $this->createUserWithPermission('invoices.update');
    $invoice = $this->createPostedInvoice($user); // posted, hash-chained
    $line = $invoice->lines->first();

    $response = $this->actingAs($user)->patchJson(
        "/api/invoices/{$invoice->uuid}/lines/{$line->uuid}",
        ['description' => 'Mutated after post']
    );

    $response->assertStatus(403); // or 422 depending on the guard's convention
    $this->assertSame($line->description, $line->fresh()->description);
}

public function test_cannot_update_notes_on_posted_line(): void
{
    $user = $this->createUserWithPermission('invoices.update');
    $invoice = $this->createPostedInvoice($user);
    $line = $invoice->lines->first();

    $response = $this->actingAs($user)->patchJson(
        "/api/invoices/{$invoice->uuid}/lines/{$line->uuid}",
        ['notes' => 'Mutated after post']
    );

    $response->assertStatus(403);
    $this->assertNull($line->fresh()->notes === 'Mutated after post' ? 'leaked' : null);
}
```

- [ ] **Step T8.2: Run**

If the guard already covers these fields → PASS. If not → FAIL, then fix in T8.3.

- [ ] **Step T8.3: Extend the guard (if needed)**

At the location identified in D0, ensure the guard's field list includes `description` and `notes`. Typical shape for a `saving` observer:

```php
// Before:
$immutableFields = ['quantity', 'unit_price', 'tax_rate', ...];
// After:
$immutableFields = ['quantity', 'unit_price', 'tax_rate', 'description', 'notes', 'designation_default_snapshot', ...];
```

(Field names depend on the actual guard code — adapt.)

- [ ] **Step T8.4: Run — expect PASS**

- [ ] **Step T8.5: Commit**

```bash
git add apps/api/app/Modules/Document/ apps/api/tests/
git commit -m "feat(documents): lock designation/notes on posted lines (extend immutability guard)"
```

---

## Phase 4 — Converters

### Task T9: Workshop → document converter splits header/detail

**Files:**
- Modify: `apps/api/app/Modules/Document/Infrastructure/Adapters/DocumentGenerationAdapter.php` (or wherever the work-order → document-line mapping lives — locate with `rg -n "display_name.*—|display_name.*description" apps/api/app/`)
- Test: `apps/api/tests/Unit/Modules/Document/WorkOrderToDocumentConverterTest.php`

- [ ] **Step T9.1: Write the failing test**

```php
public function test_workorder_line_maps_to_separate_description_and_notes(): void
{
    $workOrder = $this->createWorkOrderWithLine([
        'display_name' => 'Brake pad replacement',
        'description' => '2.5h × 60/hr by Mechanic John',
    ]);

    $adapter = $this->app->make(\App\Modules\Document\Infrastructure\Adapters\DocumentGenerationAdapter::class);
    $invoice = $adapter->generateInvoiceFromWorkOrder($workOrder);

    $line = $invoice->lines->first();

    $this->assertSame('Brake pad replacement', $line->description);
    $this->assertSame('2.5h × 60/hr by Mechanic John', $line->notes);
    $this->assertSame('Brake pad replacement', $line->designation_default_snapshot);
    $this->assertStringNotContainsString(' — ', $line->description);
}
```

- [ ] **Step T9.2: Run — FAIL**

- [ ] **Step T9.3: Replace the concatenation**

In `DocumentGenerationAdapter.php`, find:

```php
'description' => $wol->display_name . ($wol->description !== null ? ' — '.$wol->description : ''),
```

Replace with:

```php
'description'                  => (string) $wol->display_name,
'notes'                        => $wol->description,
'designation_default_snapshot' => mb_substr((string) $wol->display_name, 0, 500),
```

- [ ] **Step T9.4: Run — PASS**

- [ ] **Step T9.5: Commit**

```bash
git add apps/api/app/Modules/Document/Infrastructure/Adapters/DocumentGenerationAdapter.php apps/api/tests/Unit/Modules/Document/WorkOrderToDocumentConverterTest.php
git commit -m "feat(documents): split workorder display_name and description into designation + notes"
```

### Task T10: Doc-to-doc converters carry snapshot + notes

**Files:**
- Modify: every converter class under `apps/api/app/Modules/Document/` — locate with `rg -l "description.*=>.*->description" apps/api/app/Modules/Document/`
  - `SalesOrderToInvoiceConverter`
  - `SalesOrderToDeliveryNoteConverter`
  - `QuoteToSalesOrderConverter`
  - `InvoiceToCreditNoteConverter`
  - `DeliveryNoteToInvoiceConverter` (if exists)
- Test: `apps/api/tests/Unit/Modules/Document/DocumentConversionFieldsCarryTest.php`

- [ ] **Step T10.1: Write the failing test — one test per converter**

```php
public function test_quote_to_sales_order_carries_designation_notes_and_snapshot(): void
{
    $quote = $this->createDraftQuoteWithLine([
        'description' => 'Overridden name',
        'notes' => 'Detail line',
        'designation_default_snapshot' => 'Original name',
    ]);

    $converter = $this->app->make(\App\Modules\Document\...\QuoteToSalesOrderConverter::class);
    $so = $converter->convert($quote);

    $line = $so->lines->first();
    $this->assertSame('Overridden name', $line->description);
    $this->assertSame('Detail line', $line->notes);
    $this->assertSame('Original name', $line->designation_default_snapshot);
}

// Repeat for SalesOrderToInvoice, SalesOrderToDeliveryNote, InvoiceToCreditNote.
```

- [ ] **Step T10.2: Run — expect fails**

- [ ] **Step T10.3: Update each converter's per-line mapping**

For each converter file, in its line-copy block, ensure the new fields are copied:

```php
// existing
'description' => $line->description,
// add
'notes' => $line->notes,
'designation_default_snapshot' => $line->designation_default_snapshot,
```

- [ ] **Step T10.4: Run — PASS**

- [ ] **Step T10.5: Commit**

```bash
git add apps/api/app/Modules/Document/ apps/api/tests/Unit/Modules/Document/DocumentConversionFieldsCarryTest.php
git commit -m "feat(documents): carry notes + designation snapshot through doc-to-doc conversions"
```

---

## Phase 5 — PDF Template

### Task T11: Update PDF line items template

**Files:**
- Modify: `apps/api/resources/views/documents/components/line_items.blade.php`
- Modify: any country-specific variants — locate with `find apps/api/resources/views/documents -name "line_items*.blade.php"`
- Test: `apps/api/tests/Feature/Modules/Document/DocumentPdfRenderTest.php` (create or extend)

- [ ] **Step T11.1: Write the failing test**

```php
public function test_pdf_renders_description_as_primary_with_sku_and_notes(): void
{
    $invoice = $this->createPostedInvoiceWithLine([
        'description' => 'Custom Brake Name',
        'product_code' => 'BRK-001',
        'notes' => '2.5h × 60/hr by John',
    ]);

    $html = $this->app->make(\App\Modules\Document\Application\Services\DocumentPdfService::class)
        ->renderHtml($invoice);

    $this->assertStringContainsString('<strong>Custom Brake Name</strong>', $html);
    $this->assertStringContainsString('[BRK-001]', $html);
    $this->assertStringContainsString('2.5h × 60/hr by John', $html);
    $this->assertStringNotContainsString($invoice->lines->first()->product->name, $html);
}
```

- [ ] **Step T11.2: Run — FAIL** (template still uses `$line->product->name`)

- [ ] **Step T11.3: Update the blade template**

Replace the current line block in `line_items.blade.php`:

```blade
{{-- BEFORE --}}
@if($line->product)
    <strong>{{ $line->product->name }}</strong>
    @if($line->product->sku)
        <span>[{{ $line->product->sku }}]</span>
    @endif
@elseif($line->service)
    <strong>{{ $line->service->name }}</strong>
@else
    <strong>{{ $line->product_name ?? 'Item' }}</strong>
@endif
@if($line->description)
    <div class="item-description">{{ $line->description }}</div>
@endif
```

With:

```blade
<strong>{{ $line->description }}</strong>
@if($line->product_code)
    <span class="sku">[{{ $line->product_code }}]</span>
@endif
@if($line->notes)
    <div class="item-description">{{ $line->notes }}</div>
@endif
```

Apply the same edit to every country-specific variant found in T11.

- [ ] **Step T11.4: Run — PASS**

- [ ] **Step T11.5: Update any existing PDF snapshot/fixture tests**

```bash
cd apps/api
rg -l "item-description|product->name" tests/ resources/ | head
```
Update fixtures that reference the old rendering.

- [ ] **Step T11.6: Commit**

```bash
git add apps/api/resources/views/documents/ apps/api/tests/Feature/Modules/Document/DocumentPdfRenderTest.php
git commit -m "feat(documents): render line description as primary PDF text, notes as subtext"
```

### Task T12: PDF cache invalidation (conditional on D1)

- [ ] **Step T12.1: Skip if D1 found no cache**

If D1 confirmed no PDF cache exists, mark this task done and move on.

- [ ] **Step T12.2: Otherwise — write failing test**

```php
public function test_editing_line_description_invalidates_cached_pdf(): void
{
    $invoice = $this->createDraftInvoiceWithLine(['description' => 'Original']);
    $this->renderAndCachePdf($invoice);
    $this->assertTrue(\Cache::has("document_pdf:{$invoice->uuid}"));

    $this->app->make(DraftPersistenceService::class)
        ->updateLine($invoice->lines->first(), ['description' => 'Updated']);

    $this->assertFalse(\Cache::has("document_pdf:{$invoice->uuid}"));
}
```

- [ ] **Step T12.3: Wire invalidation**

In the line update flow (location identified in D1), call `Cache::forget("document_pdf:{$documentUuid}")` after any successful line mutation.

- [ ] **Step T12.4: Commit**

```bash
git commit -m "feat(documents): invalidate cached PDF on line mutation"
```

---

## Phase 6 — Types + API Surface

### Task T13: Regenerate TypeScript types

**Files:**
- Modify: `apps/api/app/Modules/Document/...DTOs/DocumentLineDto.php` (the DTO consumed by `typescript:transform`)
- Run: `php artisan typescript:transform`
- Generated: `packages/shared/types/document.ts` (or similar — do not edit manually)

- [ ] **Step T13.1: Add the field to the DTO**

Locate the line DTO with `rg -l "class.*DocumentLineDto" apps/api/app/Modules/Document/`. Add:

```php
public function __construct(
    // existing readonly props...
    public readonly ?string $designation_default_snapshot,
) {}
```

Update any DTO-construction sites to include the new prop (IDE / PHPStan will flag them).

- [ ] **Step T13.2: Run the transform**

```bash
cd apps/api
php artisan typescript:transform
```

- [ ] **Step T13.3: Verify TS compiles**

```bash
cd apps/web
pnpm typecheck
```
Expected: PASS.

- [ ] **Step T13.4: Commit**

```bash
git add apps/api/app/Modules/Document/ packages/shared/types/
git commit -m "feat(documents): expose designation_default_snapshot in line DTO + regenerated types"
```

---

## Phase 7 — Frontend: Designation Editor

### Task T14: `DocumentLineRow` / `DocumentLineEditor` — hover pencil + edit state

**Files:**
- Modify: `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- Modify: `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx`
- Test: `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx`

- [ ] **Step T14.1: Write the failing tests**

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentLineEditor } from '../DocumentLineEditor';

test('pencil is hidden by default, visible on row hover', async () => {
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={vi.fn()} />);
  expect(screen.queryByLabelText(/edit designation/i)).not.toBeVisible();

  await userEvent.hover(screen.getByTestId('line-row'));
  expect(screen.getByLabelText(/edit designation/i)).toBeVisible();
});

test('pencil is keyboard-reachable via tab', async () => {
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={vi.fn()} />);
  await userEvent.tab();
  // keep tabbing until reaching the pencil
  await userEvent.tab();
  await userEvent.tab();
  expect(screen.getByLabelText(/edit designation/i)).toHaveFocus();
});

test('pressing Enter on the pencil enters edit mode', async () => {
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={vi.fn()} />);
  const pencil = await screen.findByLabelText(/edit designation/i);
  pencil.focus();
  await userEvent.keyboard('{Enter}');
  expect(screen.getByRole('textbox', { name: /designation/i })).toHaveFocus();
});

test('Enter commits the new description', async () => {
  const onUpdate = vi.fn();
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={onUpdate} />);
  await userEvent.hover(screen.getByTestId('line-row'));
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  const input = screen.getByRole('textbox', { name: /designation/i });
  await userEvent.clear(input);
  await userEvent.type(input, 'Custom Name{Enter}');
  expect(onUpdate).toHaveBeenCalledWith(expect.objectContaining({ description: 'Custom Name' }));
});

test('Escape cancels without saving', async () => {
  const onUpdate = vi.fn();
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={onUpdate} />);
  await userEvent.hover(screen.getByTestId('line-row'));
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  const input = screen.getByRole('textbox', { name: /designation/i });
  await userEvent.type(input, ' changed');
  await userEvent.keyboard('{Escape}');
  expect(screen.getByText('Oil Filter')).toBeVisible();
  expect(onUpdate).not.toHaveBeenCalled();
});

test('empty description is rejected with inline hint', async () => {
  const onUpdate = vi.fn();
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={onUpdate} />);
  await userEvent.hover(screen.getByTestId('line-row'));
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  const input = screen.getByRole('textbox', { name: /designation/i });
  await userEvent.clear(input);
  await userEvent.keyboard('{Enter}');
  expect(screen.getByText(/designation cannot be empty/i)).toBeVisible();
  expect(onUpdate).not.toHaveBeenCalled();
});

test('input has dir="auto" for RTL safety', async () => {
  render(<DocumentLineEditor line={makeLine({ description: 'Oil Filter' })} onUpdate={vi.fn()} />);
  await userEvent.hover(screen.getByTestId('line-row'));
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  expect(screen.getByRole('textbox', { name: /designation/i })).toHaveAttribute('dir', 'auto');
});
```

- [ ] **Step T14.2: Run — fails**

```bash
cd apps/web
pnpm vitest DocumentLineEditor
```

- [ ] **Step T14.3: Implement the hover-pencil + edit state**

Edit `DocumentLineEditor.tsx` around the current designation cell. Replace the click-the-text pattern with a composed component. Sketch:

```tsx
import { Pencil } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { tokens, textColors, borderColors } from '@/lib/designTokens';

type DesignationCellProps = {
  value: string;
  originalSnapshot: string | null;
  onCommit: (next: string) => void;
};

export function DesignationCell({ value, originalSnapshot, onCommit }: DesignationCellProps) {
  const { t } = useTranslation('documents');
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const enterEdit = () => {
    setDraft(value);
    setError(null);
    setEditing(true);
    queueMicrotask(() => inputRef.current?.select());
  };

  const commit = () => {
    const trimmed = draft.trim();
    if (trimmed.length === 0) {
      setError(t('lines.designation.emptyHint'));
      return;
    }
    if (trimmed !== value) onCommit(trimmed);
    setEditing(false);
  };

  const cancel = () => {
    setDraft(value);
    setError(null);
    setEditing(false);
  };

  if (editing) {
    return (
      <div className="flex flex-col gap-1">
        <input
          ref={inputRef}
          type="text"
          dir="auto"
          maxLength={500}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
          }}
          aria-label={t('lines.designation.editAriaLabel')}
          className={`${borderColors.input} border rounded px-2 py-1`}
        />
        {error && <span className={`${textColors.danger} text-xs`}>{error}</span>}
      </div>
    );
  }

  return (
    <div className="group/line flex items-center gap-2">
      <span className={textColors.primary}>{value}</span>
      <button
        type="button"
        onClick={enterEdit}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); enterEdit(); }
        }}
        aria-label={t('lines.designation.editAriaLabel')}
        className="opacity-0 group-hover/line:opacity-100 focus-visible:opacity-100 transition-opacity"
      >
        <Pencil size={14} className={textColors.muted} />
      </button>
    </div>
  );
}
```

Wire `DesignationCell` into `DocumentLineEditor` and `DocumentLineRow` where the designation text currently lives. Pass `line.description` and `line.designation_default_snapshot` and a commit callback.

- [ ] **Step T14.4: Run — PASS**

- [ ] **Step T14.5: Commit**

```bash
git add apps/web/src/features/documents/
git commit -m "feat(documents): hover-pencil edit affordance for line designation with keyboard + empty validation"
```

### Task T15: Overridden indicator + tooltip (snapshot-based)

**Files:**
- Modify: the new `DesignationCell` component from T14

- [ ] **Step T15.1: Write failing tests**

```tsx
test('indicator appears when description differs from snapshot', () => {
  render(<DesignationCell value="Custom Name" originalSnapshot="Oil Filter" onCommit={vi.fn()} />);
  expect(screen.getByRole('status', { name: /overridden/i })).toBeVisible();
});

test('indicator hidden when description matches snapshot', () => {
  render(<DesignationCell value="Oil Filter" originalSnapshot="Oil Filter" onCommit={vi.fn()} />);
  expect(screen.queryByRole('status', { name: /overridden/i })).toBeNull();
});

test('indicator hidden when snapshot is null', () => {
  render(<DesignationCell value="Anything" originalSnapshot={null} onCommit={vi.fn()} />);
  expect(screen.queryByRole('status', { name: /overridden/i })).toBeNull();
});

test('tooltip shows original product name on indicator hover', async () => {
  render(<DesignationCell value="Custom Name" originalSnapshot="Oil Filter" onCommit={vi.fn()} />);
  await userEvent.hover(screen.getByRole('status', { name: /overridden/i }));
  expect(await screen.findByText(/original: Oil Filter/i)).toBeVisible();
});
```

- [ ] **Step T15.2: Run — fails**

- [ ] **Step T15.3: Add the indicator to `DesignationCell`**

Inside the non-editing branch of the component, before or after the text:

```tsx
const isOverridden = originalSnapshot !== null && value !== originalSnapshot;

// ... inside return:
{isOverridden && (
  <span
    role="status"
    aria-label={t('lines.designation.overriddenTooltip', { originalName: originalSnapshot ?? '' })}
    title={t('lines.designation.overriddenTooltip', { originalName: originalSnapshot ?? '' })}
    className={`inline-block h-1.5 w-1.5 rounded-full ${tokens.indicatorMuted}`}
  />
)}
```

(Use the project's existing tooltip component if one is available — grep `apps/web/src/components/` for `Tooltip`.)

- [ ] **Step T15.4: Run — PASS**

- [ ] **Step T15.5: Commit**

```bash
git add apps/web/src/features/documents/
git commit -m "feat(documents): overridden indicator with tooltip showing original product name"
```

### Task T16: Reset-to-default link + soft-delete handling

**Files:**
- Modify: `DesignationCell`

- [ ] **Step T16.1: Write failing tests**

```tsx
test('reset link appears in edit mode when value differs from snapshot', async () => {
  render(<DesignationCell value="Custom" originalSnapshot="Oil Filter" onCommit={vi.fn()} />);
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  expect(screen.getByRole('button', { name: /reset to product name/i })).toBeVisible();
});

test('reset link is hidden when value matches snapshot', async () => {
  render(<DesignationCell value="Oil Filter" originalSnapshot="Oil Filter" onCommit={vi.fn()} />);
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  expect(screen.queryByRole('button', { name: /reset to product name/i })).toBeNull();
});

test('reset link restores snapshot and exits edit mode', async () => {
  const onCommit = vi.fn();
  render(<DesignationCell value="Custom" originalSnapshot="Oil Filter" onCommit={onCommit} />);
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  await userEvent.click(screen.getByRole('button', { name: /reset to product name/i }));
  expect(onCommit).toHaveBeenCalledWith('Oil Filter');
  expect(screen.getByText('Oil Filter')).toBeVisible();
});

test('reset link is disabled when product is soft-deleted', async () => {
  render(<DesignationCell value="Custom" originalSnapshot="Oil Filter" productDeleted onCommit={vi.fn()} />);
  await userEvent.click(screen.getByLabelText(/edit designation/i));
  const btn = screen.getByRole('button', { name: /reset to product name/i });
  expect(btn).toBeDisabled();
  expect(btn).toHaveAttribute('title', expect.stringMatching(/product no longer exists/i));
});
```

- [ ] **Step T16.2: Run — fails**

- [ ] **Step T16.3: Extend `DesignationCell` with the reset affordance**

Add a new prop `productDeleted?: boolean` and, inside the editing branch:

```tsx
{editing && originalSnapshot && draft !== originalSnapshot && (
  <button
    type="button"
    disabled={productDeleted}
    title={productDeleted ? t('lines.designation.resetDisabledTooltip') : undefined}
    onClick={() => {
      onCommit(originalSnapshot);
      setEditing(false);
    }}
    aria-label={t('lines.designation.resetAriaLabel')}
    className={`text-xs ${textColors.link} disabled:${textColors.muted} disabled:cursor-not-allowed`}
  >
    {t('lines.designation.resetLink')}
  </button>
)}
```

Callers pass `productDeleted={line.product?.deleted_at != null}` (or equivalent derived signal — a service line uses `line.service?.deleted_at`).

- [ ] **Step T16.4: Run — PASS**

- [ ] **Step T16.5: Commit**

```bash
git add apps/web/src/features/documents/
git commit -m "feat(documents): reset-to-product-name link with soft-delete handling"
```

---

## Phase 8 — Frontend: Additional Description Slot

### Task T17: Notes slot — view state + "+ Add description" affordance

**Files:**
- Create: `apps/web/src/features/documents/components/NotesCell.tsx`
- Modify: `DocumentLineEditor.tsx` and `DocumentLineRow.tsx` to render it below the designation

- [ ] **Step T17.1: Write failing tests**

```tsx
test('shows "+ Add description" on hover when notes empty', async () => {
  render(<NotesCell value="" onCommit={vi.fn()} />);
  expect(screen.queryByRole('button', { name: /add description/i })).not.toBeVisible();
  await userEvent.hover(screen.getByTestId('notes-cell'));
  expect(screen.getByRole('button', { name: /add description/i })).toBeVisible();
});

test('shows muted secondary text when notes present', () => {
  render(<NotesCell value="2.5h x 60/hr" onCommit={vi.fn()} />);
  expect(screen.getByText('2.5h x 60/hr')).toBeVisible();
});

test('clicking muted text enters edit mode', async () => {
  render(<NotesCell value="Existing" onCommit={vi.fn()} />);
  await userEvent.click(screen.getByText('Existing'));
  expect(screen.getByRole('textbox', { name: /additional description/i })).toHaveFocus();
});

test('add button is keyboard activatable', async () => {
  render(<NotesCell value="" onCommit={vi.fn()} />);
  await userEvent.hover(screen.getByTestId('notes-cell'));
  screen.getByRole('button', { name: /add description/i }).focus();
  await userEvent.keyboard('{Enter}');
  expect(screen.getByRole('textbox', { name: /additional description/i })).toHaveFocus();
});
```

- [ ] **Step T17.2: Run — fails**

- [ ] **Step T17.3: Create `NotesCell`**

```tsx
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { tokens, textColors, borderColors } from '@/lib/designTokens';

type NotesCellProps = {
  value: string | null;
  onCommit: (next: string | null) => void;
};

export function NotesCell({ value, onCommit }: NotesCellProps) {
  const { t } = useTranslation('documents');
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const enterEdit = () => {
    setDraft(value ?? '');
    setEditing(true);
    queueMicrotask(() => textareaRef.current?.focus());
  };

  const commit = () => {
    const trimmed = draft.trim();
    onCommit(trimmed === '' ? null : trimmed);
    setEditing(false);
  };

  const cancel = () => {
    setDraft(value ?? '');
    setEditing(false);
  };

  if (editing) {
    return (
      <textarea
        ref={textareaRef}
        dir="auto"
        maxLength={1000}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); }
          if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        }}
        aria-label={t('lines.additionalDescription.editAriaLabel')}
        placeholder={t('lines.additionalDescription.placeholder')}
        className={`${borderColors.input} border rounded px-2 py-1 text-sm w-full`}
        rows={2}
      />
    );
  }

  if (value) {
    return (
      <button
        type="button"
        onClick={enterEdit}
        className={`${textColors.muted} text-xs text-left w-full`}
      >
        {value}
      </button>
    );
  }

  return (
    <div data-testid="notes-cell" className="group/notes">
      <button
        type="button"
        onClick={enterEdit}
        aria-label={t('lines.additionalDescription.addAriaLabel')}
        className={`opacity-0 group-hover/notes:opacity-100 focus-visible:opacity-100 transition-opacity text-xs ${textColors.muted} flex items-center gap-1`}
      >
        <Plus size={12} />
        {t('lines.additionalDescription.addLink')}
      </button>
    </div>
  );
}
```

Wire into `DocumentLineEditor` / `DocumentLineRow` below the `DesignationCell`.

- [ ] **Step T17.4: Run — PASS**

- [ ] **Step T17.5: Commit**

```bash
git add apps/web/src/features/documents/
git commit -m "feat(documents): per-line additional description slot with add-on-hover affordance"
```

### Task T18: Notes cell — multiline edit behavior

Covered by T17 steps above; add two more tests:

- [ ] **Step T18.1: Test Shift+Enter inserts a newline**

```tsx
test('Shift+Enter inserts newline, Enter commits', async () => {
  const onCommit = vi.fn();
  render(<NotesCell value="" onCommit={onCommit} />);
  await userEvent.hover(screen.getByTestId('notes-cell'));
  await userEvent.click(screen.getByRole('button', { name: /add description/i }));
  await userEvent.type(screen.getByRole('textbox', { name: /additional description/i }), 'Line 1{Shift>}{Enter}{/Shift}Line 2{Enter}');
  expect(onCommit).toHaveBeenCalledWith('Line 1\nLine 2');
});
```

- [ ] **Step T18.2: Run — PASS** (logic already in T17 implementation)

- [ ] **Step T18.3: Commit if changes**

---

## Phase 9 — Read-Only View + i18n

### Task T19: Read-only detail pages show indicator + notes

**Files:**
- Modify: document detail pages under `apps/web/src/features/documents/` (e.g., `InvoiceDetailPage`, `QuoteDetailPage`, `PurchaseOrderDetailPage`, etc.)
- Modify: `apps/web/src/features/documents/components/DocumentLines.tsx` (the read-only line list)

- [ ] **Step T19.1: Write failing test**

```tsx
test('read-only line shows description with indicator + notes subtext', () => {
  render(<DocumentLines lines={[
    makeLine({ description: 'Custom', notes: 'detail', designation_default_snapshot: 'Oil Filter' }),
  ]} readOnly />);
  expect(screen.getByText('Custom')).toBeVisible();
  expect(screen.getByRole('status', { name: /overridden/i })).toBeVisible();
  expect(screen.getByText('detail')).toBeVisible();
  // no pencil in read-only
  expect(screen.queryByLabelText(/edit designation/i)).toBeNull();
});
```

- [ ] **Step T19.2: Run — FAIL**

- [ ] **Step T19.3: Update `DocumentLines.tsx`**

Render `DesignationCell` with `readOnly` (new prop that hides the pencil but keeps the indicator) and `NotesCell` with `readOnly` (shows muted text only, no affordance / no edit click).

Add the `readOnly` prop to both cell components:

```tsx
// DesignationCell
type DesignationCellProps = {
  // existing...
  readOnly?: boolean;
};
// render the pencil only when !readOnly
```

- [ ] **Step T19.4: Run — PASS**

- [ ] **Step T19.5: Commit**

```bash
git add apps/web/src/features/documents/
git commit -m "feat(documents): read-only line view renders designation + notes + indicator, no edit affordances"
```

### Task T20: Add i18n keys

**Files:**
- Modify: `apps/web/src/i18n/locales/en/documents.json`
- Modify: `apps/web/src/i18n/locales/fr/documents.json` (and every other locale that has a `documents` namespace — locate with `ls apps/web/src/i18n/locales/*/documents.json`)

- [ ] **Step T20.1: Add keys to every locale**

Add to `documents.json` in each locale (English example):

```json
{
  "lines": {
    "designation": {
      "editTooltip": "Edit designation",
      "editAriaLabel": "Edit designation",
      "overriddenTooltip": "Designation overridden — original: {{originalName}}",
      "resetLink": "Reset to product name",
      "resetAriaLabel": "Reset designation to product name",
      "resetDisabledTooltip": "Product no longer exists",
      "emptyHint": "Designation cannot be empty"
    },
    "additionalDescription": {
      "addLink": "Add description",
      "addAriaLabel": "Add additional description",
      "editAriaLabel": "Additional description",
      "placeholder": "e.g. 2.5h × 60/hr by mechanic John"
    }
  }
}
```

Translate into French (and any other locale present — Arabic strings are flat text, `dir="auto"` on the input handles rendering).

- [ ] **Step T20.2: Verify no untranslated placeholders render in tests**

```bash
cd apps/web
pnpm vitest DocumentLineEditor NotesCell
```
Expected: tests pass, no raw key names visible.

- [ ] **Step T20.3: Commit**

```bash
git add apps/web/src/i18n/locales/
git commit -m "i18n(documents): add designation override + additional description keys"
```

---

## Phase 10 — E2E + Ship

### Task T21: End-to-end smoke test

**Files:**
- Create: `apps/web/tests/e2e/document-line-designation-override.spec.ts` (or wherever the project's Playwright specs live — grep `playwright.config`)

- [ ] **Step T21.1: Write the E2E spec**

```ts
import { test, expect } from '@playwright/test';

test('user overrides designation, adds description, posts invoice, PDF reflects overrides', async ({ page }) => {
  await page.goto('/invoices/new');
  // seed product, pick it, override designation, add notes, post
  await page.getByRole('button', { name: /add product/i }).click();
  await page.getByRole('option', { name: /Oil Filter/i }).click();

  // hover the line to reveal the pencil
  await page.locator('[data-testid="line-row"]').hover();
  await page.getByLabel(/edit designation/i).click();
  await page.getByRole('textbox', { name: /designation/i }).fill('Custom Oil Filter Name');
  await page.keyboard.press('Enter');

  // add description
  await page.locator('[data-testid="notes-cell"]').hover();
  await page.getByRole('button', { name: /add description/i }).click();
  await page.getByRole('textbox', { name: /additional description/i }).fill('Installed by John');
  await page.keyboard.press('Enter');

  // overridden indicator visible
  await expect(page.getByRole('status', { name: /overridden/i })).toBeVisible();

  // post the invoice
  await page.getByRole('button', { name: /post/i }).click();

  // pencil + add affordance no longer visible on posted doc
  await expect(page.getByLabel(/edit designation/i)).toHaveCount(0);

  // verify PDF via API (download and grep)
  const pdfResponse = await page.request.get(page.url() + '/pdf');
  const pdfText = await pdfResponse.text();
  expect(pdfText).toContain('Custom Oil Filter Name');
  expect(pdfText).toContain('Installed by John');
});
```

- [ ] **Step T21.2: Run — expect pass**

```bash
cd apps/web
pnpm playwright test document-line-designation-override.spec.ts
```

- [ ] **Step T21.3: Commit**

```bash
git add apps/web/tests/e2e/
git commit -m "test(documents): E2E smoke for designation override + additional description"
```

### Task T22: Preflight + feature-flag enable for staging

**Files:**
- Modify: staging env `.env.staging` (or the project's staging configuration) — set `FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE=true`

- [ ] **Step T22.1: Run preflight**

```bash
./scripts/preflight.sh
```
Expected: PASS. Fix anything red before proceeding.

- [ ] **Step T22.2: Enable the flag in staging**

Per the project's env-management convention, set the feature flag to `true` in staging. Production stays `false` until post-launch verification.

- [ ] **Step T22.3: Open the PR**

```bash
git push -u origin feat/document-line-designation-override
gh pr create --base dev --title "feat(documents): per-line designation override + additional description" \
  --body "$(cat <<'EOF'
## Summary
- Let users override the customer-facing line designation on all 5 document types
- Activate existing notes column as customer-facing additional description
- Flow both through PDF / print; e-invoicing mapping documented for future
- Hover-pencil UX with snapshot-based overridden indicator, reset-to-default, keyboard + screen-reader accessible
- Workshop converter now splits header + detail instead of em-dash concatenation

Spec: docs/superpowers/specs/2026-04-24-document-line-designation-override-design.md
Plan: docs/superpowers/plans/2026-04-24-document-line-designation-override.md

Behind feature flag: FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE (default off in prod).

## Test plan
- [ ] preflight clean (PHPStan level 8, Pint, PHPUnit, Vitest, ESLint, TS strict)
- [ ] E2E smoke passes
- [ ] Manual: create draft invoice, override designation, add description, post, verify PDF
- [ ] Manual: verify indicator + tooltip on posted invoice detail page
- [ ] Manual: verify Arabic RTL rendering in editor + PDF
EOF
)"
```

- [ ] **Step T22.4: Done.**

---

## Self-Review Checklist

Run this on the final plan before handing it off:

**Spec coverage:**
- [ ] Goal 1 (override designation on 5 doc types) — covered by T14, T15, T16, T21
- [ ] Goal 2 (additional description) — covered by T17, T18
- [ ] Goal 3 (flows to PDF / e-invoicing) — covered by T11, T13; e-invoicing future per §7.5
- [ ] Goal 4 (preserve product_id) — unchanged throughout (T3, T10 explicitly don't touch)
- [ ] Goal 5 (workshop split) — covered by T9
- [ ] Snapshot column — T2, T3, T4, T6, T13
- [ ] Posted-line lock — T8
- [ ] Conversions carry — T10
- [ ] Feature flag — T1, T22
- [ ] A11y — T14 (keyboard), T15 (screen reader), T17 (keyboard on notes)
- [ ] RTL — T14 (`dir="auto"`), T17 (`dir="auto"`)
- [ ] Permissions — reuse existing, tested via T5 / T8 fixtures
- [ ] Character limits — T5
- [ ] Empty-description rejection — T5, T14
- [ ] Event-sourcing — T7
- [ ] PDF cache — T12

**Placeholder scan:**
- [ ] No "TBD", "TODO", "fill in later", "add error handling"
- [ ] Every code step shows actual code

**Type consistency:**
- [ ] `designation_default_snapshot` same name in migration, model, DTO, types, frontend cells
- [ ] i18n keys match across test expectations and locale files
