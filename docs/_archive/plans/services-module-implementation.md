# Services Module Implementation Plan

> **Scope:** Add a dedicated Services section to the ERP with categories, tags, and pricing
> **Target:** Automotive service businesses (garages, body shops, glass specialists)

---

## Executive Summary

Based on ERP best practices and automotive industry standards:
- Services need **categories** (hierarchical grouping)
- Services need **tags** (flexible filtering/searching)
- Services need **pricing structures** (hourly rates, fixed prices, labor time estimates)
- Services should integrate with **labor guides** (Mitchell 1, MOTOR, ALLDATA)

**Architecture Decision:** Extend the existing Product model rather than create a separate Service entity, because:
1. Both products and services appear on document line items
2. Shared categories/tags infrastructure reduces duplication
3. Document lines already reference `product_id` - no schema changes needed

---

## Current State

### Existing Product Model (`app/Modules/Product/Domain/Product.php`)
```php
enum ProductType: string {
    case Part = 'part';
    case Service = 'service';
    case Consumable = 'consumable';
}

// Product fields:
// - name, sku, type, description
// - sale_price, purchase_price, cost_price, tax_rate
// - unit, barcode, is_active
// - oem_numbers, cross_references (for parts)
// - margins (target_margin_override, minimum_margin_override)
```

### What's Missing
- No categories (hierarchical)
- No tags (many-to-many)
- No service-specific fields (labor_time, pricing_type)
- No sidebar entry for Services
- No dedicated Services UI

---

## Phase 1: Database Schema

### 1.1 Categories Table (Shared)
```php
// Migration: create_categories_table
Schema::create('categories', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->index();
    $table->uuid('company_id')->index();
    $table->uuid('parent_id')->nullable()->index(); // Hierarchical
    $table->string('type'); // 'product' or 'service'
    $table->string('name');
    $table->string('slug');
    $table->text('description')->nullable();
    $table->string('color', 7)->nullable(); // Hex color for UI
    $table->string('icon')->nullable(); // Icon name
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['company_id', 'type', 'slug']);
    $table->foreign('tenant_id')->references('id')->on('tenants');
    $table->foreign('company_id')->references('id')->on('companies');
    $table->foreign('parent_id')->references('id')->on('categories');
});
```

### 1.2 Tags Table (Shared)
```php
// Migration: create_tags_table
Schema::create('tags', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->index();
    $table->uuid('company_id')->index();
    $table->string('name');
    $table->string('slug');
    $table->string('color', 7)->nullable(); // Hex color
    $table->timestamps();

    $table->unique(['company_id', 'slug']);
});

// Pivot table: taggables (polymorphic)
Schema::create('taggables', function (Blueprint $table) {
    $table->uuid('tag_id');
    $table->uuidMorphs('taggable'); // taggable_id, taggable_type
    $table->timestamps();

    $table->primary(['tag_id', 'taggable_id', 'taggable_type']);
    $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
});
```

### 1.3 Product Extensions for Services
```php
// Migration: add_service_fields_to_products
Schema::table('products', function (Blueprint $table) {
    $table->uuid('category_id')->nullable()->after('type');
    $table->boolean('is_physical')->default(true)->after('category_id'); // false for services

    // Service-specific fields (nullable for parts)
    $table->string('pricing_type')->nullable()->after('is_physical'); // 'fixed', 'hourly', 'flat_rate'
    $table->decimal('labor_hours', 8, 2)->nullable()->after('pricing_type'); // Book time
    $table->decimal('hourly_rate', 12, 2)->nullable()->after('labor_hours'); // If pricing_type='hourly'
    $table->string('labor_guide_code')->nullable()->after('hourly_rate'); // Mitchell/MOTOR code

    $table->foreign('category_id')->references('id')->on('categories');
});
```

---

## Phase 2: Domain Models

### 2.1 Category Entity
```php
// app/Modules/Catalog/Domain/Category.php
namespace App\Modules\Catalog\Domain;

class Category extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'company_id', 'parent_id',
        'type', 'name', 'slug', 'description',
        'color', 'icon', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('type', 'service');
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->where('type', 'product');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
```

### 2.2 Tag Entity
```php
// app/Modules/Catalog/Domain/Tag.php
namespace App\Modules\Catalog\Domain;

class Tag extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'company_id', 'name', 'slug', 'color',
    ];

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'taggable');
    }
}
```

