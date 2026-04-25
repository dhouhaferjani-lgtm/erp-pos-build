# Frontend Type Generation Conventions

> **Purpose:** How TypeScript types flow from PHP DTOs into `apps/web`.
> **Last updated:** 2026-04-19 (pipeline overhaul — see [ADR](../adr/2026-04-19-typescript-types-pipeline.md))

## Type flow

```
Backend: apps/api/app/Modules/*/Application/DTOs/*.php
         (tagged with #[TypeScript])
    ↓
php artisan typescript:transform
    ↓
packages/shared/types/generated.d.ts
    (declare global { declare namespace App.Modules.*.… } export {};)
    ↓
Frontend: apps/web/src/features/<module>/types.ts
         (re-exports from ambient App.Modules.*.… globals)
    ↓
Frontend code imports from './types'
```

## Five rules for every new DTO

### 1. Tag with `#[TypeScript]`

```php
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WorkOrderData extends Data
{
    public function __construct(
        public string $work_order_number,
        // …
    ) {}
}
```

CLAUDE.md rule #7. Untagged DTOs do not flow to the frontend.

### 2. snake_case public properties

```php
// ✅ good
public string $work_order_number,
public string $opened_at,
public ?string $assigned_technician_id,

// ❌ bad — camelCase drift
public string $workOrderNumber,
```

69 of 73 existing tagged DTOs already do this. The four exceptions
(Identity module: `UserData`, `AuthUserData`, `LoginData`,
`LoginResponseData`) are in scope for the separate Phase C migration
and will be fixed with a compat-shim release.

### 3. `#[DataCollectionOf]` for every collection property

```php
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\DataCollection;

public function __construct(
    public string $document_number,
    #[DataCollectionOf(DocumentLineData::class)]
    public DataCollection|array $lines,
) {}
```

Without the attribute, the transformer can't resolve element types
and emits `Array<any>`. If the element class is itself a `Data` DTO,
**it MUST ALSO be tagged with `#[TypeScript]`** — otherwise
`ReplaceSymbolsInTypeAction` substitutes `any` for the missing
symbol and you're back to `Array<any>`.

### 4. PHPDoc generics for scalar arrays

```php
/** @var string[] */
public array $oem_numbers,

/** @var int[] */
public array $bay_ids,
```

### 5. Re-export from `features/<module>/types.ts` — never hand-roll a shadow

```typescript
// apps/web/src/features/inventory/types.ts
export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData

export interface StockLevelsResponse {
  data: StockLevel[]
  meta?: {
    total: number
    current_page: number
    per_page: number
    last_page: number
  }
}
```

Frontend-only shapes (request payloads, form state, display-only
view models) ARE allowed in the same file — just keep them clearly
separated from the re-exports. Do NOT re-declare any field that
exists on a backend DTO.

## Generated output shape

`packages/shared/types/generated.d.ts` is an ambient declaration
file:

```typescript
declare global {
  declare namespace App.Enums {
    export type Vertical = 'mechanic' | 'pharmacy' | 'restaurant' | …
  }

  declare namespace App.Modules.Inventory.Application.DTOs {
    export type StockLevelData = {
      id: string
      product_id: string
      product_name: string | null
      location_id: string
      location_name: string | null
      quantity: string
      reserved: string
      available: string
      incoming: string
      projected_available: string
      min_quantity: string | null
      max_quantity: string | null
      is_below_minimum: boolean
    }
  }

  // … every other tagged DTO under its module path
}

export {}
```

Every `App.Modules.*.…` namespace is globally accessible from every
file compiled under `apps/web/tsconfig.json`. No `import` is needed
at the consumption site — only at the re-export site in
`features/<module>/types.ts`.

## Field type mappings

| PHP type | Emitted TypeScript |
|---|---|
| `string`, `int`, `float`, `bool` | `string`, `number`, `number`, `boolean` |
| `?T` (nullable) | `T \| null` |
| `Carbon`, `DateTime`, `DateTimeImmutable` | `string` (ISO 8601 on the wire) |
| `BackedEnum` | Union literal (`'asset' \| 'liability' \| …`) |
| `DataCollection<int, Foo>` + `#[DataCollectionOf(Foo::class)]` | `Array<App.Modules.….Foo>` |
| `array` (no annotation) | `Array<any>` — flag this |
| `array` with `@var Foo[]` PHPDoc | `Array<Foo>` |

