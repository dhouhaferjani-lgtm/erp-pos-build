# TypeScript Types Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make AutoERP's PHP→TypeScript type generation pipeline actually usable, fix the `DataCollection` element-type regression, standardize the case convention, and prove value with a reference module migration plus a CI drift guard.

**Architecture:** Six phases, executed sequentially on a dedicated branch `feat/types-pipeline-overhaul` off `dev`. Phase A fixes plumbing so generated types are discoverable. Phase B patches the transformer so `DataCollection<T>` fields emit `T[]` instead of `Array<any>`. Phase C locks in snake_case on both sides and migrates the four Identity DTOs (the only camelCase outliers). Phase D migrates a reference module (Accounting — coordinated with `fix/test-suite-baseline-remediation`, or Inventory as fallback) from hand-written to generated types. Phase E adds a preflight drift guard. Phase F writes the ADR and convention doc.

**Tech Stack:** Laravel 12 / PHP 8.2+ / spatie/laravel-typescript-transformer v2.5 / spatie/laravel-data v4.18 / React 19 / Vite 7 / Vitest 3 / TypeScript 5.6 strict.

---

## Baseline facts (verified 2026-04-19 on `dev`)

| Fact | Value |
|---|---|
| Tagged DTOs + enums with `#[TypeScript]` | 73 (67 DTOs + 6 enum groups per shape; audit-confirmed) |
| `packages/shared/types/generated.ts` | 617 lines, 27 `declare namespace` blocks, 114 `export type` statements |
| `apps/web` files importing from `@autoerp/shared` | 0 |
| `apps/web` files using ambient `App.Modules.*` or `App.Enums.*` | 0 |
| `Array<any>` occurrences in `generated.ts` | **34** across at least 8 modules (Document, Identity, Loyalty, Product, UnitCategory, Billing, Accounting, Core) — the collection-lowering bug is live everywhere, not only in Accounting reports |
| Root cause of `Array<any>` | Config uses the generic `Spatie\TypeScriptTransformer\Transformers\DtoTransformer`. The Laravel-Data-aware transformer `Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer` (shipped in `spatie/laravel-data`) already resolves `DataCollection<T>` → `Array<T>` correctly — we just aren't using it |
| DTOs emitting camelCase properties on the wire | 4 (all in `Identity`: `UserData`, `AuthUserData`, `LoginData`, `LoginResponseData`) |
| DTOs emitting snake_case (target convention) | 69 |
| Type-name collisions if flattened with `ModuleWriter` | `PaymentStatus` (×3), `TransactionType` (×2) — `ModuleWriter` is NOT safe |
| NameMappers / `#[MapName]` / `config/data.php` overrides in repo | 0 — no coherent case policy enforced today |
| Axios interceptor for case conversion | None in `apps/web/src/lib/api.ts` |
| `@autoerp/shared/package.json → types` field | Present (`./types/index.ts`) — the session doc's claim that it's missing is stale |
| `packages/shared/types/index.ts` content | `export {};` only — does NOT re-export `generated.ts` |
| `apps/web/src/vite-env.d.ts` reference | `/// <reference types="@autoerp/shared/types/generated" />` — resolves but namespace is module-local due to `moduleDetection: "force"` |

**Interpretation:** The generated file is syntactically fine but structurally unreachable. The only case-policy outlier is Identity (4 DTOs). The `DataCollection` bug is live, not latent — 34 properties across the Document, Identity, Loyalty, Product, and UnitCategory DTOs already emit `Array<any>` on `dev`. A `ModuleWriter` swap would break on naming collisions, so we need a custom writer that preserves the nested namespace hierarchy while making it globally visible. Note that Accounting's report DTOs (`AgedReceivablesData`, `BalanceSheetData`, etc.) use `#[DataCollectionOf]` but are NOT yet tagged with `#[TypeScript]` on `dev` — the `fix/test-suite-baseline-remediation` branch tags them; when it lands, the fix from Phase B applies to those too without further work.

---

## Decision Record (commit as `docs/adr/2026-04-19-typescript-types-pipeline.md` in Phase F)

| Decision | Choice | Why |
|---|---|---|
| Writer strategy | Custom `GlobalNamespaceWriter` wrapping the default `TypeDefinitionWriter` output in `declare global { … } export {};`, output to `generated.d.ts` | Preserves nested `App.Modules.<Module>.…` hierarchy → no collisions. `declare global { … }` lifts the whole tree to the ambient global scope. Terminal `export {};` makes the file a module so `moduleDetection: "force"` is satisfied. Works whether or not consumers explicitly import. |
| Case convention | **Policy 1 — snake_case on the wire, snake_case in TypeScript** | 69/73 DTOs already snake_case. Every hand-written frontend `types.ts` already snake_case. Only 4 DTOs need migration. Policies 2 and 3 would require migrating dozens of DTOs or all frontend types — 10–50× the churn for no safety gain. |
| `DataCollection` fix | Swap `Spatie\TypeScriptTransformer\Transformers\DtoTransformer` → `Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer` in `config/typescript-transformer.php` | One-line fix. The Data-aware transformer (already vendored with `spatie/laravel-data`) reads `#[DataCollectionOf]` via the same `DataConfig` pipeline `spatie/laravel-data` uses at runtime, so emitted types stay in lock-step with actual JSON output. No custom code. |
| Reference migration target | **Inventory module** — migrate the inline `StockLevel` shadow in `apps/web/src/features/inventory/StockLevelsPage.tsx` to re-export from `App.Modules.Inventory.Application.DTOs.StockLevelData` | `StockLevelData` is already `#[TypeScript]`-tagged on `dev`. The shadow is a single interface inside one page — small, visible, end-to-end. Accounting is explicitly off-limits because `features/finance/types.ts` is being rewritten on `fix/test-suite-baseline-remediation`. Catalog is a reasonable secondary migration target (7 tagged DTOs, `features/catalog/types.ts` shadow) if more surface is desired after the Inventory reference lands. |
| CI enforcement | `scripts/preflight.sh` reruns `php artisan typescript:transform` into a temp file and `diff`s against the committed `generated.d.ts`; fail on diff | Zero new infrastructure. Surface drift at PR time, not production. |