### 2.3 Product Extensions
```php
// Update: app/Modules/Product/Domain/Product.php
// Add new fields and relationships

protected $fillable = [
    // ... existing fields ...
    'category_id',
    'is_physical',
    'pricing_type',
    'labor_hours',
    'hourly_rate',
    'labor_guide_code',
];

protected $casts = [
    // ... existing casts ...
    'is_physical' => 'boolean',
    'labor_hours' => 'decimal:2',
    'hourly_rate' => 'decimal:2',
];

public function category(): BelongsTo
{
    return $this->belongsTo(Category::class);
}

public function tags(): MorphToMany
{
    return $this->morphToMany(Tag::class, 'taggable');
}

// Update isService() to use is_physical
public function isPhysical(): bool
{
    return $this->is_physical;
}

// Calculate effective price
public function getEffectivePrice(): string
{
    if ($this->pricing_type === 'hourly' && $this->labor_hours && $this->hourly_rate) {
        return bcmul($this->labor_hours, $this->hourly_rate, 2);
    }
    return $this->sale_price;
}
```

### 2.4 Enums
```php
// app/Modules/Product/Domain/Enums/PricingType.php
namespace App\Modules\Product\Domain\Enums;

enum PricingType: string
{
    case Fixed = 'fixed';       // Fixed price service
    case Hourly = 'hourly';     // Hourly rate × estimated hours
    case FlatRate = 'flat_rate'; // Book time pricing
}
```

---

## Phase 3: Backend API

### 3.1 New Routes
```php
// Categories (shared)
Route::apiResource('categories', CategoryController::class);
Route::get('categories/tree', [CategoryController::class, 'tree']);

// Tags (shared)
Route::apiResource('tags', TagController::class);

// Services (filtered products)
Route::get('services', [ServiceController::class, 'index']);
Route::post('services', [ServiceController::class, 'store']);
Route::get('services/{id}', [ServiceController::class, 'show']);
Route::patch('services/{id}', [ServiceController::class, 'update']);
Route::delete('services/{id}', [ServiceController::class, 'destroy']);
```

### 3.2 Service Controller
```php
// app/Modules/Product/Presentation/Controllers/ServiceController.php
class ServiceController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $query = Product::where('company_id', auth()->user()->company_id)
            ->where('type', ProductType::Service)
            ->with(['category', 'tags']);

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('tag_ids')) {
            $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $request->tag_ids));
        }

        return ProductResource::collection($query->paginate());
    }

    public function store(CreateServiceRequest $request): JsonResource
    {
        $data = $request->validated();
        $data['type'] = ProductType::Service;
        $data['is_physical'] = false;

        $service = Product::create($data);

        if (!empty($data['tag_ids'])) {
            $service->tags()->sync($data['tag_ids']);
        }

        return new ProductResource($service->load(['category', 'tags']));
    }
}
```

### 3.3 Request Validation
```php
// app/Modules/Product/Presentation/Requests/CreateServiceRequest.php
class CreateServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', 'exists:tags,id'],
            'pricing_type' => ['required', 'in:fixed,hourly,flat_rate'],
            'sale_price' => ['required_if:pricing_type,fixed', 'numeric', 'min:0'],
            'hourly_rate' => ['required_if:pricing_type,hourly', 'numeric', 'min:0'],
            'labor_hours' => ['nullable', 'numeric', 'min:0'],
            'labor_guide_code' => ['nullable', 'string', 'max:50'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
```

---

## Phase 4: Frontend - Sidebar Navigation

### 4.1 Update Sidebar Navigation
```typescript
// apps/web/src/components/organisms/Sidebar/Sidebar.tsx
// Add new "services" module between "inventory" and "vehicles"

const navigation: NavModule[] = [
  // ... existing modules ...
  {
    key: 'inventory',
    icon: Package,
    children: [
      { key: 'products', href: '/inventory/products', icon: Package },
      // ... existing children ...
    ],
  },
  // NEW: Services module
  {
    key: 'services',
    icon: Wrench, // or Tool
    children: [
      { key: 'serviceList', href: '/services', icon: Wrench },
      { key: 'serviceCategories', href: '/services/categories', icon: FolderTree },
    ],
  },
  {
    key: 'vehicles',
    // ...
  },
]
```