**Important: monetary and quantity fields emit `string`, not `number`.**
Postgres DECIMAL columns serialize as strings to preserve precision.
Use `lib/decimal.ts` (`bccomp`, `bcsub`, `bcadd`, `bcmul`,
`formatCurrency`) for every operation. Native JavaScript comparison
on these fields is lexical and will give wrong answers
(`"10" <= "9"` is `true`).

## Workflow — adding a new field

1. **Add the field to the PHP DTO:**
   ```php
   public ?string $technician_note,
   ```
2. **Regenerate types:**
   ```bash
   cd apps/api && php artisan typescript:transform
   ```
3. **Use it immediately in frontend code** — `App.Modules.*.…` is
   already updated. If you re-exported the parent type in
   `features/<module>/types.ts`, the consumer just picks it up.
4. **Commit both the PHP change AND the regenerated `.d.ts`** as a
   single commit. CI blocks merges where these disagree (see next
   section).

## CI enforcement

Two guards prevent stale `generated.d.ts`:

1. **`scripts/preflight.sh`** — run locally before pushing. It
   regenerates the types and fails on any diff, with an inline
   summary + three-command fix recipe.
2. **`.github/workflows/ci.yml → types-drift` job** — runs in every
   PR. Fails the build with a `::error::` annotation + diff summary
   if the committed `generated.d.ts` disagrees with a fresh regen.
   The job is in `all-checks-pass`'s `needs` list, so merge is
   blocked.

Both surfaces emit the same fix:

```bash
(cd apps/api && php artisan typescript:transform)
git add packages/shared/types/generated.d.ts
git commit -m 'chore(types): regenerate TypeScript types'
```

## Contract-lock tests (advanced)

For features where type drift would be especially dangerous (fiscal
flows, monetary calculations), write a type-level contract test:

```typescript
// apps/web/src/features/inventory/__tests__/types.test.ts
import { describe, it, expectTypeOf } from 'vitest'
import type { StockLevel } from '../types'

describe('StockLevel type contract', () => {
  it('monetary and quantity fields are strings, not numbers', () => {
    expectTypeOf<StockLevel['quantity']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['available']>().toEqualTypeOf<string>()
  })
})
```

**IMPORTANT:** `expectTypeOf` is a zero-runtime proxy — `vitest run`
reports these tests green regardless of truth. The actual guard is
`tsc --noEmit` (part of preflight + CI). Add a comment at the top of
every such test file noting this so future contributors aren't
misled by a green vitest run.

## Key rules

1. **Single source of truth** — the PHP DTO is authoritative.
2. **No hand-written shadow interfaces** — every backend-derived
   type is a re-export from the ambient namespace.
3. **Monetary/quantity are strings, not numbers.** Route all
   comparisons through `lib/decimal.ts`.
4. **Regenerate + commit in the same change.** CI enforces this.
5. **Enums stay in sync automatically.** PHP `BackedEnum` → TS
   union literal.

## Reference migration

`apps/web/src/features/inventory/` demonstrates the whole pattern
end-to-end:
- `types.ts` — re-export from `App.Modules.Inventory.Application.DTOs.StockLevelData`
- `__tests__/types.test.ts` — type-level contract lock
- `StockLevelsPage.tsx` — consumer, routes every numeric comparison
  through `bccomp`

Use it as the template when migrating another module's shadow.

## Checklist

- [ ] DTO class has `#[TypeScript]`
- [ ] DTO extends `Spatie\LaravelData\Data`
- [ ] All public properties are snake_case
- [ ] Collections use `#[DataCollectionOf(ElementData::class)]`
- [ ] Scalar-array properties have `@var` PHPDoc
- [ ] Element DTOs for collections are ALSO tagged with `#[TypeScript]`
- [ ] Ran `php artisan typescript:transform` after any DTO change
- [ ] `generated.d.ts` is committed alongside the PHP change
- [ ] `features/<module>/types.ts` re-exports — no hand-rolled shadows
- [ ] Monetary/quantity operations use `lib/decimal.ts` helpers

## Fixture factories for tests