---

## Pre-flight checks before starting

- [ ] **Step 0: Create isolated branch**

Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git status  # confirm only .playwright-mcp artifacts are untracked
git checkout -b feat/types-pipeline-overhaul dev
```

Expected: branch created from `dev` HEAD `44e5d546`.

- [ ] **Step 0.2: Confirm remediation branch state**

Run:

```bash
git log --oneline -5 origin/fix/test-suite-baseline-remediation
```

Record the remediation branch status for awareness. Phase D of this plan targets Inventory regardless (finance files are explicitly off-limits), but knowing whether the remediation branch has landed informs which generated types will be present at Phase B verification time.

- [ ] **Step 0.3: Baseline generated file in place**

Run:

```bash
cd apps/api && php artisan typescript:transform
cd ../..
git diff --stat packages/shared/types/generated.ts
```

Expected: either no diff (file matches the committed state) or a small regen diff. Record the current byte-exact state of `generated.ts` so later phases have a clear reference point.

---

## Phase A — Pipeline plumbing (make generated types reachable)

**Goal:** After this phase, a `.ts` file in `apps/web/src/` can reference `App.Modules.Accounting.Domain.Enums.AccountType` with no import and TypeScript resolves it, AND `pnpm typecheck` passes.

### Task A.1: Write the failing typecheck canary

**Files:**
- Create: `apps/web/src/test/__fixtures__/types-canary.ts`

- [ ] **Step 1: Write the canary file**

Create `apps/web/src/test/__fixtures__/types-canary.ts`:

```ts
// Canary that proves the generated namespace is reachable from apps/web.
// If this file stops type-checking, the TypeScript pipeline has regressed.

const accountType: App.Modules.Accounting.Domain.Enums.AccountType = 'asset'
const invoiceStatus: App.Modules.Billing.Domain.Enums.InvoiceStatus = 'paid'
const vertical: App.Enums.Vertical = 'pharmacy'

// Force the compiler to keep the references (strict unused locals).
export const __typesCanary = { accountType, invoiceStatus, vertical } as const
```

- [ ] **Step 2: Run typecheck to verify it fails**

Run: `cd apps/web && pnpm typecheck`
Expected: FAIL with `TS2503: Cannot find namespace 'App'.` (or equivalent) on lines 4–6 of the canary.

Record the exact error output verbatim in your scratch notes — you'll compare after Task A.6.

### Task A.2: Inspect the existing transformer writer signature

**Files:**
- Read-only: `apps/api/vendor/spatie/typescript-transformer/src/Writers/TypeDefinitionWriter.php`
- Read-only: `apps/api/vendor/spatie/typescript-transformer/src/Writers/Writer.php`

- [ ] **Step 1: Confirm the writer interface**

Run: `cat apps/api/vendor/spatie/typescript-transformer/src/Writers/Writer.php`
Expected: interface with `format(TypesCollection $collection): string` and `replacesSymbolsWithFullyQualifiedIdentifiers(): bool`.

- [ ] **Step 2: Confirm TypeDefinitionWriter produces nested `declare namespace`**

Run: `head -50 apps/api/vendor/spatie/typescript-transformer/src/Writers/TypeDefinitionWriter.php`
Expected: `format()` groups types by namespace path and emits `declare namespace {path} { … }`.

No edits this task — this is a signature-confirmation checkpoint before we subclass.

### Task A.3: Write a custom `GlobalNamespaceWriter`

**Files:**
- Create: `apps/api/app/Shared/TypeScript/GlobalNamespaceWriter.php`
- Create: `apps/api/tests/Unit/Shared/TypeScript/GlobalNamespaceWriterTest.php`

- [ ] **Step 1: Write the failing PHPUnit test**

Create `apps/api/tests/Unit/Shared/TypeScript/GlobalNamespaceWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\TypeScript;

use App\Shared\TypeScript\GlobalNamespaceWriter;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Structures\TypesCollection;
use Spatie\TypeScriptTransformer\TypeReflectors\ClassTypeReflector;

