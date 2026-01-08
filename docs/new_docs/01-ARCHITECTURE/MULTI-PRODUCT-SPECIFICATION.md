# Multi-Product Architecture Specification

**Products:** Otospex (Automotive) | IziPOS (Generic Retail/F&B)

---

## 1. Overview

Both products share ~85% of code. Differentiation happens through:
- **APP_PRODUCT** environment variable
- **Dynamic module loading** (some modules only load for specific products)
- **Visual theming** (different branding, colors)
- **Feature flags** (per-company capabilities)

---

## 2. Product Definitions

### Otospex (Automotive ERP)
```
Target Verticals:
├── Mechanic / Service Center
├── Body Shop / Car Glass
├── Service Station / Car Wash
├── Auto Parts Retailer
└── Industrial Parts / Bearings

Specific Modules:
├── Vehicle (VIN, make/model, mileage)
├── Workshop (work orders, labor)
└── Parts Catalog (TecDoc integration planned)

Key Features:
├── Vehicle history tracking
├── Work order management
├── Parts fitment (which parts fit which vehicles)
└── Labor time estimation
```

### IziPOS (Generic ERP/POS)
```
Target Verticals:
├── Retail
│   ├── General Retail
│   ├── Para-Pharmacy
│   ├── Beauty / Cosmetics
│   └── Fashion / Apparel
│
├── Food & Beverage
│   ├── Coffee Shop / Café
│   ├── Restaurant
│   ├── Bakery / Pastry
│   └── Fast Food
│
└── Services
    ├── Salon / Spa
    └── Professional Services

Specific Modules:
├── POS (point of sale terminal)
├── Menu (F&B menu management)
├── Tables (restaurant floor plan)
├── Recipe (ingredient tracking)
└── Batch/Lot Tracking (pharmacy expiry)

Key Features:
├── Quick checkout flow
├── Cash register management
├── Menu variations & modifiers
├── Table management
└── FEFO expiry tracking
```

---

## 3. Module Classification

### Core Modules (Always Loaded)
```
Identity        - Authentication, users, roles
Company         - Multi-company, settings
Communication   - Notifications, emails
Media           - File storage, images
```

### Shared Business Modules (Always Loaded)
```
Product         - Catalog, categories, variants
Partner         - Customers, suppliers
Document        - Quotes, orders, invoices, credit notes
Inventory       - Stock, reservations, movements
Treasury        - Payments, cash tracking
Accounting      - Chart of accounts, journals
```

### Otospex-Only Modules
```
Vehicle         - VIN, vehicle data, snapshots
Workshop        - Work orders, labor tracking
```

### IziPOS-Only Modules
```
POS             - Terminal, cash register, receipts
Menu            - F&B menus, items, modifiers
Tables          - Floor plans, reservations
Recipe          - Ingredients, costing
```

---

## 4. Configuration System

### config/products.php
```php
<?php

return [
    'otospex' => [
        'name' => 'Otospex',
        'tagline' => 'Automotive Business Management',
        'modules' => [
            // Core (always)
            'Identity', 'Company', 'Communication', 'Media',
            // Shared Business (always)
            'Product', 'Partner', 'Document', 'Inventory', 'Treasury', 'Accounting',
            // Otospex-specific
            'Vehicle', 'Workshop',
        ],
        'theme' => [
            'primary_color' => '#1a5f7a',
            'logo' => 'otospex-logo.svg',
        ],
        'features' => [
            'vehicle_tracking' => true,
            'work_orders' => true,
            'parts_fitment' => true,
            'pos_terminal' => false,
            'menu_management' => false,
        ],
    ],
    
    'izipos' => [
        'name' => 'IziPOS',
        'tagline' => 'Simple Business Management',
        'modules' => [
            // Core (always)
            'Identity', 'Company', 'Communication', 'Media',
            // Shared Business (always)
            'Product', 'Partner', 'Document', 'Inventory', 'Treasury', 'Accounting',
            // IziPOS-specific
            'POS', 'Menu', 'Tables', 'Recipe',
        ],
        'theme' => [
            'primary_color' => '#2d7d46',
            'logo' => 'izipos-logo.svg',
        ],
        'features' => [
            'vehicle_tracking' => false,
            'work_orders' => false,
            'parts_fitment' => false,
            'pos_terminal' => true,
            'menu_management' => true,
        ],
    ],
];
```

### ProductService
```php
<?php

namespace App\Services;

class ProductService
{
    public function current(): string
    {
        return config('app.product', 'izipos');
    }
    
    public function is(string $product): bool
    {
        return $this->current() === $product;
    }
    
    public function isOtospex(): bool
    {
        return $this->is('otospex');
    }
    
    public function isIziPOS(): bool
    {
        return $this->is('izipos');
    }
    
    public function modules(): array
    {
        return config("products.{$this->current()}.modules", []);
    }
    
    public function hasFeature(string $feature): bool
    {
        return config("products.{$this->current()}.features.{$feature}", false);
    }
}
```