Frontend tests MUST use typed fixture factories instead of ad-hoc mock literals. This prevents drift when types change.

### Why this rule exists

Tests historically mocked API responses with inline object literals that had the wrong shape. The type system never caught it because `mockResolvedValue` of a `vi.fn()` is typed as `any`. The 2026-04-19 test-suite remediation traced 80+ test failures to this class of drift — the fix was to make every mock type-checked against the same interface the page consumes.

### Pattern

Colocate fixtures next to the test under a `__fixtures__/` directory. Type the factory return against the hand-written frontend interface (or, when the types pipeline is restored, the generated DTO — tracked separately in `docs/sessions/2026-04-19-investigate-types-pipeline-prompt.md`):

```ts
// src/features/finance/__fixtures__/agedReceivables.ts
import type { AgedReceivablesData, AgedReceivablesLine } from '../types'

export function makeAgedReceivablesLine(
  overrides: Partial<AgedReceivablesLine> = {},
): AgedReceivablesLine {
  return {
    customer_id: '00000000-0000-4000-8000-000000000001',
    customer_name: 'ACME Corp',
    current: '1000.00',
    days_30: '500.00',
    days_60: '200.00',
    days_90: '100.00',
    over_90: '50.00',
    total: '1850.00',
    ...overrides,
  }
}

export function makeAgedReceivablesReport(
  overrides: Partial<AgedReceivablesData> = {},
): AgedReceivablesData {
  const defaultLines = [makeAgedReceivablesLine()]
  const lines = overrides.lines ?? defaultLines
  return {
    as_of_date: '2026-04-19',
    lines,
    total_current: '1000.00',
    total_days_30: '500.00',
    total_days_60: '200.00',
    total_days_90: '100.00',
    total_over_90: '50.00',
    grand_total: '1850.00',
    ...overrides,
  }
}
```

Tests consume factories:

```ts
import { makeAgedReceivablesReport, makeAgedReceivablesLine } from './__fixtures__/agedReceivables'

mockApiGet.mockResolvedValue(
  makeAgedReceivablesReport({
    lines: [makeAgedReceivablesLine({ customer_name: 'ACME Corp' })],
  }),
)
```

### Rules

1. **Factory return type MUST be the interface the component consumes.** Never `any`, never `Partial<X>`, never an inline type. If the interface doesn't exist, create or export it first.
2. **Every test mock that returns an API response MUST call a factory.** No raw object literals in `mockResolvedValue`, `mockReturnValue`, etc.
3. **Defaults must be plausible production values.** UUIDs should be real UUIDs (not `'1'`), currency fields should be strings matching the DTO convention (`'1000.00'`), enums should be valid values.
4. **Overrides must be `Partial<X>`.** Never wider than the type — callers cannot add fields that don't exist on the real DTO.
5. **Colocate.** Fixtures live in `__fixtures__/` next to the tests that use them. Cross-cutting fixtures (e.g. `companyConfig`, `productConfig` used by `renderWithProviders`) live under `apps/web/src/test/fixtures/`.
6. **Wrapper factories for wrapped shapes.** If an endpoint returns `{ lines: [], grand_total: '0' }`, provide both `makeXLine()` and `makeXReport()`. The line factory composes into the report factory's default `lines` array.
7. **No factory should call `faker`, `uuid()`, `new Date()`, or anything non-deterministic.** Tests must be reproducible; pin all values.

### When a DTO changes

1. Add/rename/remove the field in the backend DTO.
2. Run `php artisan typescript:transform` (regenerates `packages/shared/types/generated.d.ts`).
3. The re-export in `features/<module>/types.ts` updates automatically — the ambient `App.Modules.*` namespace flows through.
4. Update the factory default in `__fixtures__/<name>.ts`.
5. TypeScript will fail-compile every consumer until step 4 is done. This is the property we want.

### Related

- `renderWithProviders` (at `apps/web/src/test/renderWithProviders.tsx`) — the shared test wrapper that mounts `ProductConfigProvider` + `CompanyConfigProvider` and accepts seed overrides. Required for tests that render components consuming those contexts.
- `scripts/preflight.sh` — runs `php artisan typescript:transform` and fails on drift between DTOs and the committed `packages/shared/types/generated.d.ts`.
