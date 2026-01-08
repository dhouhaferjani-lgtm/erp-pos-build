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
         (imports from @mecanospex/shared/types/generated)
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
import type { Invoice } from '@mecanospex/shared/types/generated'

const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)
```

### Pattern 2: Re-export with Extensions

```typescript
// apps/web/src/types/document.ts
import type { App } from '@mecanospex/shared/types/generated'

export type Document = App.Modules.Document.Application.DTOs.DocumentData & {
  issue_date?: string  // Add computed properties
}
```

### Pattern 3: Enum Aliases

```typescript
import type {
  App_Modules_Document_Domain_Enums_DocumentStatus as DocumentStatus,
  App_Modules_Document_Domain_Enums_DocumentType as DocumentType,
} from '@mecanospex/shared/types/generated'

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
- [ ] Import from `@mecanospex/shared/types/generated`
- [ ] Never manually edit `generated.ts`