final class GlobalNamespaceWriterTest extends TestCase
{
    public function test_it_wraps_emitted_namespaces_in_declare_global_block(): void
    {
        $collection = TypesCollection::create();
        $collection->add(TransformedType::create(
            new \ReflectionClass(\stdClass::class),
            'Foo',
            "export type Foo = { bar: string };",
        )->withNamespace('App\\Modules\\Demo'));

        $writer = new GlobalNamespaceWriter();
        $output = $writer->format($collection);

        $this->assertStringStartsWith("declare global {\n", $output);
        $this->assertStringContainsString("declare namespace App.Modules.Demo {", $output);
        $this->assertStringContainsString('export type Foo', $output);
        $this->assertStringEndsWith("}\n\nexport {};\n", $output);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit --filter GlobalNamespaceWriterTest`
Expected: FAIL — class `App\Shared\TypeScript\GlobalNamespaceWriter` does not exist.

- [ ] **Step 3: Implement `GlobalNamespaceWriter`**

Create `apps/api/app/Shared/TypeScript/GlobalNamespaceWriter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\TypeScript;

use Spatie\TypeScriptTransformer\Structures\TypesCollection;
use Spatie\TypeScriptTransformer\Writers\TypeDefinitionWriter;

/**
 * Emits the nested `declare namespace App.Modules.{…}` hierarchy produced
 * by TypeDefinitionWriter but wraps the whole file in `declare global { … }`
 * so the namespaces are visible globally from every TypeScript module.
 *
 * A trailing `export {};` turns the file into a module so that
 * `moduleDetection: "force"` (set in apps/web/tsconfig.json) is satisfied
 * without shadowing the global declaration.
 */
final class GlobalNamespaceWriter extends TypeDefinitionWriter
{
    public function format(TypesCollection $collection): string
    {
        $inner = parent::format($collection);

        return "declare global {\n".$inner."\n}\n\nexport {};\n";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit --filter GlobalNamespaceWriterTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/TypeScript/GlobalNamespaceWriter.php \
        apps/api/tests/Unit/Shared/TypeScript/GlobalNamespaceWriterTest.php
git commit -m "feat(types): add GlobalNamespaceWriter that wraps output in declare global"
```

### Task A.4: Point the transformer config at the new writer, rename output to `.d.ts`

**Files:**
- Modify: `apps/api/config/typescript-transformer.php`

- [ ] **Step 1: Update the config**

In `apps/api/config/typescript-transformer.php`, change `output_file` and add a `writer` key:

```php
'output_file' => base_path('../../packages/shared/types/generated.d.ts'),

'writer' => App\Shared\TypeScript\GlobalNamespaceWriter::class,
```

Keep all other keys unchanged. The final file becomes:

```php
<?php

declare(strict_types=1);

return [
    'auto_discover_transformers' => [
        app_path('Modules'),
        app_path('Shared'),
    ],

    'transformers' => [
        Spatie\TypeScriptTransformer\Transformers\EnumTransformer::class,
        Spatie\TypeScriptTransformer\Transformers\DtoTransformer::class,
    ],

    'collectors' => [
        Spatie\TypeScriptTransformer\Collectors\DefaultCollector::class,
        Spatie\TypeScriptTransformer\Collectors\EnumCollector::class,
    ],

    'output_file' => base_path('../../packages/shared/types/generated.d.ts'),

    'writer' => App\Shared\TypeScript\GlobalNamespaceWriter::class,

    'default_type_replacements' => [
        DateTime::class => 'string',
        DateTimeImmutable::class => 'string',
        Carbon\Carbon::class => 'string',
        Carbon\CarbonImmutable::class => 'string',
        Illuminate\Support\Carbon::class => 'string',
    ],
];
```

- [ ] **Step 2: Regenerate**

Run:

```bash
cd apps/api
php artisan typescript:transform
```

Expected: writes `packages/shared/types/generated.d.ts`. The old `packages/shared/types/generated.ts` is NOT automatically removed; delete it in the next step.

- [ ] **Step 3: Remove the old `.ts` output, confirm the `.d.ts` shape**

Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git rm packages/shared/types/generated.ts
head -5 packages/shared/types/generated.d.ts
tail -5 packages/shared/types/generated.d.ts
```

Expected start: `declare global {\ndeclare namespace App.Enums {`
Expected end: `}\n}\n\nexport {};`

### Task A.5: Update the workspace package to re-export + drop the stale reference

**Files:**
- Modify: `packages/shared/types/index.ts`
- Modify: `apps/web/src/vite-env.d.ts`

- [ ] **Step 1: Update `packages/shared/types/index.ts` to pull in the global**

Replace contents with:

```ts
/**
 * AutoERP Shared Types
 *
 * DO NOT edit — auto-generated by `php artisan typescript:transform`
 * (see CLAUDE.md rule #7 "Types flow from backend").
 *
 * Side-effect import registers the `declare global { … }` namespaces in
 * `generated.d.ts`. Import this package anywhere in apps/web to make
 * `App.Modules.*` and `App.Enums.*` visible to the compiler.
 */
import './generated'

export {}
```

- [ ] **Step 2: Replace the stale triple-slash reference with a path-based one**

Edit `apps/web/src/vite-env.d.ts` to:

```ts
/// <reference types="vite/client" />
/// <reference types="vitest/globals" />
/// <reference path="../../../packages/shared/types/generated.d.ts" />
```

Rationale: `reference types="@autoerp/shared/types/generated"` hits npm-style types resolution that doesn't respect our `exports` map. A relative `path` reference is deterministic.

### Task A.6: Verify the canary now typechecks

- [ ] **Step 1: Re-run typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS — 0 errors. The canary references `App.Modules.*` without any import and resolves.

- [ ] **Step 2: Run lint to make sure the new canary doesn't trip unused-variable rules**

Run: `cd apps/web && pnpm lint --max-warnings=0 src/test/__fixtures__/types-canary.ts`
Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git add apps/api/config/typescript-transformer.php \
        packages/shared/types/generated.d.ts \
        packages/shared/types/index.ts \
        apps/web/src/vite-env.d.ts \
        apps/web/src/test/__fixtures__/types-canary.ts
git commit -m "feat(types): switch generator to GlobalNamespaceWriter, emit to .d.ts, add canary"
```

---

## Phase B — `DataCollection<T>` element-type fix (swap to Data-aware transformer)

**Goal:** After this phase, every `DataCollection<T>` or `DataCollectionOf(T::class)` property in a tagged DTO emits `Array<T>` instead of `Array<any>`, and Laravel Data's full property-resolution pipeline (nullable flattening, lazy stripping, paginator handling) is in effect.

**Root cause:** `config/typescript-transformer.php` currently registers `Spatie\TypeScriptTransformer\Transformers\DtoTransformer` — the upstream, non-Laravel-Data-aware transformer. `spatie/laravel-data` ships `Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer` which extends the Laravel wrapper and already implements `DataCollection<T>` resolution via `$dataProperty->type->dataClass` (see vendor source lines 108–131). We just need to point the config at it.

### Task B.1: Establish the baseline

- [ ] **Step 1: Count current `Array<any>` occurrences**

Run: `grep -c "Array<any>" packages/shared/types/generated.d.ts`
Expected: **34** (or current baseline — record the exact number).

- [ ] **Step 2: Enumerate the affected DTOs**

Run: `grep -B1 "Array<any>" packages/shared/types/generated.d.ts | grep "^export type" | sort -u`

Record every DTO that has at least one `Array<any>` property — these are your validation targets. Current list includes at minimum:
`DocumentData`, `VehicleContextData`, `AuthUserData`, `UserData`, `LoginData`, `TierBenefitsData`, `TierData`, `ProgramData`, `EarningRuleData`, `QualifyingItemsData`, `RedemptionRuleData`, `PointsTransactionData`, `CategoryData`, `CertificationData`, `ClaimData`, `ProductData`, `UnitCategoryData`.

### Task B.2: Write the failing fixture-based test

**Files:**
- Create: `apps/api/tests/Fixtures/TypeScript/ChildData.php`
- Create: `apps/api/tests/Fixtures/TypeScript/ParentData.php`
- Create: `apps/api/tests/Feature/Shared/TypeScript/DataCollectionLoweringTest.php`

- [ ] **Step 1: Create the fixture DTOs**

Create `apps/api/tests/Fixtures/TypeScript/ChildData.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures\TypeScript;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ChildData extends Data
{
    public function __construct(
        public readonly string $value,
    ) {}
}
```

Create `apps/api/tests/Fixtures/TypeScript/ParentData.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures\TypeScript;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ParentData extends Data
{
    public function __construct(
        public readonly string $label,
        /** @var DataCollection<int, ChildData> */
        #[DataCollectionOf(ChildData::class)]
        public readonly DataCollection|array $children,
    ) {}
}
```

- [ ] **Step 2: Write the failing feature test**

Create `apps/api/tests/Feature/Shared/TypeScript/DataCollectionLoweringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\TypeScript;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * End-to-end guard: running the transform must resolve DataCollection
 * element types via #[DataCollectionOf], not lower to Array<any>.
 */
final class DataCollectionLoweringTest extends TestCase
{
    public function test_parent_data_fixture_emits_typed_children(): void
    {
        Artisan::call('typescript:transform');

        $generated = file_get_contents(
            base_path('../../packages/shared/types/generated.d.ts')
        );

        self::assertIsString($generated);
        self::assertStringContainsString(
            'export type ParentData = ',
            $generated,
            'ParentData fixture must be picked up by the transformer'
        );

        // The key assertion: children must reference ChildData explicitly.
        self::assertMatchesRegularExpression(
            '/ParentData\s*=\s*\{[^}]*children:\s*Array<[^>]*ChildData>/s',
            $generated,
            'children must emit Array<ChildData>, not Array<any>'
        );
    }

    public function test_whole_generated_file_has_no_untyped_arrays(): void
    {
        Artisan::call('typescript:transform');

        $generated = file_get_contents(
            base_path('../../packages/shared/types/generated.d.ts')
        );

        // Find every Array<any> with 40 chars of leading context for debugging.
        preg_match_all('/(.{0,40}Array<any>)/', (string) $generated, $matches);

        self::assertSame(
            [],
            $matches[1] ?? [],
            "Found Array<any> in generated types — these DataCollection fields are losing their element type:\n"
                . implode("\n", $matches[1] ?? [])
        );
    }
}
```

**Note on scope:** the second test asserts the WHOLE file has zero `Array<any>`. If a production DTO has a legitimately untyped `array` property (e.g. a free-form `metadata: array` with no `#[DataCollectionOf]` and no PHPDoc), swapping transformers won't fix it — the fix is to annotate the DTO. That's acceptable scope; those are real drift points.

Register the fixture namespace in the transformer's auto-discover paths so the test's ParentData/ChildData are collected. Add to `apps/api/config/typescript-transformer.php` → `auto_discover_transformers`:

```php
'auto_discover_transformers' => [
    app_path('Modules'),
    app_path('Shared'),
    base_path('tests/Fixtures/TypeScript'),  // phpunit fixtures
],
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit --filter DataCollectionLoweringTest`
Expected: BOTH tests FAIL. The first fails because `ParentData.children` emits `Array<any>`. The second fails with a list of existing production DTO offenders (at least 34 matches).

### Task B.3: Swap the transformer in config

**Files:**
- Modify: `apps/api/config/typescript-transformer.php`

- [ ] **Step 1: Replace `DtoTransformer` with the Data-aware transformer**

Edit `apps/api/config/typescript-transformer.php` transformers array:

```php
'transformers' => [
    Spatie\TypeScriptTransformer\Transformers\EnumTransformer::class,
    Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer::class,
],
```

Keep `EnumTransformer` first; the Data-aware transformer only handles classes extending `Spatie\LaravelData\Contracts\BaseData`, so enums still need the dedicated enum transformer.

- [ ] **Step 2: Regenerate**

Run: `cd apps/api && php artisan typescript:transform`

- [ ] **Step 3: Recount `Array<any>`**

Run: `grep -c "Array<any>" packages/shared/types/generated.d.ts`
Expected: significantly lower than 34. Any remaining occurrences indicate DTOs with untyped `array` properties that would need a PHPDoc or `#[DataCollectionOf]` to annotate — record those as a follow-up task.

- [ ] **Step 4: Re-run the test**

Run: `cd apps/api && ./vendor/bin/phpunit --filter DataCollectionLoweringTest`

Expected:
- `test_parent_data_fixture_emits_typed_children` → PASS.
- `test_whole_generated_file_has_no_untyped_arrays` → may still have residual hits from genuinely untyped `array` properties. If any residuals exist, inspect each in turn and either (a) annotate the DTO with proper types (e.g. `@var string[]` PHPDoc or `#[DataCollectionOf]`), or (b) if the property is genuinely free-form metadata, remove it from the test scope by narrowing the assertion to a specific list of known-good DTOs.

Iterate until both tests pass. Do NOT mark this task complete until the second test passes.

### Task B.4: Annotate residual untyped array properties

For each DTO still emitting `Array<any>` after the transformer swap, the PHP type is a bare `array` with no generics. Two fixes:

1. **Element type is known** (e.g. `public array $oem_numbers` is a list of strings): add PHPDoc `/** @var string[] */` above the property AND make sure the transformer's type-processor stack reads it (the default does).
2. **Element type is genuinely heterogeneous** (e.g. `public array $metadata` is an untyped bag): change the property type to `public array|object $metadata` and add `@var array<string, mixed>` — the transformer emits `Record<string, unknown>` which is honest.

- [ ] **Step 1: Enumerate residuals**

Run: `grep -B5 "Array<any>" packages/shared/types/generated.d.ts | grep "export type\|Array<any>"`

Group by DTO. For each, open the PHP source and assess which bucket it falls into.

- [ ] **Step 2: Fix case-by-case**

For each residual property, apply the appropriate annotation. Regenerate and re-check after each batch.

- [ ] **Step 3: Re-run Phase B tests**

Run: `cd apps/api && ./vendor/bin/phpunit --filter DataCollectionLoweringTest`
Expected: both tests PASS.

- [ ] **Step 4: Frontend typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS. If it fails, the canary fixture (`types-canary.ts`) references a type whose name changed — update the canary. If any feature file fails because a generated type is now stricter (e.g. `oem_numbers: string[]` instead of `Array<any>`), leave those failures for the responsible phase (Phase D covers the reference module; other modules migrate incrementally).

If non-reference frontend files break because they were silently depending on `Array<any>` behavior, **do not fix them in this phase** — that's Phase D's scope or a follow-up. Instead, leave the canary passing and note the broken consumers in the PR description. If the typecheck failure count blocks further work, add the offending files to a temporary `skipLibCheck` or re-export the problematic type as `unknown[]` in the feature's shadow file.

- [ ] **Step 5: Commit**

```bash
git add apps/api/config/typescript-transformer.php \
        apps/api/tests/Fixtures/TypeScript/ChildData.php \
        apps/api/tests/Fixtures/TypeScript/ParentData.php \
        apps/api/tests/Feature/Shared/TypeScript/DataCollectionLoweringTest.php \
        packages/shared/types/generated.d.ts
# plus any DTOs touched in B.4 Step 2
git commit -m "feat(types): use Data-aware transformer so DataCollection<T> emits Array<T>"
```

---

## Phase C — Case convention: snake_case everywhere

**Goal:** After this phase, every emitted property in `generated.d.ts` uses snake_case, and a regression test guards against future camelCase drift.

### Task C.1: Write the regression test

**Files:**
- Create: `apps/api/tests/Feature/Shared/TypeScript/CaseConventionTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Feature/Shared/TypeScript/CaseConventionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\TypeScript;

use Tests\TestCase;

/**
 * Enforces case policy: every #[TypeScript]-tagged Data DTO must declare
 * public properties in snake_case, so that the serialized JSON wire format
 * matches the generated TypeScript types.
 *
 * Policy decision: 2026-04-19-typescript-types-pipeline ADR (Policy 1).
 */
final class CaseConventionTest extends TestCase
{
    public function test_every_tagged_dto_uses_snake_case_properties(): void
    {
        $offenders = [];

        $finder = (new \Symfony\Component\Finder\Finder())
            ->files()
            ->in([app_path('Modules'), app_path('Shared')])
            ->name('*.php');

        foreach ($finder as $file) {
            $contents = $file->getContents();
            if (! str_contains($contents, '#[TypeScript]')) {
                continue;
            }

            $class = $this->resolveClassFromFile($file->getRealPath());
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isEnum()) {
                continue;
            }

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $name = $property->getName();
                if (preg_match('/[A-Z]/', $name) === 1) {
                    $offenders[] = "{$class}::\${$name}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "The following tagged DTO properties are camelCase and must be renamed to snake_case:\n"
                . implode("\n", $offenders)
        );
    }

    private function resolveClassFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        if (preg_match('/namespace\s+([^;]+);/', $contents, $ns) !== 1) {
            return null;
        }
        if (preg_match('/(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $contents, $cls) !== 1) {
            return null;
        }

        return trim($ns[1]).'\\'.$cls[1];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit --filter CaseConventionTest`
Expected: FAIL with offenders listed — at minimum:

```
App\Modules\Identity\Application\DTOs\UserData::$emailVerifiedAt
App\Modules\Identity\Application\DTOs\UserData::$lastLoginAt
App\Modules\Identity\Application\DTOs\UserData::$lastLoginIp
App\Modules\Identity\Application\DTOs\UserData::$createdAt
App\Modules\Identity\Application\DTOs\UserData::$updatedAt
App\Modules\Identity\Application\DTOs\UserData::$canDiscount
App\Modules\Identity\Application\DTOs\UserData::$maxDiscountPercent
… and siblings in AuthUserData, LoginData, LoginResponseData
```

Record the full offender list — this drives Task C.2.

### Task C.2: Migrate Identity DTOs to snake_case

**Files:**
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/UserData.php`
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php`
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/LoginData.php`
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/LoginResponseData.php`
- Modify: every `apps/web/src/**` file that reads the camelCase field names

- [ ] **Step 1: Enumerate frontend consumers of the offender properties**

For each offender property (from Task C.1 output), grep `apps/web/src/` to list every read:

```bash
for field in emailVerifiedAt lastLoginAt lastLoginIp createdAt updatedAt canDiscount maxDiscountPercent tenantId ; do
  echo "=== $field ===" ; grep -rn "\\.$field\\b\\|\\['$field'\\]\\|\\[\"$field\"\\]" apps/web/src/ 2>/dev/null
done
```

Record every file and line number. Group by frontend feature (auth, users, identity).

- [ ] **Step 2: Rename DTO properties in each Identity DTO**

In `apps/api/app/Modules/Identity/Application/DTOs/UserData.php`, change constructor parameters:

```php
public ?string $email_verified_at,
public ?string $last_login_at,
public ?string $last_login_ip,
public string $created_at,
public string $updated_at,
public ?bool $can_discount,
public ?float $max_discount_percent,
public string $tenant_id,
```

And update the `fromUser()` factory to assign to the renamed fields (the mapped Eloquent accessors `$user->email_verified_at` already return snake_case values, so the assignments simplify).

Repeat for `AuthUserData.php`, `LoginData.php`, `LoginResponseData.php` — rename every camelCase property to its snake_case equivalent. If any property name has no obvious snake_case equivalent (e.g. `Url` → `url`), use unit-tested intuition: `twoFactorEnabled` → `two_factor_enabled`, `avatarUrl` → `avatar_url`.

- [ ] **Step 3: Update frontend consumers**

For every file identified in Step 1, replace camelCase reads with snake_case. Example for `apps/web/src/features/auth/authStore.ts`:

```ts
// before
if (user.emailVerifiedAt == null) { /* … */ }

// after
if (user.email_verified_at == null) { /* … */ }
```

If a file renames a prop locally (e.g. `const { emailVerifiedAt } = user`), preserve the local alias but source from the renamed property:

```ts
const { email_verified_at: emailVerifiedAt } = user
```

- [ ] **Step 4: Run the case-convention test**

Run: `cd apps/api && ./vendor/bin/phpunit --filter CaseConventionTest`
Expected: PASS (empty offender list).

- [ ] **Step 5: Regenerate types + typecheck frontend**

Run:

```bash
cd apps/api && php artisan typescript:transform
cd ../web && pnpm typecheck
```

Expected: PASS. Any typecheck failures indicate a missed camelCase frontend reference — go back to Step 3 and fix.

- [ ] **Step 6: Run backend + frontend test suites**

Run:

```bash
cd apps/api && composer test -- --filter Identity
cd ../web && pnpm test --filter auth --filter users
```

Expected: PASS. Identity-specific tests are most likely to break.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Identity/Application/DTOs/ \
        apps/web/src/features/auth/ apps/web/src/features/users/ \
        packages/shared/types/generated.d.ts \
        apps/api/tests/Feature/Shared/TypeScript/CaseConventionTest.php
# (add any other touched apps/web files)
git commit -m "refactor(identity): migrate DTOs to snake_case per case convention ADR"
```

### Task C.3: Add PHPStan/Pint guard against future camelCase in DTOs (optional hardening)

Only do this task if the project already has a PHPStan extension surface or a Pint ruleset that inspects property names. Otherwise, skip — the unit test from C.1 is the primary enforcement.

- [ ] **Step 1: Check for PHPStan custom rules directory**

Run: `ls apps/api/tools/phpstan 2>/dev/null || ls apps/api/phpstan-rules 2>/dev/null`

If nothing exists, skip this task and note the decision in the ADR (Phase F) — the unit test alone is sufficient.

---

## Phase D — Reference migration: Inventory `StockLevel`

**Goal:** Prove the pipeline works end-to-end by migrating the `StockLevelsPage` from its inline `StockLevel` shadow interface to the generated `App.Modules.Inventory.Application.DTOs.StockLevelData`. Tight, high-signal, one-page surface.

### Task D.1: Capture the current shadow

**Files:**
- Read-only: `apps/web/src/features/inventory/StockLevelsPage.tsx`

- [ ] **Step 1: Read the existing shadow**

Run: `sed -n '15,30p' apps/web/src/features/inventory/StockLevelsPage.tsx`

Record the current shadow — at present:

```ts
interface StockLevel {
  // …fields…
}

interface StockLevelsResponse {
  data: StockLevel[]
  meta: { total: number }
}
```

Note every field on `StockLevel` — you'll diff these against the generated type.

- [ ] **Step 2: Inspect the generated type**

Run: `grep -A20 "StockLevelData = " packages/shared/types/generated.d.ts`

Record the generated shape. Diff against the shadow. Any field that exists on the shadow but not on the generated type is either:
(a) a field the backend doesn't emit — consumer should stop reading it (real drift we're about to fix), OR
(b) a frontend-only display field (should move to a separate display type).

### Task D.2: Create `features/inventory/types.ts`

**Files:**
- Create: `apps/web/src/features/inventory/types.ts`

- [ ] **Step 1: Add the types module**

Create `apps/web/src/features/inventory/types.ts`:

```ts
/**
 * Inventory feature types — re-exports from generated backend DTOs.
 *
 * DO NOT add hand-written domain types here. If a new field is needed
 * on the wire, add it to the PHP DTO and regenerate:
 *   cd apps/api && php artisan typescript:transform
 *
 * Frontend-only shapes (form state, UI state) ARE allowed here but
 * must NOT re-declare a backend DTO.
 */

export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData

export interface StockLevelsResponse {
  data: StockLevel[]
  meta: { total: number }
}
```

### Task D.3: Migrate `StockLevelsPage`

**Files:**
- Modify: `apps/web/src/features/inventory/StockLevelsPage.tsx`

- [ ] **Step 1: Remove the inline `interface StockLevel` and `StockLevelsResponse` blocks**

In `apps/web/src/features/inventory/StockLevelsPage.tsx`, delete lines 15–30 (the two inline interfaces) and add an import near the other imports at the top of the file:

```ts
import type { StockLevel, StockLevelsResponse } from './types'
```

- [ ] **Step 2: Typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS. If it fails, the shadow had a field the generated type doesn't emit — two possibilities:

(a) The field should come from the backend → tag the missing PHP DTO property with an annotation (not in this phase; note it as follow-up).
(b) The field is display-only → move it to a separate `StockLevelDisplay` type in `features/inventory/types.ts` and update the page's local variable types.

- [ ] **Step 3: Run the inventory tests**

Run: `cd apps/web && pnpm test -- src/features/inventory`
Expected: PASS. If any test fails because a mock returns fewer/extra fields than the generated type, the mock is the drifted surface — update it.

- [ ] **Step 4: Manual smoke test**

Start the dev server and exercise the page:

```bash
cd apps/web && pnpm dev
# in a browser: log in, navigate to Inventory > Stock Levels.
# Verify: the list renders, filters work, row selection still opens the detail drawer,
# and the "total" meta count matches the row count.
```

Record what you tested and any regressions. A UI regression should be fixed in this phase — the page is in scope.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/inventory/types.ts \
        apps/web/src/features/inventory/StockLevelsPage.tsx
git commit -m "refactor(inventory): switch StockLevelsPage to generated StockLevelData type"
```

### Task D.4 (optional): Catalog as a second reference

If Phase D.3 completes without pain and you have time budget, migrate `apps/web/src/features/catalog/types.ts` (7 tagged DTOs: `CompositeItem`, `Modifier`, `Recipe`, `RecipeLine`, `RecipeCost`, `CompositeItemVariant`, `ModifierGroup`). Follow the same pattern as D.2/D.3. Skip if Phase D.3 was bumpy — the point of this phase is a working reference, not maximum coverage.

---

## Phase E — Preflight drift guard

**Goal:** CI fails if `php artisan typescript:transform` would change `generated.d.ts` without a matching commit.

### Task E.1: Inspect existing preflight

**Files:**
- Read-only: `scripts/preflight.sh`

- [ ] **Step 1: Read the script**

Run: `cat scripts/preflight.sh`

Identify where new checks should slot in (typically at the end, after PHPStan/Pint/PHPUnit/TypeScript).

### Task E.2: Add drift check

- [ ] **Step 1: Append a drift check block**

Edit `scripts/preflight.sh` to add the following section (adapt to the existing script's conventions — e.g. if it uses a `run_check` helper, wrap accordingly):

```bash
# --- TypeScript types drift guard --------------------------------------------
echo "Checking generated TypeScript types are in sync…"
(
  cd apps/api
  php artisan typescript:transform --quiet
)
if ! git diff --quiet -- packages/shared/types/generated.d.ts ; then
  echo "ERROR: packages/shared/types/generated.d.ts is out of date." >&2
  echo "Run: (cd apps/api && php artisan typescript:transform) and commit the diff." >&2
  git --no-pager diff -- packages/shared/types/generated.d.ts >&2
  exit 1
fi
```

- [ ] **Step 2: Trigger a deliberate drift to verify the guard catches it**

Pick any tagged DTO — `StockLevelData` is a good choice because it's our reference-migration target.

Run:

```bash
# Back up the current file, then append a throwaway property.
cp apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php \
   apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php.bak

# Append a new property inside the constructor signature. Use the Edit tool
# instead of sed to avoid whitespace/quoting issues; for CLI verification
# any safe edit that adds one property works. Example:
#   public readonly string $drift_canary = '',
# added as a new constructor parameter.

./scripts/preflight.sh
```

Expected: preflight exits non-zero with the drift error message pointing at `generated.d.ts`.

Revert:

```bash
mv apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php.bak \
   apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php
(cd apps/api && php artisan typescript:transform)
./scripts/preflight.sh
```

Expected: preflight passes.

- [ ] **Step 3: Commit**

```bash
git add scripts/preflight.sh
git commit -m "ci(types): fail preflight when generated TypeScript types are stale"
```

---

## Phase F — ADR + convention documentation

**Goal:** Future contributors know which pattern to follow without re-deriving it.

### Task F.1: Write the ADR

**Files:**
- Create: `docs/adr/2026-04-19-typescript-types-pipeline.md`

- [ ] **Step 1: Write the ADR**

Create `docs/adr/2026-04-19-typescript-types-pipeline.md`:

```markdown
# ADR — TypeScript types pipeline

**Status:** Accepted
**Date:** 2026-04-19
**Authors:** Claude Code (session 2026-04-19-investigate-types-pipeline)

## Context

AutoERP generates TypeScript type declarations from PHP DTOs using
`spatie/laravel-typescript-transformer`. An audit on 2026-04-19 confirmed:

1. Zero files in `apps/web` imported the generated namespace. It was
   cosmetic — every feature hand-rolled parallel TypeScript interfaces.
2. DTOs using `Spatie\LaravelData\DataCollection` emitted
   `Array<any>` instead of the element type — the transformer didn't
   read the `#[DataCollectionOf]` attribute.
3. Four Identity DTOs emitted camelCase properties (`emailVerifiedAt`,
   etc.) while 69 others emitted snake_case. No coherent convention.

The system is pursuing fiscal certifications (NF525, Italian SDI,
Tunisia fiscal, UK MTD, Peppol/Factur-X). Silent drift between backend
and frontend is a compliance-material risk.

## Decisions

### D1. Writer: custom `GlobalNamespaceWriter` + `.d.ts` output.

The default `TypeDefinitionWriter` produces nested
`declare namespace App.Modules.…` blocks, which `moduleDetection: "force"`
in `apps/web/tsconfig.json` pushes module-local. `ModuleWriter` would
flatten but collide on duplicate basenames (`PaymentStatus` ×3,
`TransactionType` ×2).

`GlobalNamespaceWriter` subclasses `TypeDefinitionWriter` and wraps the
output in `declare global { … } export {};`, lifting the whole
namespace tree to the ambient global scope without collisions.

Output is written to `packages/shared/types/generated.d.ts` and pulled
in via a relative-path reference in `apps/web/src/vite-env.d.ts`.

### D2. Case convention: snake_case on both sides.

69/73 tagged DTOs already emitted snake_case. Every hand-written
frontend `types.ts` already used snake_case. The cost of standardizing
was migrating 4 DTOs. Alternative policies (axios interceptor,
backend-side `CamelCaseMapper`) would have required migrating dozens
of DTOs or every frontend type file — 10–50× the churn for the same
safety guarantee.

Enforced by `Tests\Feature\Shared\TypeScript\CaseConventionTest` which
scans every `#[TypeScript]`-tagged DTO and fails if any public property
name contains an uppercase letter.

### D3. `DataCollection` lowering fix: swap to the Data-aware transformer.

The config previously registered
`Spatie\TypeScriptTransformer\Transformers\DtoTransformer` (the
upstream, protocol-package transformer which knows nothing about
Spatie Laravel Data). `spatie/laravel-data` itself ships
`Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer`,
which already resolves `DataCollection<T>` / `#[DataCollectionOf]`
via the same `DataConfig` machinery used at runtime to serialize the
DTO. We simply swap the config to use that transformer; no custom
code.

A small number of production DTOs still emitted `Array<any>` after
the swap because their PHP type was a bare `array` with no generics
and no `#[DataCollectionOf]`. Those were annotated case-by-case (see
commit `chore(types): annotate untyped array properties`).

### D4. CI enforcement via preflight.

`scripts/preflight.sh` regenerates the types into the working tree and
fails if the diff against `packages/shared/types/generated.d.ts` is
non-empty. This surfaces drift at PR time rather than production.

### D5. Migration sequence.

1. Inventory `StockLevelsPage` — the smallest-surface shadow on `dev`
   with a tagged counterpart. Proves the pipeline in one page (this
   plan's Phase D).
2. Catalog — 7 tagged DTOs, one shadow file (`features/catalog/types.ts`).
   Optional in this plan (Phase D.4); a natural second migration.
3. Accounting — done separately by
   `fix/test-suite-baseline-remediation`. This plan explicitly avoids
   `features/finance/*` to prevent merge conflicts.
4. Every other feature migrates as it's touched. No mass rewrite.
5. Any new feature's `types.ts` MUST re-export from generated —
   enforced by code review against `docs/conventions/04-FRONTEND-TYPES.md`.

## Consequences

- Backend PHP DTO renames are always-breaking at the TypeScript compile
  step, not at runtime in production. That's the desired signal.
- Future new modules get type safety for free as long as they tag their
  DTOs with `#[TypeScript]` and re-export from `types.ts`.
- The 18 tagged DTOs without shadow interfaces (e.g. 4 Automotive DTOs)
  get instant type visibility on the frontend without a separate
  migration pass.
```

- [ ] **Step 2: Commit**

```bash
git add docs/adr/2026-04-19-typescript-types-pipeline.md
git commit -m "docs(adr): record TypeScript types pipeline decisions"
```

### Task F.2: Update the frontend-types convention doc

**Files:**
- Modify: `docs/conventions/04-FRONTEND-TYPES.md`

- [ ] **Step 1: Read the current convention**

Run: `cat docs/conventions/04-FRONTEND-TYPES.md`

Identify sections that need update (probably: workflow, naming, enforcement).

- [ ] **Step 2: Rewrite to reflect the new reality**

Update the document with these new sections (merge, don't replace wholesale — preserve the parts that were already correct):

```markdown
## The Pipeline

1. Annotate a PHP DTO with `#[TypeScript]`.
2. Use snake_case property names (enforced by
   `Tests\Feature\Shared\TypeScript\CaseConventionTest`).
3. For collection properties, use
   `#[DataCollectionOf(ElementData::class)]` — the transformer reads
   the attribute and emits `Array<ElementData>`.
4. Run `php artisan typescript:transform`. This regenerates
   `packages/shared/types/generated.d.ts`.
5. Commit both the DTO change and the regenerated file.

`scripts/preflight.sh` will fail CI if you forget step 4 or 5.

## Consuming types in apps/web

The generated file declares its namespaces under `declare global`, so
every `App.Modules.*` type is available without an import. In each
feature's `types.ts`, re-export:

```ts
export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData
export type AccountType = App.Modules.Accounting.Domain.Enums.AccountType
```

Then feature code imports as before:

```ts
import type { StockLevel, AccountType } from './types'
```

Request/filter/form types that aren't part of a wire response CAN be
hand-written in the same `types.ts` — just keep them clearly separated
from the re-exports.

## Enforcement

- `tests/Feature/Shared/TypeScript/CaseConventionTest.php` — blocks
  camelCase DTOs.
- `scripts/preflight.sh` — blocks drifted `generated.d.ts`.
- Code review — new hand-written interfaces shadowing a backend DTO
  must be rewritten as re-exports.
```

- [ ] **Step 3: Commit**

```bash
git add docs/conventions/04-FRONTEND-TYPES.md
git commit -m "docs(conventions): describe new frontend types pipeline"
```

---

## Final verification

- [ ] **Step 1: Full preflight**

Run: `./scripts/preflight.sh`
Expected: PASS end-to-end (PHPStan, Pint, PHPUnit, TypeScript check, ESLint, drift guard).

- [ ] **Step 2: Full frontend typecheck + test**

Run: `cd apps/web && pnpm typecheck && pnpm test`
Expected: PASS.

- [ ] **Step 3: Confirm deliverables**

| Deliverable | Location | Status |
|---|---|---|
| ADR | `docs/adr/2026-04-19-typescript-types-pipeline.md` | Created in F.1 |
| Working pipeline with ≥1 consumer | `apps/web/src/features/<target>/types.ts` re-exports | Created in D.3 |
| Reference migration | `<target>` feature running against generated types | D.3 |
| Preflight guard | `scripts/preflight.sh` drift block | E.2 |
| Convention doc | `docs/conventions/04-FRONTEND-TYPES.md` | F.2 |
| DataCollection lowering test | `tests/Feature/Shared/TypeScript/DataCollectionLoweringTest.php` | B.2 |
| Case convention test | `tests/Feature/Shared/TypeScript/CaseConventionTest.php` | C.1 |

- [ ] **Step 4: Push the branch and open a PR**

```bash
git push -u origin feat/types-pipeline-overhaul
gh pr create --base dev --title "feat(types): end-to-end PHP→TypeScript pipeline overhaul" \
  --body "See docs/adr/2026-04-19-typescript-types-pipeline.md. Closes pipeline Failures 1, 2, 3 per docs/sessions/2026-04-19-investigate-types-pipeline-prompt.md."
```

---

## Out of scope (explicitly deferred)

- Migrating every frontend feature to consume generated types. Only the reference module (Phase D) is migrated in this plan; the rest migrate incrementally as features are touched.
- Migrating non-Identity DTOs that already emit snake_case — nothing to do.
- Tagging the ~18 DTOs without shadow interfaces (Automotive, Marketplace). The generated side will pick them up automatically; frontend consumers can re-export on demand.
- Changing the wire format (snake_case is already the de facto convention).
- Rewriting any feature's business logic.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| `DataTypeScriptTransformer` API changes between `spatie/laravel-data` minor versions (e.g. 4.18 → 4.19 renames `resolveTypeForProperty`) | Feature test B.2 exercises the end-to-end transform on a fixture, so any API drift fails CI. `spatie/laravel-data` is already pinned in `composer.lock`; no minor bumps without an explicit upgrade task. |
| Identity frontend consumers read properties not enumerated in Task C.2 Step 1 | Typecheck (C.2 Step 5) surfaces any missed rename — treat as iteration, not a plan failure |
| Remediation branch (`fix/test-suite-baseline-remediation`) conflicts with Phase D | Phase D explicitly targets `features/inventory/StockLevelsPage.tsx`, which the remediation branch does not touch. `features/finance/*` is entirely off-limits in this plan. |
| `declare global` pattern trips unexpected ESLint rules | Canary file (A.1) + lint check (A.6 Step 2) surface this in Phase A |
| Preflight drift check double-regenerates and slows CI | `php artisan typescript:transform` on 73 DTOs is ~2–3s; acceptable overhead |
