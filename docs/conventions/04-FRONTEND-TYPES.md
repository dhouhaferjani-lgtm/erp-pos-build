# Frontend Type Generation Conventions

> **Purpose:** How TypeScript types are generated from PHP DTOs
> **Last Updated:** 2025-12-30

## Type Flow

```
Backend: app/Modules/*/Application/DTOs/*.php
         (with #[TypeScript] attribute)
    ↓
php artisan typescript:transform
    ↓
packages/shared/types/generated.ts
    ↓
Frontend: apps/web/src/**/*.tsx
         (imports from @autoerp/shared/types/generated)
```

## Backend DTO Definition

**File:** `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`

```php
<?php
namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]  // ← REQUIRED for transformation
class ProductData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $sku,
        public ProductType $type,        // Enum → union type
        public ?string $description,     // Nullable → string | null
        public bool $is_active,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            id: $product->id,
            name: $product->name,
            sku: $product->sku,
            type: $product->type,
        );
    }
}
```

## Enum Definition

```php
<?php
namespace App\Modules\Product\Domain\Enums;

enum ProductType: string
{
    case Part = 'part';
    case Service = 'service';
    case Consumable = 'consumable';
}
```

## Generate Types

```bash
# From apps/api directory
php artisan typescript:transform

# Output: packages/shared/types/generated.ts
```

## Generated TypeScript Output

**File:** `packages/shared/types/generated.ts`

```typescript
declare namespace App.Modules.Product.Domain.Enums {
  export type ProductType = 'part' | 'service' | 'consumable';
}

declare namespace App.Modules.Product.Application.DTOs {
  export type ProductData = {
    id: string;
    name: string;
    sku: string;
    type: App.Modules.Product.Domain.Enums.ProductType;
    description: string | null;
    is_active: boolean;
    created_at: string;  // DateTime → string (ISO 8601)
  };
}
```

## Frontend Usage Patterns

### Pattern 1: Direct Import

```typescript
import type { Invoice } from '@autoerp/shared/types/generated'

const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)
```

### Pattern 2: Re-export with Extensions

```typescript
// apps/web/src/types/document.ts
import type { App } from '@autoerp/shared/types/generated'

export type Document = App.Modules.Document.Application.DTOs.DocumentData & {
  issue_date?: string  // Add computed properties
}
```

### Pattern 3: Enum Aliases

```typescript
import type {
  App_Modules_Document_Domain_Enums_DocumentStatus as DocumentStatus,
  App_Modules_Document_Domain_Enums_DocumentType as DocumentType,
} from '@autoerp/shared/types/generated'

export interface Expense {
  type: DocumentType
  status: DocumentStatus
}
```

## tsconfig.json Path Alias

**File:** `apps/web/tsconfig.json`

```json
{
  "compilerOptions": {
    "paths": {
      "@/*": ["src/*"],
      "@autoerp/shared/*": ["../../packages/shared/*"]
    }
  }
}
```

## Workflow: Adding New Field to DTO

1. **Add field to PHP DTO:**
   ```php
   public ?string $new_field,
   ```

2. **Run transformation:**
   ```bash
   php artisan typescript:transform
   ```

3. **Use in frontend immediately:**
   ```typescript
   const product: Product = {
     new_field: null,  // ✅ Now available
   }
   ```

## Key Rules

1. **Single Source of Truth** - PHP DTOs are authoritative
2. **No Manual Type Files** - Never duplicate `generated.ts`
3. **Enums → Unions** - PHP enums become TypeScript union types
4. **DateTime → string** - All dates serialized as ISO 8601 strings
5. **Nullable → `| null`** - PHP `?string` becomes `string | null`
6. **Run After DTO Changes** - Always regenerate after modifying DTOs

## Checklist

- [ ] DTO class has `#[TypeScript]` attribute
- [ ] DTO extends `Spatie\LaravelData\Data`
- [ ] Run `php artisan typescript:transform` after changes
- [ ] Import from `@autoerp/shared/types/generated`
- [ ] Never manually edit `generated.ts`

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
2. Run `php artisan typescript:transform` (regenerates `packages/shared/types/generated.ts`).
3. Update the hand-written interface in `features/<module>/types.ts` (until the types pipeline is restored — see investigation prompt above).
4. Update the factory default in `__fixtures__/<name>.ts`.
5. TypeScript will fail-compile every consumer until step 4 is done. This is the property we want.

### Related

- `renderWithProviders` (at `apps/web/src/test/renderWithProviders.tsx`) — the shared test wrapper that mounts `ProductConfigProvider` + `CompanyConfigProvider` and accepts seed overrides. Required for tests that render components consuming those contexts.
- `scripts/preflight.sh` — runs `php artisan typescript:transform` and fails on drift between DTOs and the committed `packages/shared/types/generated.ts`.
