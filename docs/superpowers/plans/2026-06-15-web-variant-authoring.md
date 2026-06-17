# Web-Admin Variant Authoring & Onboarding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give web-admin a real per-product variant authoring flow — pick which option values apply to a product, generate the matrix idempotently (additive + restore), guard combinatorial explosion, enforce barcode uniqueness with clean 422s, gate variant deletion on stock, and add an onboarding step.

**Architecture:** Backend changes live in the Catalog module (matrix service, DTO, requests, controller), the Tenant module (onboarding), and one new Shared contract implemented by Inventory (stock guard). Frontend changes live in `apps/web` catalog feature (API client, hooks, the `ProductVariantMatrixEditor` rework, i18n). Matrix idempotency is keyed on the `product_variant_attribute_values` junction (attribute_id/value_id pairs), not `variant_code` strings.

**Tech Stack:** Laravel 12 / PHP 8.2 strict / hexagonal / PostgreSQL (database-per-tenant) / PHPUnit; React 19 / Vite / TypeScript strict / TanStack Query 5 / Vitest; Spatie Laravel Data DTOs; react-i18next.

**Spec:** `docs/superpowers/specs/2026-06-15-web-variant-authoring-design.md` (v3, owner-approved). Reviews: `…/reviews/2026-06-15-web-variant-authoring-spec-codex-review{,-r2}.md`.

**Constants:** `MAX_VARIANTS_PER_GENERATE = 200`, `VARIANT_COUNT_SOFT_WARN = 50`.

**Test-run safety (from project memory):** NEVER run the full PHPUnit suite. Always scope with `--filter`. Constraint/restore/race tests need real PostgreSQL — run them against the local PG test DB, not SQLite.

**Execution order (dependency-correct — follow this sequence, not the document order):**
`A1 → A2 → B1 → A3 → B2 → A4 → C1 → C2 → D1 → D2 → E1 → E2 → F1 → F2 → G1 → G2 → G3 → G4 → G5 → H1`.
Rationale: A3's matrix service reads the `attributeValues` relation (Task **B1**), and A4's controller response uses both the relation and the DTO `attribute_values` field (Tasks **B1 + B2**). So **B1 must precede A3** and **B2 must precede A4**. Each affected task repeats its `Depends on:` line.

**Test fixtures (backend):** these factories already exist — use them, don't hand-roll inserts:
`database/factories/Catalog/{ProductAttributeFactory,ProductAttributeValueFactory,ProductVariantFactory,ProductVariantAttributeValueFactory}.php`. Mirror the tenant/auth/seeding setup in the existing `apps/api/tests/Feature/Catalog/ProductVariantServiceMatrixTest.php` and `ProductVariantApiTest.php` (RefreshDatabase + `RolesAndPermissionsSeeder`, real models). A reusable local helper for these tasks:

```php
// product with one is_variant_axis attribute "Size" (codes 36/37/38) + "Color" (noir/blanc)
$product = Product::factory()->for($company)->create(['sku' => 'TSHIRT']);
$size = ProductAttribute::factory()->create(['code' => 'taille', 'name' => 'Size', 'is_variant_axis' => true]);
[$v36, $v37, $v38] = collect(['36', '37', '38'])
    ->map(fn ($c) => ProductAttributeValue::factory()->for($size, 'attribute')->create(['code' => $c, 'label' => $c]))->all();
```
(Use the same shape for Color. Adjust factory relation names to match the factories above.)

---

## File map

**Backend — create:**
- `apps/api/app/Modules/Catalog/Domain/Support/VariantMatrixLimit.php` — holds `MAX_VARIANTS_PER_GENERATE`.
- `apps/api/app/Shared/Domain/Exceptions/MatrixGenerationLimitException.php`
- `apps/api/app/Shared/Domain/Exceptions/DuplicateBarcodeException.php`
- `apps/api/app/Shared/Contracts/VariantStockReader.php` — `variantOnHandQuantity()`.
- `apps/api/app/Modules/Inventory/Application/Services/VariantStockReaderService.php` — implements it.
- `apps/api/app/Modules/Catalog/Application/DTOs/VariantAttributeValueData.php`
- Tests under `apps/api/tests/Feature/Catalog/` and `apps/api/tests/Unit/Catalog/`.

**Backend — modify:**
- `…/Catalog/Application/Services/ProductVariantService.php` — matrix overhaul, restore, barcode catch.
- `…/Catalog/Application/Services/ProductVariantMatrixGenerator.php` — already supports `$excluded`; no change expected (verify).
- `…/Catalog/Domain/Entities/ProductVariant.php` — add `attributeValues()` relation.
- `…/Catalog/Application/DTOs/ProductVariantData.php` — add `attribute_values`.
- `…/Catalog/Presentation/Requests/{GenerateMatrixRequest,CreateVariantRequest,UpdateVariantRequest}.php`
- `…/Catalog/Presentation/Controllers/ProductVariantController.php` — new request shape, meta response, eager-load, delete guard.
- `…/Inventory/Providers/InventoryServiceProvider.php` — bind `VariantStockReader`.
- `…/Tenant/Domain/Enums/OnboardingStep.php`, `…/Tenant/Application/Services/OnboardingChecklistService.php`

**Frontend — modify:**
- `apps/web/src/features/catalog/api/variantApi.ts`
- `apps/web/src/features/catalog/hooks/useVariants.ts`
- `apps/web/src/features/catalog/components/ProductVariantMatrixEditor.tsx`
- `apps/web/src/features/catalog/components/__tests__/ProductVariantMatrixEditor.test.tsx`
- `apps/web/public/locales/en/catalog.json`, `…/fr/catalog.json`, and the `onboarding` namespace files (en/fr).

---

## Milestone A — Backend: matrix generation overhaul

### Task A1: Matrix limit constant + value-subset request contract

**Files:**
- Create: `apps/api/app/Modules/Catalog/Domain/Support/VariantMatrixLimit.php`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/GenerateMatrixRequest.php`
- Test: `apps/api/tests/Feature/Catalog/GenerateMatrixRequestTest.php`

- [ ] **Step 1: Create the limit holder**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Support;

final class VariantMatrixLimit
{
    /** Maximum total variants a single generate call may produce for a product. */
    public const MAX_VARIANTS_PER_GENERATE = 200;
}
```

- [ ] **Step 2: Write the failing request test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Tests\TestCase;
// ...standard tenant/auth setup helpers used by other Catalog feature tests...