---

## 5. Module Loading

### ModuleServiceProvider
```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ProductService;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $product = app(ProductService::class);
        $enabledModules = $product->modules();
        
        foreach ($enabledModules as $moduleName) {
            $providerClass = "App\\Modules\\{$moduleName}\\{$moduleName}ServiceProvider";
            
            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }
}
```

### Route Registration (per module)
Each module has `Presentation/routes.php`:
```php
<?php
// App/Modules/POS/Presentation/routes.php

use Illuminate\Support\Facades\Route;

// Only register if module is enabled (handled by ModuleServiceProvider)
Route::prefix('api/pos')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::apiResource('terminals', TerminalController::class);
        Route::apiResource('sessions', SessionController::class);
        // ...
    });
```

---

## 6. Database Considerations

### Shared Tables
All business tables are shared. Module-specific tables are created but may be empty:
- `vehicles` table exists but unused in IziPOS
- `pos_terminals` table exists but unused in Otospex

### Migrations
Migrations run for all modules. The module service provider controls runtime access, not schema.

### Multi-Tenancy + Multi-Company
```
Tenant (Organization)
  └── Company (Legal Entity)
       ├── Country-specific settings
       ├── Tax configuration
       └── Chart of accounts
```

A tenant can have multiple companies across different countries.

---

## 7. Frontend Architecture

### Product Context
```tsx
// contexts/ProductContext.tsx
interface ProductContextType {
  product: 'otospex' | 'izipos';
  features: Record<string, boolean>;
  theme: ThemeConfig;
  hasFeature: (feature: string) => boolean;
}

const ProductContext = createContext<ProductContextType | null>(null);

export const useProduct = () => {
  const context = useContext(ProductContext);
  if (!context) throw new Error('useProduct must be used within ProductProvider');
  return context;
};
```

### Conditional Rendering
```tsx
function ProductForm() {
  const { hasFeature } = useProduct();
  
  return (
    <Form>
      <TextField name="name" label="Product Name" />
      <TextField name="sku" label="SKU" />
      
      {hasFeature('vehicle_tracking') && (
        <VehicleFitmentSection />
      )}
      
      {hasFeature('menu_management') && (
        <RecipeLinkSection />
      )}
    </Form>
  );
}
```

---

## 8. Deployment Strategy

### Single Docker Image
```dockerfile
# Same image for both products
FROM php:8.3-fpm
# ... build steps ...
```

### Environment Differentiation
```yaml
# docker-compose.otospex.yml
services:
  app:
    image: erp-platform:latest
    environment:
      - APP_PRODUCT=otospex
      - APP_NAME=Otospex

# docker-compose.izipos.yml
services:
  app:
    image: erp-platform:latest
    environment:
      - APP_PRODUCT=izipos
      - APP_NAME=IziPOS
```

### Domain Routing
```
otospex.com      → APP_PRODUCT=otospex
izipos.com       → APP_PRODUCT=izipos
app.otospex.com  → APP_PRODUCT=otospex
app.izipos.com   → APP_PRODUCT=izipos
```

---

## 9. Adding a New Module

1. **Create module structure:**
   ```
   App/Modules/NewModule/
   ├── Domain/
   │   ├── NewEntity.php
   │   └── Repositories/
   ├── Application/
   │   ├── DTOs/
   │   ├── Services/
   │   └── Events/
   ├── Infrastructure/
   │   └── Persistence/
   ├── Presentation/
   │   ├── Controllers/
   │   └── routes.php
   └── NewModuleServiceProvider.php
   ```

2. **Add to product configuration:**
   ```php
   // config/products.php
   'izipos' => [
       'modules' => [
           // ... existing ...
           'NewModule',  // Add here
       ],
   ],
   ```

3. **Create migrations:**
   ```bash
   php artisan make:migration create_new_module_tables
   ```

4. **Register routes in module's routes.php**

5. **Build frontend components**

---

## 10. Testing Across Products

```php
// Test both product contexts
public function test_feature_works_in_otospex(): void
{
    config(['app.product' => 'otospex']);
    
    // Test Otospex-specific behavior
}

public function test_feature_works_in_izipos(): void
{
    config(['app.product' => 'izipos']);
    
    // Test IziPOS-specific behavior
}
```

---

## Appendix: Quick Reference

| Question | Answer |
|----------|--------|
| How to check current product? | `app(ProductService::class)->current()` |
| How to check if feature enabled? | `app(ProductService::class)->hasFeature('pos_terminal')` |
| Where are product configs? | `config/products.php` |
| Where is Company model? | `App\Modules\Company\Domain\Company` |
| Credit note or credit memo? | `CreditNote` |
| How to add module to product? | Add to `modules` array in `config/products.php` |