### 4.2 Update Translations
```json
// apps/web/src/locales/en/common.json
{
  "navigation": {
    // ... existing keys ...
    "services": "Services",
    "serviceList": "Service Catalog",
    "serviceCategories": "Categories"
  }
}

// apps/web/src/locales/fr/common.json
{
  "navigation": {
    // ... existing keys ...
    "services": "Services",
    "serviceList": "Catalogue des services",
    "serviceCategories": "Categories"
  }
}
```

### 4.3 Service Translations
```json
// apps/web/src/locales/en/services.json
{
  "title": "Services",
  "new": "New Service",
  "edit": "Edit Service",
  "fields": {
    "name": "Service Name",
    "sku": "Service Code",
    "description": "Description",
    "category": "Category",
    "tags": "Tags",
    "pricingType": "Pricing Type",
    "salePrice": "Price",
    "hourlyRate": "Hourly Rate",
    "laborHours": "Labor Hours",
    "laborGuideCode": "Labor Guide Code",
    "taxRate": "Tax Rate"
  },
  "pricingTypes": {
    "fixed": "Fixed Price",
    "hourly": "Hourly Rate",
    "flatRate": "Flat Rate (Book Time)"
  },
  "categories": {
    "title": "Service Categories",
    "new": "New Category",
    "edit": "Edit Category",
    "empty": {
      "title": "No categories yet",
      "description": "Create categories to organize your services."
    }
  },
  "tags": {
    "title": "Tags",
    "new": "New Tag",
    "addTag": "Add Tag"
  },
  "empty": {
    "title": "No services yet",
    "description": "Get started by creating your first service."
  },
  "automotive": {
    "categories": {
      "maintenance": "Preventive Maintenance",
      "brakes": "Brakes",
      "steering": "Steering & Suspension",
      "engine": "Engine",
      "electrical": "Electrical",
      "transmission": "Transmission",
      "bodywork": "Bodywork",
      "glass": "Glass",
      "diagnostics": "Diagnostics",
      "other": "Other"
    }
  }
}
```

---

## Phase 5: Frontend - Service List Page

### 5.1 Routes
```typescript
// apps/web/src/router.tsx
{
  path: 'services',
  children: [
    { index: true, element: <ServiceListPage /> },
    { path: 'new', element: <ServiceFormPage /> },
    { path: ':id', element: <ServiceDetailPage /> },
    { path: ':id/edit', element: <ServiceFormPage /> },
    { path: 'categories', element: <ServiceCategoriesPage /> },
  ],
}
```

### 5.2 API Hooks
```typescript
// apps/web/src/features/services/api/services.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../lib/api'

export interface Service {
  id: string
  name: string
  sku: string | null
  description: string | null
  category_id: string | null
  category: Category | null
  tags: Tag[]
  pricing_type: 'fixed' | 'hourly' | 'flat_rate'
  sale_price: string
  hourly_rate: string | null
  labor_hours: string | null
  labor_guide_code: string | null
  tax_rate: string
  is_active: boolean
}

export interface Category {
  id: string
  name: string
  slug: string
  parent_id: string | null
  color: string | null
  icon: string | null
  children?: Category[]
}

export interface Tag {
  id: string
  name: string
  slug: string
  color: string | null
}

export function useServices(params?: { category_id?: string; tag_ids?: string[] }) {
  return useQuery({
    queryKey: ['services', params],
    queryFn: () => api.get('/services', { params }).then(r => r.data),
  })
}

export function useServiceCategories() {
  return useQuery({
    queryKey: ['service-categories'],
    queryFn: () => api.get('/categories', { params: { type: 'service' } }).then(r => r.data),
  })
}

export function useTags() {
  return useQuery({
    queryKey: ['tags'],
    queryFn: () => api.get('/tags').then(r => r.data),
  })
}

export function useCreateService() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: Partial<Service>) => api.post('/services', data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['services'] })
    },
  })
}
```

### 5.3 Service List Page Component
```typescript
// apps/web/src/features/services/ServiceListPage.tsx
// Standard list page with:
// - Category filter sidebar
// - Tag filter chips
// - Search
// - Table with: Name, Category, Price, Tags, Status, Actions
// - "New Service" button
```

---

## Phase 6: Data Migration