final class GenerateMatrixRequestTest extends TestCase
{
    public function test_accepts_axes_with_value_subset(): void
    {
        // seed a product + an is_variant_axis attribute with 3 values
        // authenticate a user with catalog.variants.create
        $response = $this->postJson("/api/v1/products/{$productId}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attrId, 'value_ids' => [$v1, $v2]],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data', 'meta' => ['created_count', 'skipped_count', 'restored_count']]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_rejects_gross_product_over_cap_with_422(): void
    {
        // two axes whose |value_ids| product is 201
        $response = $this->postJson("/api/v1/products/{$productId}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attrA, 'value_ids' => $threeValues /* 3 */],
                ['attribute_id' => $attrB, 'value_ids' => $sixtySevenValues /* 67 => 201 */],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['combinations']);
    }

    public function test_rejects_value_id_not_belonging_to_attribute(): void
    {
        $response = $this->postJson("/api/v1/products/{$productId}/variants/generate-matrix", [
            'axes' => [['attribute_id' => $attrId, 'value_ids' => [$valueFromOtherAttr]]],
        ]);
        $response->assertStatus(422);
    }

    public function test_legacy_attribute_ids_shape_still_accepted(): void
    {
        $response = $this->postJson("/api/v1/products/{$productId}/variants/generate-matrix", [
            'attribute_ids' => [$attrId],
        ]);
        $response->assertCreated();
    }
}
```

- [ ] **Step 3: Run it — expect failure**

Run: `cd apps/api && php artisan test --filter=GenerateMatrixRequestTest`
Expected: FAIL (cap + axes validation not implemented).

- [ ] **Step 4: Implement the request**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Support\VariantMatrixLimit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class GenerateMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.variants.create') ?? false;
    }

    /**
     * Accepts either the preferred `axes` shape or the legacy `attribute_ids`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'axes' => ['sometimes', 'array', 'min:1'],
            'axes.*.attribute_id' => ['required_with:axes', 'uuid'],
            'axes.*.value_ids' => ['required_with:axes', 'array', 'min:1'],
            'axes.*.value_ids.*' => ['uuid'],
            'attribute_ids' => ['sometimes', 'array', 'min:1'],
            'attribute_ids.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $axes = $this->input('axes');
            if (! is_array($axes) && $this->input('attribute_ids') === null) {
                $validator->errors()->add('axes', 'Either axes or attribute_ids is required.');

                return;
            }

            if (is_array($axes)) {
                // Each value_id must belong to its attribute.
                foreach ($axes as $i => $axis) {
                    $valid = ProductAttributeValue::query()
                        ->where('attribute_id', $axis['attribute_id'])
                        ->whereIn('id', $axis['value_ids'])
                        ->count();
                    if ($valid !== count(array_unique($axis['value_ids']))) {
                        $validator->errors()->add("axes.$i.value_ids", 'One or more values do not belong to the attribute.');
                    }
                }

                // Gross resulting matrix size = product of selected value counts.
                $gross = array_product(array_map(
                    static fn (array $axis): int => count(array_unique($axis['value_ids'])),
                    $axes,
                ));
                if ($gross > VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE) {
                    $validator->errors()->add('combinations', "This selection would create {$gross} variants, over the limit of ".VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE.'.');
                }
            }
        });
    }
}
```

- [ ] **Step 5: Run it — expect pass**

Run: `cd apps/api && php artisan test --filter=GenerateMatrixRequestTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Catalog/Domain/Support/VariantMatrixLimit.php \
        apps/api/app/Modules/Catalog/Presentation/Requests/GenerateMatrixRequest.php \
        apps/api/tests/Feature/Catalog/GenerateMatrixRequestTest.php
git commit -m "feat(catalog): generate-matrix accepts value-subset axes + gross cap (422)"
```

---

### Task A2: Matrix limit exception class (no service edit)

> **Why exception-only:** the service-boundary cap GUARD and its test live in Task A3 (the rewrite), because the cap must run against A3's new `array $axes` signature. Editing the current `generateMatrix(array $attributeIds)` here would mean A2's test uses a signature that doesn't exist yet (Opus review HIGH). A2 only creates the exception type.

**Files:**
- Create: `apps/api/app/Shared/Domain/Exceptions/MatrixGenerationLimitException.php`
- Test: `apps/api/tests/Unit/Catalog/MatrixGenerationLimitExceptionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_exceeded_builds_message_with_counts(): void
{
    $e = MatrixGenerationLimitException::exceeded(201, 200);
    $this->assertInstanceOf(\RuntimeException::class, $e);
    $this->assertStringContainsString('201', $e->getMessage());
    $this->assertStringContainsString('200', $e->getMessage());
}
```

- [ ] **Step 2: Run — expect fail.** `cd apps/api && php artisan test --filter=MatrixGenerationLimitExceptionTest` → FAIL (class missing).

- [ ] **Step 3: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use RuntimeException;

final class MatrixGenerationLimitException extends RuntimeException
{
    public static function exceeded(int $requested, int $max): self
    {
        return new self("Variant matrix of {$requested} exceeds the limit of {$max}.");
    }
}
```

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(catalog): MatrixGenerationLimitException type"
```

---

### Task A3: `generateMatrix` — value-subset, junction-keyed exclude, restore-on-regenerate

**Depends on:** Task **B1** (`ProductVariant::attributeValues()` relation — this task reads `$variant->attributeValues`). Run B1 first.

**Files:**
- Create: `apps/api/app/Shared/Domain/Exceptions/DuplicateBarcodeException.php` (the unique-violation→422 mapper; introduced here because the restore path is the first save that can collide. **Task C2 reuses it** for the single create/update paths.)
- Modify: `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php`
- Test: `apps/api/tests/Feature/Catalog/ProductVariantMatrixGenerationTest.php` (**needs PostgreSQL** — uses unique constraints + soft-delete partial indexes)

The new signature accepts a normalized `$axes` argument: `array<int, array{attributeId: string, valueIds: string[]}>`. The controller (Task A4) builds this from either request shape. Returns an array `['created' => Collection, 'restored' => Collection, 'skipped_count' => int]`.

**On `cartesian()`'s `$excluded` arg:** the generator still accepts it, but this task deliberately does NOT use it. We need a **three-way** per-combo decision — skip (active exists) / restore (soft-deleted exists) / create (missing) — which a pure exclude list cannot express. So we generate the full cartesian product and branch per combo against `$byComboKey`. (Spec §3.1 prose mentions `$excluded`; this is the equivalent, restore-aware realization.)

**Create `DuplicateBarcodeException` first:**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class DuplicateBarcodeException
{
    /** True when $e is a PG unique violation (23505) on the given constraint name. */
    public static function isViolationOf(QueryException $e, string $constraint): bool
    {
        return (($e->errorInfo[0] ?? null) === '23505') && str_contains($e->getMessage(), $constraint);
    }

    public static function asValidation(string $message): ValidationException
    {
        return ValidationException::withMessages(['barcode' => [$message]]);
    }
}
```

- [ ] **Step 1: Write the failing tests**

```php
public function test_generates_only_selected_value_subset(): void
{
    // Size attr has values 36,37,38; select only 36,37. Color noir,blanc; select both.
    $result = $service->generateMatrix($productId, [
        ['attributeId' => $sizeId, 'valueIds' => [$v36, $v37]],
        ['attributeId' => $colorId, 'valueIds' => [$noir, $blanc]],
    ]);
    $this->assertSame(4, $result['created']->count());           // 2x2, not 3x2
    $this->assertSame(0, $result['skipped_count']);
}

public function test_regenerate_same_selection_is_idempotent(): void
{
    $service->generateMatrix($productId, $axes);
    $second = $service->generateMatrix($productId, $axes);
    $this->assertSame(0, $second['created']->count());
    $this->assertSame(0, $second['restored']->count());
    $this->assertCount(4, ProductVariant::where('product_id', $productId)->get());
}

public function test_adding_a_value_creates_only_new_combos(): void
{
    $service->generateMatrix($productId, [['attributeId' => $sizeId, 'valueIds' => [$v36]], ['attributeId' => $colorId, 'valueIds' => [$noir]]]); // 1
    $result = $service->generateMatrix($productId, [['attributeId' => $sizeId, 'valueIds' => [$v36, $v37]], ['attributeId' => $colorId, 'valueIds' => [$noir]]]); // adds 37/noir
    $this->assertSame(1, $result['created']->count());
}

public function test_regenerate_restores_a_soft_deleted_combo(): void
{
    $first = $service->generateMatrix($productId, $axesOneCombo); // 36/noir
    $variant = $first['created']->first();
    $variant->barcode = '3401111';
    $variant->save();
    $variant->delete(); // soft delete

    $result = $service->generateMatrix($productId, $axesOneCombo);
    $this->assertSame(0, $result['created']->count());
    $this->assertSame(1, $result['restored']->count());
    $restored = $result['restored']->first()->fresh();
    $this->assertNull($restored->deleted_at);
    $this->assertSame('3401111', $restored->barcode); // prior edits preserved
    // exactly one row for the combo (active), no duplicate:
    $this->assertSame(1, ProductVariant::withTrashed()->where('product_id', $productId)->count());
}

public function test_idempotency_survives_value_code_rename(): void
{
    $service->generateMatrix($productId, $axesOneCombo);
    // rename the value code (label/code edit) — IDs unchanged
    ProductAttributeValue::find($v36)->update(['code' => 'TAILLE_36']);
    $result = $service->generateMatrix($productId, $axesOneCombo);
    $this->assertSame(0, $result['created']->count()); // matched by ID, not code
}

public function test_generate_matrix_throws_when_gross_exceeds_cap(): void
{
    // axes whose product of value counts is 201; call the service directly
    $this->expectException(MatrixGenerationLimitException::class);
    try {
        $service->generateMatrix($productId, $axesOf201);
    } finally {
        $this->assertSame(0, ProductVariant::where('product_id', $productId)->count()); // zero written
    }
}

public function test_restore_with_conflicting_barcode_maps_to_422_not_500(): void
{
    // combo C generated then soft-deleted with barcode B; a DIFFERENT active
    // variant later takes barcode B; re-selecting C must surface a 422, not a 500.
    $first = $service->generateMatrix($productId, $axesOneCombo);
    $deleted = $first['created']->first();
    $deleted->barcode = '3409999'; $deleted->save(); $deleted->delete();
    // another active variant grabs 3409999 (partial unique excludes the soft-deleted one)
    ProductVariant::factory()->for($product)->create(['barcode' => '3409999', 'variant_code' => 'OTHER', 'sku' => 'OTHER']);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $service->generateMatrix($productId, $axesOneCombo);
}
```

- [ ] **Step 2: Run — expect fail**

Run (against PG): `cd apps/api && php artisan test --filter=ProductVariantMatrixGenerationTest`
Expected: FAIL (new signature/behavior absent).

- [ ] **Step 3: Rewrite `generateMatrix`**

Replace the method body. Key logic: load values **by ID** (current codes), build `$axes`/`$lookup`, guard cap, compute existing combos by **junction ID-set**, derive `$excluded` (code-shape) and the restore-map, then for each generated combo either skip / restore (with `lockForUpdate`) / create.

```php
/**
 * @param  array<int, array{attributeId: string, valueIds: string[]}>  $axes
 * @return array{created: Collection<int, ProductVariant>, restored: Collection<int, ProductVariant>, skipped_count: int}
 */
public function generateMatrix(string $productId, array $axes): array
{
    $product = Product::find($productId);
    if ($product === null) {
        throw new RuntimeException("Product [{$productId}] not found.");
    }

    // Build code-axes + lookup from values loaded BY ID (codes always current).
    /** @var array<string, string[]> $codeAxes */
    $codeAxes = [];
    /** @var array<string, array<string, array{attributeId: string, attributeValueId: string, label: string}>> $lookup */
    $lookup = [];
    // Map attribute_value_id -> attribute_id, for keying existing variants in ID space.
    $valueToAttribute = [];

    foreach ($axes as $axis) {
        $attribute = $this->attributeRepo->findById($axis['attributeId']);
        if ($attribute === null) {
            throw new RuntimeException("Attribute [{$axis['attributeId']}] not found.");
        }
        $values = $this->attributeValueRepo->listForAttribute($attribute->id)
            ->whereIn('id', $axis['valueIds']);

        $axisCodes = [];
        foreach ($values as $value) {
            $axisCodes[] = $value->code;
            $lookup[$attribute->code][$value->code] = [
                'attributeId' => $attribute->id,
                'attributeValueId' => $value->id,
                'label' => $value->label,
            ];
            $valueToAttribute[$value->id] = $attribute->id;
        }
        $codeAxes[$attribute->code] = $axisCodes;
    }

    $gross = array_product(array_map('count', $codeAxes));
    if ($gross > VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE) {
        throw MatrixGenerationLimitException::exceeded($gross, VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE);
    }

    // Existing variants (incl. trashed) with their junction, keyed in ID-space.
    $existing = ProductVariant::withTrashed()
        ->where('product_id', $productId)
        ->with('attributeValues')
        ->get();

    /** @var array<string, ProductVariant> $byComboKey  key => variant */
    $byComboKey = [];
    foreach ($existing as $variant) {
        $byComboKey[$this->comboKeyFromJunction($variant)] = $variant;
    }

    $hasExistingDefault = $existing->contains(fn (ProductVariant $v): bool => $v->is_default && $v->deleted_at === null);

    $combos = $this->matrixGenerator->cartesian($codeAxes);

    $created = collect();
    $restored = collect();
    $skipped = 0;

    return DB::transaction(function () use ($combos, $lookup, $product, $productId, $byComboKey, $hasExistingDefault, &$created, &$restored, &$skipped): array {
        $first = true;
        foreach ($combos as $combo) {
            // Translate this generated combo (codes) -> ID-set key.
            $idPairs = [];
            foreach ($combo as $axisCode => $valueCode) {
                $idPairs[$lookup[$axisCode][$valueCode]['attributeId']] = $lookup[$axisCode][$valueCode]['attributeValueId'];
            }
            $key = $this->comboKey($idPairs);

            if (isset($byComboKey[$key])) {
                $match = $byComboKey[$key];
                if ($match->deleted_at === null) {
                    $skipped++;            // active: leave untouched
                } else {
                    // Restore the soft-deleted row under a row lock. Use the
                    // explicit where()->lockForUpdate()->first() form so the FOR
                    // UPDATE clause is unambiguously applied before the row is read.
                    $locked = ProductVariant::withTrashed()->where('id', $match->id)->lockForUpdate()->first();
                    try {
                        $locked->restore();    // un-soft-delete; preserves prior edits
                    } catch (QueryException $e) {
                        // Reviving the row re-activates its sku/barcode partial-unique
                        // indexes; if an active variant took the value meanwhile, map to
                        // 422 instead of letting a raw 500 escape (spec §3.1 restore edge).
                        if (DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_barcode_unique')) {
                            throw DuplicateBarcodeException::asValidation('Cannot restore variant: its barcode is now used by another variant.');
                        }
                        if (DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_sku_unique')) {
                            throw ValidationException::withMessages(['sku' => ['Cannot restore variant: its SKU is now used by another variant.']]);
                        }
                        throw $e;
                    }
                    $locked->load('attributeValues');
                    $restored->push($locked);
                }
                $first = false;
                continue;
            }

            // Create a brand-new combo.
            $created->push($this->createVariant($this->commandFor($product, $combo, $lookup, $first && ! $hasExistingDefault)));
            $first = false;
        }

        return ['created' => $created, 'restored' => $restored, 'skipped_count' => $skipped];
    });
}

private function comboKey(array $attributeIdToValueId): string
{
    ksort($attributeIdToValueId);
    return implode('|', array_map(fn ($a, $v) => "$a:$v", array_keys($attributeIdToValueId), $attributeIdToValueId));
}

private function comboKeyFromJunction(ProductVariant $variant): string
{
    $pairs = [];
    foreach ($variant->attributeValues as $row) {
        $pairs[$row->attribute_id] = $row->attribute_value_id;
    }
    return $this->comboKey($pairs);
}
```

Add a private `commandFor(Product $product, array $combo, array $lookup, bool $isDefault): CreateVariantCommand` that builds `variant_code`/`sku`/`name_suffix`/`attributeValues` exactly as the previous loop did (uppercase code suffix joined by `-`; labels joined by ` / `).

- [ ] **Step 4: Run — expect pass.** `php artisan test --filter=ProductVariantMatrixGenerationTest` (PG) → PASS.

- [ ] **Step 5: PHPStan + Pint**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog && ./vendor/bin/pint app/Modules/Catalog`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(catalog): value-subset, junction-keyed idempotent matrix with restore-on-regenerate"
```

---

### Task A4: Controller wires axes + meta response

**Depends on:** Tasks **B1 + B2** (`loadMissing('attributeValues')` and the DTO `attribute_values` field). Run B1 and B2 first.

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php`
- Test: `apps/api/tests/Feature/Catalog/GenerateMatrixResponseTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_response_keeps_data_array_and_adds_meta_counts(): void
{
    $response = $this->postJson("/api/v1/products/{$productId}/variants/generate-matrix", [
        'axes' => [['attribute_id' => $attrId, 'value_ids' => [$v1, $v2]]],
    ]);
    $response->assertCreated();
    $this->assertIsArray($response->json('data'));            // unchanged shape
    $this->assertSame(2, $response->json('meta.created_count'));
    $this->assertSame(0, $response->json('meta.skipped_count'));
    $this->assertSame(0, $response->json('meta.restored_count'));
}
```

- [ ] **Step 2: Run — expect fail.** `php artisan test --filter=GenerateMatrixResponseTest` → FAIL.

- [ ] **Step 3: Update `generateMatrix` action**

```php
public function generateMatrix(GenerateMatrixRequest $request, string $productId): JsonResponse
{
    if (! Str::isUuid($productId)) {
        return response()->json(['message' => 'Invalid ID format'], 400);
    }
    $company = $this->companyContext->requireCompany();
    $product = Product::query()
        ->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)->find($productId);
    if ($product === null) {
        return response()->json(['message' => 'Product not found'], 404);
    }

    $axes = $this->normalizeAxes($request); // axes shape OR expand legacy attribute_ids to all values

    $result = $this->variantService->generateMatrix($product->id, $axes);

    $affected = $result['created']->merge($result['restored']);

    return response()->json([
        'data' => $affected->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v->loadMissing('attributeValues')))->values(),
        'meta' => [
            'created_count' => $result['created']->count(),
            'restored_count' => $result['restored']->count(),
            'skipped_count' => $result['skipped_count'],
        ],
    ], 201);
}
```

`normalizeAxes()`: if `axes` present, map each to `['attributeId' => …, 'valueIds' => …]`; else for each legacy `attribute_id`, load all its value IDs and use them. Map `MatrixGenerationLimitException` to a 422 (register in the module/global handler or catch here returning `response()->json(['errors' => ['combinations' => [$e->getMessage()]]], 422)`).

- [ ] **Step 4: Run — expect pass.** `php artisan test --filter=GenerateMatrixResponseTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(catalog): matrix controller — axes normalization + meta counts response"
```

---

## Milestone B — Backend: variant DTO exposes junction

### Task B1: `attributeValues()` relation

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariant.php`
- Test: `apps/api/tests/Unit/Catalog/ProductVariantRelationTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_variant_has_attribute_values_relation(): void
{
    $variant = /* create variant + 2 junction rows */;
    $this->assertCount(2, $variant->attributeValues);
    $this->assertSame($attributeId, $variant->attributeValues->first()->attribute_id);
}
```

- [ ] **Step 2: Run — expect fail.** `php artisan test --filter=ProductVariantRelationTest` → FAIL.

- [ ] **Step 3: Add the relation**

```php
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @return HasMany<ProductVariantAttributeValue, $this>
 */
public function attributeValues(): HasMany
{
    return $this->hasMany(ProductVariantAttributeValue::class, 'variant_id');
}
```

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(catalog): ProductVariant::attributeValues relation"`

---

### Task B2: DTO `attribute_values` + eager-load + TS types

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/DTOs/VariantAttributeValueData.php`
- Modify: `apps/api/app/Modules/Catalog/Application/DTOs/ProductVariantData.php`, `ProductVariantController.php` (index/store/update eager-load)
- Test: `apps/api/tests/Feature/Catalog/ProductVariantDtoTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_variant_listing_exposes_attribute_values_without_n_plus_1(): void
{
    // create product with 3 variants, each 2 junction rows
    \DB::enableQueryLog();
    $response = $this->getJson("/api/v1/products/{$productId}/variants");
    $response->assertOk();
    $response->assertJsonStructure(['data' => [['id', 'attribute_values' => [['attribute_id', 'attribute_value_id']]]]]);
    // eager-loaded: index query + single attributeValues query (not one per variant)
    $this->assertLessThanOrEqual(3, count(\DB::getQueryLog()));
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Create the value DTO**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VariantAttributeValueData extends Data
{
    public function __construct(
        public string $attribute_id,
        public string $attribute_value_id,
    ) {}
}
```

- [ ] **Step 4: Extend `ProductVariantData`**

Add the property and populate from the **loaded** relation only:

```php
// constructor: add as last param
/** @var array<int, VariantAttributeValueData> */
public array $attribute_values = [],

// in fromModel():
attribute_values: $variant->relationLoaded('attributeValues')
    ? $variant->attributeValues->map(fn ($r) => new VariantAttributeValueData($r->attribute_id, $r->attribute_value_id))->all()
    : [],
```

- [ ] **Step 5: Eager-load in controller reads.** In `index`, add `->with('attributeValues')` to the variant query; in `store`/`update`, `$variant->loadMissing('attributeValues')` before `fromModel`.

- [ ] **Step 6: Run — expect pass.** PASS.

- [ ] **Step 7: Regenerate TS types**

Run: `cd apps/api && php artisan typescript:transform`
Expected: `packages/shared/types/generated.d.ts` updated with `attribute_values` on `ProductVariantData` and the new `VariantAttributeValueData`.

- [ ] **Step 8: Commit**

```bash
git add apps/api/app/Modules/Catalog apps/api/tests/Feature/Catalog/ProductVariantDtoTest.php packages/shared/types/generated.d.ts
git commit -m "feat(catalog): expose variant attribute_values in DTO (eager-loaded)"
```

---

## Milestone C — Backend: barcode uniqueness → 422

### Task C1: FormRequest uniqueness rules + max:100

**Files:**
- Modify: `CreateVariantRequest.php`, `UpdateVariantRequest.php`
- Test: `apps/api/tests/Feature/Catalog/VariantBarcodeValidationTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_create_rejects_duplicate_barcode_with_422(): void
{
    // existing active variant with barcode 3401234 in this tenant
    $response = $this->postJson("/api/v1/products/{$productId}/variants", [
        'variant_code' => 'X', 'sku' => 'X', 'name_suffix' => 'X', 'barcode' => '3401234',
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['barcode']);
}

public function test_update_allows_keeping_own_barcode(): void
{
    $response = $this->patchJson("/api/v1/product-variants/{$variantId}", ['barcode' => $itsOwnBarcode]);
    $response->assertOk();
}

public function test_barcode_over_100_chars_rejected(): void
{
    $response = $this->postJson("/api/v1/products/{$productId}/variants", [
        'variant_code' => 'X', 'sku' => 'X', 'name_suffix' => 'X', 'barcode' => str_repeat('9', 101),
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['barcode']);
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Add rules.** In both requests change `barcode`/`sku` `max:255`→`max:100` and add a tenant-scoped uniqueness rule. Because the unique index excludes soft-deleted rows, use a closure/`Rule::unique` with `whereNull('deleted_at')` and the tenant scope; on update, ignore the current id:

```php
use Illuminate\Validation\Rule;

// CreateVariantRequest:
'barcode' => ['sometimes', 'nullable', 'string', 'max:100',
    Rule::unique('product_variants', 'barcode')
        ->where(fn ($q) => $q->whereNull('deleted_at')->where('tenant_id', $this->user()->tenant_id))],

// UpdateVariantRequest (route param is the variant id):
'barcode' => ['sometimes', 'nullable', 'string', 'max:100',
    Rule::unique('product_variants', 'barcode')
        ->ignore($this->route('id'))
        ->where(fn ($q) => $q->whereNull('deleted_at')->where('tenant_id', $this->user()->tenant_id))],
```

(Also drop `sku` `max:255`→`max:100` in both.)

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(catalog): barcode/sku uniqueness 422 + max:100 in variant requests"`

---

### Task C2: Centralized 23505 mapper (race-safe, both paths)

**Depends on:** Task **A3** (creates `DuplicateBarcodeException` with `isViolationOf()` / `asValidation()` — reused here, not re-created).

**Files:**
- Modify: `ProductVariantService.php` (add a barcode-safe `createVariant` wrap + a new `updateVariant()` method), `ProductVariantController.php` (route `update` through the service so the same catch covers the update race)
- Test: `apps/api/tests/Feature/Catalog/VariantBarcodeRaceTest.php` (**PG** — relies on the real partial unique index)

- [ ] **Step 1: Failing test** — simulate the race by bypassing FormRequest (call the service/Eloquent path directly with a colliding barcode) and assert a `DuplicateBarcodeException` → 422 mapping, and that a `sku` collision does NOT map to a `barcode` error:

```php
public function test_db_barcode_violation_maps_to_422_not_500(): void
{
    // insert variant A with barcode 3401234 directly
    $response = $this->withoutMiddleware(/* validate-then-write race: feed dup past FormRequest */)
        ->patchJson("/api/v1/product-variants/{$variantB}", ['barcode' => '3401234']);
    $response->assertStatus(422)->assertJsonValidationErrors(['barcode']);
}

public function test_sku_violation_is_not_reported_as_barcode(): void
{
    // force a sku collision; expect a non-barcode error
    $response->assertStatus(422);
    $this->assertArrayNotHasKey('barcode', $response->json('errors'));
}
```

- [ ] **Step 2: Run — expect fail.** FAIL (raw 500 today).

- [ ] **Step 3: Add a tenant-scoped `updateVariant()` to the service and a barcode-safe save helper.** `DuplicateBarcodeException` already exists (Task A3). Add:

```php
use App\Shared\Domain\Exceptions\DuplicateBarcodeException;
use Illuminate\Database\QueryException;

/** @param array<string,mixed> $attributes */
public function updateVariant(ProductVariant $variant, array $attributes): ProductVariant
{
    $variant->fill($attributes);
    $this->saveBarcodeSafe($variant);          // maps 23505 -> 422
    $variant->loadMissing('attributeValues');

    return $variant;
}

private function saveBarcodeSafe(ProductVariant $variant): void
{
    try {
        $variant->save();
    } catch (QueryException $e) {
        if (DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_barcode_unique')) {
            $name = $this->barcodeConflictName($variant->tenant_id, (string) $variant->barcode, $variant->id); // best-effort, tenant-scoped, excludes soft-deleted + self; null when ambiguous
            throw DuplicateBarcodeException::asValidation(
                $name !== null
                    ? "Barcode already used by another variant ({$name})."
                    : 'Barcode already used by another variant.'
            );
        }
        throw $e; // sku / variant_code / default violations are NOT reported as barcode errors
    }
}
```

`isViolationOf()` (defined in A3) gates on SQLSTATE first (`errorInfo[0] === '23505'`) then matches the exact index name. The four `product_variants` index names are all < 63 bytes (verified against migration `…100003`: `product_variants_tenant_barcode_unique`=38, `…_tenant_sku_unique`, `…_product_id_variant_code_unique`=47, `…_default_unique`), so PG's 63-byte identifier truncation never applies. `barcodeConflictName()`: `ProductVariant::whereNull('deleted_at')->where('tenant_id',$t)->where('barcode',$b)->where('id','!=',$selfId)->value('name_suffix')` — returns null when absent/ambiguous (never throws).

Also wrap the `createVariant()` save in `saveBarcodeSafe()` (single-variant create path).

- [ ] **Step 4: Route the controller `update` through the service.** Replace the controller's `$variant->fill(...)->save()` with `$variant = $this->variantService->updateVariant($variant, $request->validated());` so the update race is covered by the same catch. `barcodeConflictName()`: tenant-scoped query `whereNull('deleted_at')->where('barcode', …)->where('id','!=',$selfId)->value('name_suffix')` — returns null when ambiguous/absent (never throws). Route the controller `update` through `variantService->updateVariant($id, $validated)` so the same catch covers updates.

- [ ] **Step 5: Run — expect pass.** PASS.

- [ ] **Step 6: PHPStan + Pint + commit**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog app/Shared && ./vendor/bin/pint app/Modules/Catalog app/Shared
git commit -am "feat(catalog): race-safe barcode 23505 -> 422 (create + update), best-effort conflict name"
```

---

## Milestone D — Backend: variant delete policy

### Task D1: `VariantStockReader` contract + Inventory implementation

**Files:**
- Create: `apps/api/app/Shared/Contracts/VariantStockReader.php`, `apps/api/app/Modules/Inventory/Application/Services/VariantStockReaderService.php`
- Modify: `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`
- Test: `apps/api/tests/Feature/Inventory/VariantStockReaderTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_returns_total_on_hand_quantity_for_variant(): void
{
    // two stock_levels for the variant: 3.0000 + 2.5000 across locations
    $reader = app(VariantStockReader::class);
    $this->assertSame('5.5000', $reader->variantOnHandQuantity($tenantId, $companyId, $variantId));
}

public function test_returns_zero_when_no_stock(): void
{
    // scale-4 string; bccomp in the delete guard treats '0.0000' as zero
    $this->assertSame('0.0000', app(VariantStockReader::class)->variantOnHandQuantity($tenantId, $companyId, $variantId));
}
```

- [ ] **Step 2: Run — expect fail.** FAIL (no binding).

- [ ] **Step 3: Create the contract**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Cross-module read of on-hand stock for a single variant.
 * Implemented by Inventory, consumed by Catalog's variant delete guard.
 */
interface VariantStockReader
{
    /** Total on-hand quantity (quantity-scale-4 numeric string) for the variant across all locations. */
    public function variantOnHandQuantity(string $tenantId, string $companyId, string $variantId): string;
}
```

- [ ] **Step 4: Implement in Inventory**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Contracts\VariantStockReader;
use App\Shared\Domain\QuantityScale;

final class VariantStockReaderService implements VariantStockReader
{
    public function variantOnHandQuantity(string $tenantId, string $companyId, string $variantId): string
    {
        $sum = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('variant_id', $variantId)
            ->sum('quantity');

        // Return a quantity-scale-4 string per the precision contract (mirrors
        // LocationStockQueryService). NEVER let a float represent quantity.
        return QuantityScale::round((string) $sum, 4, QuantityScale::FLOOR);
    }
}
```

- [ ] **Step 5: Bind it** in `InventoryServiceProvider::register()`:

```php
$this->app->bind(\App\Shared\Contracts\VariantStockReader::class, \App\Modules\Inventory\Application\Services\VariantStockReaderService::class);
```

- [ ] **Step 6: Run — expect pass.** PASS.

- [ ] **Step 7: Commit.** `git commit -am "feat(inventory): VariantStockReader contract for cross-module on-hand lookup"`

---

### Task D2: Delete guard in controller

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php` (inject `VariantStockReader`, guard `destroy`)
- Test: `apps/api/tests/Feature/Catalog/VariantDeletePolicyTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_delete_blocked_when_variant_has_on_hand_stock(): void
{
    // stock_levels row qty 4 for the variant
    $response = $this->deleteJson("/api/v1/product-variants/{$variantId}");
    $response->assertStatus(422);
    $this->assertNotNull(ProductVariant::find($variantId)); // not soft-deleted
}

public function test_delete_allowed_when_zero_stock(): void
{
    $response = $this->deleteJson("/api/v1/product-variants/{$variantId}");
    $response->assertNoContent();
    $this->assertSoftDeleted('product_variants', ['id' => $variantId]);
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Inject + guard.** Add `private readonly VariantStockReader $stockReader` to the constructor; in `destroy`, before `$variant->delete()`:

```php
if (bccomp($this->stockReader->variantOnHandQuantity($company->tenant_id, $company->id, $variant->id), '0', 4) === 1) {
    return response()->json([
        'message' => 'This variant has stock on hand. Deactivate it instead of deleting.',
    ], 422);
}
```

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(catalog): block variant delete when on-hand stock > 0 (steer to deactivate)"`

---

## Milestone E — Backend: onboarding step

### Task E1: `OnboardingStep::ProductOptions`

**Files:**
- Modify: `apps/api/app/Modules/Tenant/Domain/Enums/OnboardingStep.php`
- Test: `apps/api/tests/Unit/Tenant/OnboardingStepTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_product_options_step_is_optional_with_catalog_path(): void
{
    $step = OnboardingStep::ProductOptions;
    $this->assertFalse($step->isRequired());
    $this->assertSame('/catalog/attributes', $step->settingsPath());
    $this->assertSame('product_options', $step->value);
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Add the case** — add `case ProductOptions = 'product_options';` and arms in `isRequired()` (false), `label()` (`'Set up product options'`), `settingsPath()` (`'/catalog/attributes'`).

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(tenant): ProductOptions onboarding step (optional)"`

---

### Task E2: Checklist completion check

**Files:**
- Modify: `apps/api/app/Modules/Tenant/Application/Services/OnboardingChecklistService.php`
- Test: `apps/api/tests/Feature/Tenant/OnboardingProductOptionsTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_product_options_incomplete_without_variant_axis_attribute(): void
{
    $status = collect($service->getStatus($companyId))->firstWhere('step', 'product_options');
    $this->assertFalse($status['completed']);
}

public function test_product_options_complete_with_a_variant_axis_attribute(): void
{
    // create a ProductAttribute is_variant_axis = true
    $status = collect($service->getStatus($companyId))->firstWhere('step', 'product_options');
    $this->assertTrue($status['completed']);
}
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Add the match arm + check**

```php
use App\Modules\Catalog\Domain\Entities\ProductAttribute;

// in the match:
OnboardingStep::ProductOptions => $this->checkProductOptions(),

private function checkProductOptions(): bool
{
    // Attributes are tenant-scoped (no company_id); the per-request connection is the tenant DB.
    return ProductAttribute::query()->where('is_variant_axis', true)->exists();
}
```

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(tenant): complete product_options step when a variant-axis attribute exists"`

---

## Milestone F — Frontend: API client, hooks, types

### Task F1: `generateVariantMatrix` sends axes, reads meta

**Files:**
- Modify: `apps/web/src/features/catalog/api/variantApi.ts`
- Test: `apps/web/src/features/catalog/api/__tests__/variantApi.test.ts`

- [ ] **Step 1: Failing test** — assert it POSTs `{ axes }` and returns `{ data, meta }`:

```ts
it('posts axes and returns data + meta', async () => {
  const spy = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: [], meta: { created_count: 2, skipped_count: 0, restored_count: 0 } } })
  const res = await generateVariantMatrix('p1', [{ attribute_id: 'a1', value_ids: ['v1', 'v2'] }])
  expect(spy).toHaveBeenCalledWith('/products/p1/variants/generate-matrix', { axes: [{ attribute_id: 'a1', value_ids: ['v1', 'v2'] }] })
  expect(res.meta.created_count).toBe(2)
})
```

- [ ] **Step 2: Run — expect fail.** `cd apps/web && pnpm test variantApi` → FAIL.

- [ ] **Step 3: Implement** — add the axis + result types and switch to `api.post` (NOT `apiPost`, which strips `meta`):

```ts
import { api } from '@/lib/api'

export interface GenerateMatrixAxis {
  attribute_id: string
  value_ids: string[]
}

export interface GenerateMatrixResult {
  data: ProductVariant[]
  meta: { created_count: number; skipped_count: number; restored_count: number }
}

export async function generateVariantMatrix(
  productId: string,
  axes: GenerateMatrixAxis[],
): Promise<GenerateMatrixResult> {
  const response = await api.post(`/products/${productId}/variants/generate-matrix`, { axes })
  return response.data as GenerateMatrixResult
}
```

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(web): variantApi generateVariantMatrix sends axes, returns data+meta"`

---

### Task F2: `useGenerateMatrix` accepts axes + returns counts

**Files:**
- Modify: `apps/web/src/features/catalog/hooks/useVariants.ts`

- [ ] **Step 1:** Change the mutation input type to `GenerateMatrixAxis[]` and call `generateVariantMatrix(productId, axes)`. (No new test file — exercised via the component test in Milestone G.) Keep the `invalidateQueries` onSuccess.

```ts
export function useGenerateMatrix(productId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (axes: GenerateMatrixAxis[]) => generateVariantMatrix(productId, axes),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: variantKeys.forProduct(productId) })
    },
  })
}
```

- [ ] **Step 2: Typecheck.** `cd apps/web && pnpm typecheck` → no errors (will surface the call-site in the component, fixed in G1).

- [ ] **Step 3: Commit.** `git commit -am "feat(web): useGenerateMatrix takes axes"`

---

## Milestone G — Frontend: matrix editor rework

> All steps: design tokens only (`@/lib/designTokens`), all text via `t()`. Tests in `ProductVariantMatrixEditor.test.tsx` (vi.mock hooks per project convention).

### Task G1: Value-chip selection + live count + soft-warn + hard-cap

**Files:**
- Modify: `ProductVariantMatrixEditor.tsx`, its test.

- [ ] **Step 1: Failing tests**

```tsx
it('reveals value chips when an axis is checked and counts combos', async () => {
  // mock useAttributes -> [{id:'size',is_variant_axis:true,name:'Size'}], useAttributeValues('size') -> [v1,v2,v3]
  render(<ProductVariantMatrixEditor productId="p1" />)
  await userEvent.click(screen.getByLabelText('Size'))
  expect(screen.getAllByRole('checkbox', { name: /value/i })).toHaveLength(3)
  // deselect one -> count reflects 2
  expect(screen.getByText(/2 combinations/i)).toBeInTheDocument()
})

it('disables generate above the hard cap', async () => {
  // selection producing 201
  expect(screen.getByRole('button', { name: /generate/i })).toBeDisabled()
  expect(screen.getByText(/over the .*limit/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run — expect fail.** `pnpm test ProductVariantMatrixEditor` → FAIL.

- [ ] **Step 3: Implement.** Add a constant `export const VARIANT_COUNT_SOFT_WARN = 50` and `export const MAX_VARIANTS_PER_GENERATE = 200` (mirror backend). Replace `selectedAxes: string[]` with `selectedValues: Record<string /*attrId*/, string[] /*valueIds*/>`.

  **Rules of Hooks:** do NOT call `useAttributeValues(axisId)` in a `.map()` over checked axes. Extract a child component rendered once per checked axis:

  ```tsx
  function AxisValueChips({ attributeId, selectedValueIds, onToggleValue }: {
    attributeId: string
    selectedValueIds: string[]
    onToggleValue: (attributeId: string, valueId: string) => void
  }) {
    const { data: values } = useAttributeValues(attributeId) // one hook call, fixed position
    return (
      <div className="flex flex-wrap gap-2">
        {(values ?? []).map((v) => (
          <label key={v.id} className="inline-flex items-center gap-1">
            <input type="checkbox" className={tokens.checkbox.base}
              aria-label={`value ${v.label}`}
              checked={selectedValueIds.includes(v.id)}
              onChange={() => { onToggleValue(attributeId, v.id) }} />
            {v.hex_color ? <span style={{ backgroundColor: v.hex_color }} className="inline-block h-3 w-3 rounded-full" /> : null}
            <span className={`text-sm ${textColors.secondary}`}>{v.label}</span>
          </label>
        ))}
      </div>
    )
  }
  ```

  The parent renders `<AxisValueChips … />` for each **checked** axis (a stable list ⇒ stable hook order). Default a newly-checked axis to all values selected (seed `selectedValues[axisId]` when its values first load — inside the child via an effect, or eagerly when the axis is toggled on). Compute `comboCount = Object.values(selectedValues).reduce((n, ids) => n * ids.length, 1)` (0 axes ⇒ 0). Show count text; warn class when `>= VARIANT_COUNT_SOFT_WARN`; disable Generate when `> MAX_VARIANTS_PER_GENERATE`. On generate, build `axes: GenerateMatrixAxis[]` from `selectedValues` and call `generateMatrix.mutateAsync(axes)`; toast reads `meta` counts.

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(web): variant value-subset chips, live combo count, soft-warn + hard-cap"`

---

### Task G2: Hydrate selection from existing variants

**Files:** Modify `ProductVariantMatrixEditor.tsx`, its test.

- [ ] **Step 1: Failing test**

```tsx
it('pre-selects axes/values from existing variants attribute_values', () => {
  // useVariantsForProduct -> [{ id, attribute_values:[{attribute_id:'size',attribute_value_id:'v1'}, {...'color','red'}] }]
  render(<ProductVariantMatrixEditor productId="p1" />)
  expect(screen.getByLabelText('Size')).toBeChecked()
  // v1 chip checked, etc.
})
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** Derive an initial `selectedValues` from the union of `variants[].attribute_values` (group `attribute_value_id` by `attribute_id`). Seed it once when variants first load (guard with a ref like the existing `variantsToggleSeededRef` pattern in `ProductForm`), so server refetches don't clobber in-progress edits.

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(web): hydrate variant selection from existing variants"`

---

### Task G3: Orphan "not in current selection" badge

**Files:** Modify `ProductVariantMatrixEditor.tsx`, its test.

- [ ] **Step 1: Failing test**

```tsx
it('badges a variant whose values are not in the current selection', async () => {
  // variant has color:green; selection only has color:red
  render(<ProductVariantMatrixEditor productId="p1" />)
  expect(screen.getByText(/not in current selection/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** A variant is an orphan when any of its `attribute_values` pairs is not present in `selectedValues` (i.e. its combo ⊄ current selection). Render a badge in the variant row.

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(web): orphan badge for variants outside current selection"`

---

### Task G4: Inline barcode 422 on row save

**Files:** Modify `ProductVariantMatrixEditor.tsx`, its test.

- [ ] **Step 1: Failing test**

```tsx
it('shows an inline barcode error when save returns 422', async () => {
  // useUpdateVariant mutateAsync rejects with { response: { status: 422, data: { errors: { barcode: ['dup'] } } } }
  render(<ProductVariantMatrixEditor productId="p1" />)
  await userEvent.click(screen.getAllByRole('button', { name: /save/i })[0])
  expect(await screen.findByText('dup')).toBeInTheDocument()
})
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** Track `rowErrors: Record<variantId, string>`. In `handleSave`'s catch, read `error.response?.data?.errors?.barcode?.[0]` and set it for that row; render under the barcode input. Clear on successful save / further edits.

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Commit.** `git commit -am "feat(web): inline barcode 422 on variant row save"`

---

### Task G5: Delete confirm dialog + deactivate-on-422

**Files:** Modify `ProductVariantMatrixEditor.tsx`, its test.

- [ ] **Step 1: Failing tests**

```tsx
it('asks for confirmation before deleting', async () => {
  render(<ProductVariantMatrixEditor productId="p1" />)
  await userEvent.click(screen.getAllByRole('button', { name: /delete .* /i })[0])
  expect(screen.getByRole('dialog')).toBeInTheDocument()
  // cancel -> no delete call
  await userEvent.click(screen.getByRole('button', { name: /cancel/i }))
  expect(deleteMock).not.toHaveBeenCalled()
})

it('offers deactivate when delete returns 422', async () => {
  // deleteVariant rejects 422
  // confirm -> see deactivate option that calls updateVariant({is_active:false})
})
```

- [ ] **Step 2: Run — expect fail.** FAIL.

- [ ] **Step 3: Implement.** Add a fixed-size confirm dialog (per `feedback_modal_fixed_size` — fixed dimensions, no resize). Confirm calls `deleteVariant.mutateAsync(id)`; on 422, swap the dialog body to the "has stock — deactivate instead" message with a Deactivate button that calls `updateVariant.mutateAsync({ variantId: id, payload: { is_active: false } })`.

- [ ] **Step 4: Run — expect pass.** PASS.

- [ ] **Step 5: Typecheck + lint + commit**

```bash
cd apps/web && pnpm typecheck && pnpm lint
git commit -am "feat(web): variant delete confirm dialog + deactivate-on-422 path"
```

---

## Milestone H — i18n

### Task H1: Translation keys (en + fr)

**Files:**
- Modify: `apps/web/public/locales/{en,fr}/catalog.json`, `apps/web/public/locales/{en,fr}/onboarding.json`

- [ ] **Step 1:** Add every `t()` key introduced in Milestone G under the `catalog` namespace (e.g. `variants.selectValues`, `variants.combinationCount`, `variants.overLimit`, `variants.softWarn`, `variants.notInSelection`, `variants.confirmDeleteTitle`, `variants.confirmDeleteBody`, `variants.deactivateInstead`, `variants.deactivate`, `variants.cancel`, `variants.restored`) in both en and fr. Add `onboarding.steps.product_options` in both.

- [ ] **Step 2: Verify no missing keys.** Grep the component for `t('catalog:variants.` and confirm each key exists in both locale files.

- [ ] **Step 3: Run the full frontend gate for the catalog feature**

Run: `cd apps/web && pnpm test ProductVariantMatrixEditor variantApi && pnpm typecheck && pnpm lint`
Expected: all PASS.

- [ ] **Step 4: Commit.** `git commit -am "feat(web): i18n keys for variant authoring + onboarding step (en/fr)"`

---

## Final verification (whole feature)

- [ ] Backend (scoped, never full suite): `cd apps/api && php artisan test --filter='Catalog|Onboarding|VariantStock'` — run the PG-dependent ones against local PostgreSQL.
- [ ] `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog app/Modules/Tenant app/Modules/Inventory app/Shared && ./vendor/bin/pint --test app/Modules app/Shared`
- [ ] `cd apps/api && php artisan typescript:transform` (no uncommitted diff after).
- [ ] Frontend: `cd apps/web && pnpm test src/features/catalog && pnpm typecheck && pnpm lint`.
- [ ] Manual E2E (local): create a product → select Size {S,M} × Color {Black} → generate (2 created) → add White → regenerate (1 created, 1 skipped) → delete S/Black (confirm) → re-select S/Black & regenerate (1 restored) → set a duplicate barcode (inline 422) → give a variant stock, try to delete (422 → deactivate) → check onboarding shows "Set up product options" complete.

---

## Out of scope (do not implement)
Saved option-set templates; Spec C label/QR printing; soft-delete-on-sync; bulk delete endpoint; POS-side changes; per-variant stock editing in the matrix editor.