### 6.1 Seed Default Service Categories
```php
// database/seeders/ServiceCategoriesSeeder.php
class ServiceCategoriesSeeder extends Seeder
{
    public function run(string $companyId, string $tenantId): void
    {
        $categories = [
            ['name' => 'Preventive Maintenance', 'slug' => 'maintenance', 'icon' => 'wrench'],
            ['name' => 'Brakes', 'slug' => 'brakes', 'icon' => 'disc'],
            ['name' => 'Steering & Suspension', 'slug' => 'steering', 'icon' => 'car'],
            ['name' => 'Engine', 'slug' => 'engine', 'icon' => 'cog'],
            ['name' => 'Electrical', 'slug' => 'electrical', 'icon' => 'zap'],
            ['name' => 'Transmission', 'slug' => 'transmission', 'icon' => 'settings'],
            ['name' => 'Bodywork', 'slug' => 'bodywork', 'icon' => 'car'],
            ['name' => 'Glass', 'slug' => 'glass', 'icon' => 'square'],
            ['name' => 'Diagnostics', 'slug' => 'diagnostics', 'icon' => 'search'],
            ['name' => 'Other', 'slug' => 'other', 'icon' => 'more-horizontal'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['company_id' => $companyId, 'slug' => $cat['slug'], 'type' => 'service'],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'type' => 'service',
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'icon' => $cat['icon'],
                    'is_active' => true,
                ]
            );
        }
    }
}
```

### 6.2 Migrate Existing Service Products
```php
// Migration: set_is_physical_on_existing_products
// Set is_physical = false for existing products with type='service'
DB::table('products')
    ->where('type', 'service')
    ->update(['is_physical' => false, 'pricing_type' => 'fixed']);

DB::table('products')
    ->whereIn('type', ['part', 'consumable'])
    ->update(['is_physical' => true]);
```

---

## Phase 7: Integration with Document Lines

### 7.1 Document Line Price Calculation
```php
// When adding a service to a document line, calculate price based on pricing_type
// app/Modules/Document/Domain/Services/DocumentLineService.php

public function calculateLineTotal(Product $product, float $quantity): array
{
    $unitPrice = $product->sale_price;

    if ($product->pricing_type === PricingType::Hourly && $product->hourly_rate) {
        // For hourly services, quantity = hours worked
        $unitPrice = $product->hourly_rate;
    } elseif ($product->pricing_type === PricingType::FlatRate && $product->labor_hours && $product->hourly_rate) {
        // For flat rate, use book time
        $unitPrice = bcmul($product->labor_hours, $product->hourly_rate, 2);
    }

    return [
        'unit_price' => $unitPrice,
        'line_total' => bcmul($unitPrice, (string) $quantity, 2),
    ];
}
```

---

## Implementation Order

1. **Phase 1**: Database migrations (categories, tags, product extensions)
2. **Phase 2**: Domain models (Category, Tag, Product updates)
3. **Phase 3**: Backend API (routes, controllers, requests)
4. **Phase 4**: Sidebar navigation & translations
5. **Phase 5**: Frontend pages (list, form, detail, categories)
6. **Phase 6**: Data migration & seeders
7. **Phase 7**: Document line integration

---

## Testing Checklist

- [ ] Create service with fixed price
- [ ] Create service with hourly rate
- [ ] Create service with flat rate (book time)
- [ ] Assign category to service
- [ ] Add tags to service
- [ ] Filter services by category
- [ ] Filter services by tags
- [ ] Add service to quote/invoice
- [ ] Verify price calculation for each pricing type
- [ ] Category CRUD operations
- [ ] Tag CRUD operations
- [ ] Service appears in product search on documents

---

## Future Enhancements

1. **Labor Guide Integration**: Connect to Mitchell 1 / MOTOR APIs for automatic labor times
2. **Service Packages**: Bundle multiple services together
3. **Service History**: Track service history per vehicle
4. **Technician Assignment**: Link services to technicians for work orders
5. **Warranty Tracking**: Track service warranties

---

## Sources

- [Service Management in Business Central](https://erpsoftwareblog.com/2025/11/business-central-service-management/)
- [Auto Repair Labor Rates by State 2025](https://myautogms.com/blog/auto-repair-labor-rates-by-state-2025-guide/)
- [Setting Your Auto Shop Labor Rates](https://blog.torque360.co/setting-your-auto-shop-labor-rates/)
- [ProDemand Estimating Guide](https://mitchell1.com/prodemand/estimating/)
