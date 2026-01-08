# Pagination & Categories Implementation

**Context:** Core ERP system needs proper pagination across all list endpoints and a category system for product organization. These are foundational features required before module development.

---

## Part A: Pagination

### A1. Requirements

**Problem:** Currently all list endpoints return unbounded results. With 10,000+ products, this causes:
- Slow API responses
- High memory usage
- Poor UX (endless scrolling, no navigation)
- Browser performance issues

**Solution:** Implement cursor-based pagination with consistent patterns across all modules.

### A2. Pagination Strategy

**Use cursor-based pagination** (not offset-based) because:
- Consistent results when data changes between requests
- Better performance on large datasets
- Works well with real-time updates

Laravel provides `cursorPaginate()` out of the box.

### A3. Backend Implementation

#### Standard Pagination Response Format

All paginated endpoints must return this structure:

```json
{
  "data": [...],
  "meta": {
    "per_page": 25,
    "total": 1847,
    "has_more": true
  },
  "links": {
    "next": "?cursor=eyJpZCI6MjUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
    "prev": null
  }
}
```

#### Create Pagination Trait

**File:** `app/Support/Traits/PaginatesResults.php`

```php
<?php

namespace App\Support\Traits;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

trait PaginatesResults
{
    protected function getPaginationParams(Request $request): array
    {
        return [
            'per_page' => min((int) $request->input('per_page', 25), 100),
            'cursor' => $request->input('cursor'),
        ];
    }

    protected function formatPaginatedResponse(CursorPaginator $paginator, string $dataClass = null): array
    {
        $items = $paginator->items();
        
        // Transform items if DTO class provided
        if ($dataClass && method_exists($dataClass, 'fromModel')) {
            $items = collect($items)->map(fn ($item) => $dataClass::fromModel($item))->all();
        }

        return [
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                // Note: cursor pagination doesn't provide total by default
                // Include only if explicitly requested and query is simple
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ];
    }
}
```

#### Update Controllers

**Example: ProductController**

```php
<?php

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Support\Traits\PaginatesResults;

class ProductController extends Controller
{
    use PaginatesResults;

    public function index(Request $request)
    {
        $params = $this->getPaginationParams($request);
        
        $query = Product::query()
            ->where('company_id', $request->user()->current_company_id)
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%' . $request->input('search') . '%')
                      ->orWhere('sku', 'ilike', '%' . $request->input('search') . '%')
                      ->orWhere('barcode', $request->input('search'));
                });
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $q->where('category_id', $request->input('category_id'));
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('name');

        $paginator = $query->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        return response()->json(
            $this->formatPaginatedResponse($paginator, ProductData::class)
        );
    }
}
```

#### Endpoints to Update

Apply pagination to ALL list endpoints:

| Module | Endpoint | Priority |
|--------|----------|----------|
| Catalog | `GET /products` | 🔴 High |
| Catalog | `GET /categories` | 🔴 High |
| Customer | `GET /customers` | 🔴 High |
| Document | `GET /documents` | 🔴 High |
| Document | `GET /invoices` | 🔴 High |
| Document | `GET /sales-orders` | 🔴 High |
| Document | `GET /delivery-notes` | 🔴 High |
| Document | `GET /credit-notes` | 🔴 High |
| Document | `GET /quotes` | 🔴 High |
| Inventory | `GET /stock-levels` | 🔴 High |
| Inventory | `GET /stock-movements` | 🟡 Medium |
| Treasury | `GET /payments` | 🟡 Medium |
| Accounting | `GET /journal-entries` | 🟡 Medium |
| Accounting | `GET /accounts` | 🟡 Medium |
| Identity | `GET /users` | 🟡 Medium |

### A4. Frontend Implementation

#### Create Pagination Hook

**File:** `apps/web/src/hooks/usePagination.ts`

```typescript
import { useState, useCallback } from 'react';

interface PaginationState {
  cursor: string | null;
  perPage: number;
  hasMore: boolean;
  isLoading: boolean;
}

interface PaginationLinks {
  next: string | null;
  prev: string | null;
}

interface UsePaginationOptions {
  initialPerPage?: number;
}

export function usePagination(options: UsePaginationOptions = {}) {
  const { initialPerPage = 25 } = options;

  const [state, setState] = useState<PaginationState>({
    cursor: null,
    perPage: initialPerPage,
    hasMore: false,
    isLoading: false,
  });

  const [links, setLinks] = useState<PaginationLinks>({
    next: null,
    prev: null,
  });

  const [history, setHistory] = useState<string[]>([]);

  const updateFromResponse = useCallback((meta: any, responseLinks: PaginationLinks) => {
    setState(prev => ({
      ...prev,
      hasMore: meta.has_more ?? false,
      isLoading: false,
    }));
    setLinks(responseLinks);
  }, []);

  const goToNext = useCallback(() => {
    if (links.next) {
      setHistory(prev => [...prev, state.cursor || '']);
      setState(prev => ({ ...prev, cursor: links.next, isLoading: true }));
    }
  }, [links.next, state.cursor]);

  const goToPrev = useCallback(() => {
    if (history.length > 0) {
      const newHistory = [...history];
      const prevCursor = newHistory.pop() || null;
      setHistory(newHistory);
      setState(prev => ({ ...prev, cursor: prevCursor, isLoading: true }));
    }
  }, [history]);

  const reset = useCallback(() => {
    setState(prev => ({ ...prev, cursor: null, isLoading: true }));
    setHistory([]);
  }, []);

  const setPerPage = useCallback((perPage: number) => {
    setState(prev => ({ ...prev, perPage, cursor: null, isLoading: true }));
    setHistory([]);
  }, []);

  return {
    cursor: state.cursor,
    perPage: state.perPage,
    hasMore: state.hasMore,
    isLoading: state.isLoading,
    hasPrev: history.length > 0,
    hasNext: !!links.next,
    goToNext,
    goToPrev,
    reset,
    setPerPage,
    updateFromResponse,
    // For building query params
    getQueryParams: () => ({
      per_page: state.perPage,
      ...(state.cursor && { cursor: state.cursor }),
    }),
  };
}
```

#### Create Pagination Component

**File:** `apps/web/src/components/ui/Pagination.tsx`

```typescript
import React from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from './Button';
import { Select } from './Select';

interface PaginationProps {
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  perPage: number;
  onPerPageChange: (perPage: number) => void;
  isLoading?: boolean;
}

const PER_PAGE_OPTIONS = [10, 25, 50, 100];

export function Pagination({
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  perPage,
  onPerPageChange,
  isLoading = false,
}: PaginationProps) {
  return (
    <div className="flex items-center justify-between px-4 py-3 border-t">
      <div className="flex items-center gap-2">
        <span className="text-sm text-gray-600">Rows per page:</span>
        <Select
          value={perPage.toString()}
          onValueChange={(value) => onPerPageChange(Number(value))}
          disabled={isLoading}
        >
          {PER_PAGE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </Select>
      </div>

      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          onClick={onPrev}
          disabled={!hasPrev || isLoading}
        >
          <ChevronLeft className="h-4 w-4" />
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={onNext}
          disabled={!hasNext || isLoading}
        >
          Next
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
```

#### Update List Components

**Example: ProductList.tsx**

```typescript
import { usePagination } from '@/hooks/usePagination';
import { Pagination } from '@/components/ui/Pagination';
import { useProducts } from '@/api/products';

export function ProductList() {
  const pagination = usePagination({ initialPerPage: 25 });
  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState<string | null>(null);

  const { data, isLoading, error } = useProducts({
    ...pagination.getQueryParams(),
    search: search || undefined,
    category_id: categoryId || undefined,
  });

  // Update pagination state when data arrives
  useEffect(() => {
    if (data) {
      pagination.updateFromResponse(data.meta, data.links);
    }
  }, [data]);

  // Reset pagination when filters change
  useEffect(() => {
    pagination.reset();
  }, [search, categoryId]);

  return (
    <div>
      {/* Filters */}
      <div className="p-4 border-b">
        <SearchInput value={search} onChange={setSearch} />
        <CategoryFilter value={categoryId} onChange={setCategoryId} />
      </div>

      {/* Table */}
      <ProductTable 
        products={data?.data ?? []} 
        isLoading={isLoading} 
      />

      {/* Pagination */}
      <Pagination
        hasPrev={pagination.hasPrev}
        hasNext={pagination.hasNext}
        onPrev={pagination.goToPrev}
        onNext={pagination.goToNext}
        perPage={pagination.perPage}
        onPerPageChange={pagination.setPerPage}
        isLoading={isLoading}
      />
    </div>
  );
}
```

### A5. API Client Updates

Update API client functions to support pagination params:

```typescript
// apps/web/src/api/products.ts

interface GetProductsParams {
  per_page?: number;
  cursor?: string | null;
  search?: string;
  category_id?: string;
  is_active?: boolean;
}

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    per_page: number;
    has_more: boolean;
    total?: number;
  };
  links: {
    next: string | null;
    prev: string | null;
  };
}

export async function getProducts(params: GetProductsParams): Promise<PaginatedResponse<Product>> {
  const searchParams = new URLSearchParams();
  
  if (params.per_page) searchParams.set('per_page', params.per_page.toString());
  if (params.cursor) searchParams.set('cursor', params.cursor);
  if (params.search) searchParams.set('search', params.search);
  if (params.category_id) searchParams.set('category_id', params.category_id);
  if (params.is_active !== undefined) searchParams.set('is_active', params.is_active.toString());

  const response = await api.get(`/products?${searchParams.toString()}`);
  return response.data;
}

// React Query hook
export function useProducts(params: GetProductsParams) {
  return useQuery({
    queryKey: ['products', params],
    queryFn: () => getProducts(params),
    keepPreviousData: true, // Smooth transitions between pages
  });
}
```

---

## Part B: Categories

### B1. Requirements

Products need hierarchical organization:
- Parent/child category relationships
- Unlimited depth (but recommend max 3-4 levels for UX)
- Category-based filtering and navigation
- Breadcrumb support

### B2. Database Schema

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_categories_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            
            // Materialized path for efficient tree queries
            // e.g., "1/5/12" means: Root(1) > Child(5) > Grandchild(12)
            $table->string('path')->default('')->index();
            $table->unsignedInteger('depth')->default(0);
            
            // For ordering within same parent
            $table->unsignedInteger('sort_order')->default(0);
            
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            // Unique slug within company
            $table->unique(['company_id', 'slug']);
            
            // Index for tree queries
            $table->index(['company_id', 'parent_id', 'sort_order']);
            $table->index(['company_id', 'path']);
        });

        // Add category_id to products
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                  ->nullable()
                  ->after('company_id')
                  ->constrained('categories')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
```

### B3. Category Model

**File:** `app/Modules/Catalog/Domain/Category.php`

```php
<?php

namespace App\Modules\Catalog\Domain;

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'path',
        'depth',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'depth' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::created(function (Category $category) {
            $category->updatePath();
        });

        static::updated(function (Category $category) {
            if ($category->wasChanged('parent_id')) {
                $category->updatePath();
                $category->updateDescendantPaths();
            }
        });
    }

    // Relationships

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Tree Methods

    public function updatePath(): void
    {
        if ($this->parent_id) {
            $parent = $this->parent;
            $this->path = $parent->path ? "{$parent->path}/{$this->id}" : (string) $this->id;
            $this->depth = $parent->depth + 1;
        } else {
            $this->path = (string) $this->id;
            $this->depth = 0;
        }
        $this->saveQuietly();
    }

    public function updateDescendantPaths(): void
    {
        foreach ($this->children as $child) {
            $child->updatePath();
            $child->updateDescendantPaths();
        }
    }

    public function getAncestors(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->path)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $ancestorIds = explode('/', $this->path);
        array_pop($ancestorIds); // Remove self

        if (empty($ancestorIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return static::whereIn('id', $ancestorIds)
            ->orderByRaw("POSITION(id::text IN ?)", [$this->path])
            ->get();
    }

    public function getDescendants(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('path', 'like', $this->path . '/%')
            ->orderBy('path')
            ->get();
    }

    public function getDescendantIds(): array
    {
        return $this->getDescendants()->pluck('id')->all();
    }

    public function getBreadcrumb(): array
    {
        $ancestors = $this->getAncestors();
        $breadcrumb = $ancestors->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'slug' => $cat->slug,
        ])->all();

        $breadcrumb[] = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];

        return $breadcrumb;
    }

    public function isAncestorOf(Category $category): bool
    {
        return str_starts_with($category->path, $this->path . '/');
    }

    public function isDescendantOf(Category $category): bool
    {
        return str_starts_with($this->path, $category->path . '/');
    }

    // Query Scopes

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithProductCount($query)
    {
        return $query->withCount('products');
    }

    // Helpers

    public function getFullPath(): string
    {
        return $this->getAncestors()
            ->pluck('name')
            ->push($this->name)
            ->implode(' > ');
    }
}
```

### B4. Category DTO

**File:** `app/Modules/Catalog/Application/DTOs/CategoryData.php`

```php
<?php

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Category;

readonly class CategoryData
{
    public function __construct(
        public int $id,
        public int $company_id,
        public ?int $parent_id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $image_url,
        public string $path,
        public int $depth,
        public int $sort_order,
        public bool $is_active,
        public ?int $products_count,
        public ?array $breadcrumb,
        public ?array $children,
    ) {}

    public static function fromModel(Category $category, bool $includeChildren = false): self
    {
        return new self(
            id: $category->id,
            company_id: $category->company_id,
            parent_id: $category->parent_id,
            name: $category->name,
            slug: $category->slug,
            description: $category->description,
            image_url: $category->image_path ? asset('storage/' . $category->image_path) : null,
            path: $category->path,
            depth: $category->depth,
            sort_order: $category->sort_order,
            is_active: $category->is_active,
            products_count: $category->products_count ?? null,
            breadcrumb: $category->getBreadcrumb(),
            children: $includeChildren 
                ? $category->children->map(fn ($c) => self::fromModel($c, true))->all()
                : null,
        );
    }
}
```

### B5. Category Controller

**File:** `app/Modules/Catalog/Presentation/Controllers/CategoryController.php`

```php
<?php

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\DTOs\CategoryData;
use App\Modules\Catalog\Domain\Category;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);
        $companyId = $request->user()->current_company_id;

        $query = Category::query()
            ->where('company_id', $companyId)
            ->withCount('products')
            ->when($request->filled('parent_id'), function ($q) use ($request) {
                if ($request->input('parent_id') === 'root') {
                    $q->whereNull('parent_id');
                } else {
                    $q->where('parent_id', $request->input('parent_id'));
                }
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->input('search') . '%');
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        $paginator = $query->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        return response()->json(
            $this->formatPaginatedResponse($paginator, CategoryData::class)
        );
    }

    public function tree(Request $request): JsonResponse
    {
        $companyId = $request->user()->current_company_id;

        // Get all categories and build tree in memory
        // More efficient than recursive queries for reasonable category counts
        $categories = Category::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $tree = $this->buildTree($categories);

        return response()->json(['data' => $tree]);
    }

    private function buildTree($categories, $parentId = null): array
    {
        $branch = [];

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);
                
                $item = CategoryData::fromModel($category);
                $item = (array) $item;
                $item['children'] = $children;
                
                $branch[] = $item;
            }
        }

        return $branch;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $category = Category::query()
            ->where('company_id', $request->user()->current_company_id)
            ->withCount('products')
            ->findOrFail($id);

        return response()->json([
            'data' => CategoryData::fromModel($category, includeChildren: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        // Verify parent belongs to same company
        if (!empty($validated['parent_id'])) {
            $parent = Category::where('company_id', $request->user()->current_company_id)
                ->find($validated['parent_id']);
            
            if (!$parent) {
                return response()->json(['error' => 'Parent category not found'], 404);
            }
        }

        $category = Category::create([
            'company_id' => $request->user()->current_company_id,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'data' => CategoryData::fromModel($category->fresh()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::query()
            ->where('company_id', $request->user()->current_company_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        // Prevent circular reference
        if (!empty($validated['parent_id'])) {
            if ($validated['parent_id'] == $category->id) {
                return response()->json(['error' => 'Category cannot be its own parent'], 422);
            }

            $newParent = Category::find($validated['parent_id']);
            if ($newParent && $category->isAncestorOf($newParent)) {
                return response()->json(['error' => 'Cannot move category under its own descendant'], 422);
            }
        }

        $category->update($validated);

        return response()->json([
            'data' => CategoryData::fromModel($category->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::query()
            ->where('company_id', $request->user()->current_company_id)
            ->findOrFail($id);

        // Check if has products
        if ($category->products()->exists()) {
            return response()->json([
                'error' => 'Cannot delete category with products. Move or delete products first.',
            ], 422);
        }

        // Check if has children
        if ($category->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete category with subcategories. Delete subcategories first.',
            ], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
            'categories.*.parent_id' => 'nullable|exists:categories,id',
        ]);

        $companyId = $request->user()->current_company_id;

        foreach ($validated['categories'] as $item) {
            Category::where('id', $item['id'])
                ->where('company_id', $companyId)
                ->update([
                    'sort_order' => $item['sort_order'],
                    'parent_id' => $item['parent_id'] ?? null,
                ]);
        }

        // Rebuild paths for affected categories
        $categoryIds = collect($validated['categories'])->pluck('id');
        Category::whereIn('id', $categoryIds)->each(function ($cat) {
            $cat->updatePath();
            $cat->updateDescendantPaths();
        });

        return response()->json(['message' => 'Categories reordered successfully']);
    }
}
```

### B6. Routes

**File:** `app/Modules/Catalog/Presentation/routes.php` (add to existing)

```php
// Categories
Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/tree', [CategoryController::class, 'tree']);
    Route::post('/', [CategoryController::class, 'store']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::put('/{id}', [CategoryController::class, 'update']);
    Route::delete('/{id}', [CategoryController::class, 'destroy']);
    Route::post('/reorder', [CategoryController::class, 'reorder']);
});
```

### B7. Update Product Model

**File:** `app/Modules/Catalog/Domain/Product.php` (add relationship)

```php
public function category(): BelongsTo
{
    return $this->belongsTo(Category::class);
}
```

### B8. Frontend Category Components

#### Category Tree Component

**File:** `apps/web/src/components/catalog/CategoryTree.tsx`

```typescript
import React, { useState } from 'react';
import { ChevronRight, ChevronDown, Folder, FolderOpen } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Category {
  id: number;
  name: string;
  slug: string;
  products_count: number | null;
  children: Category[];
}

interface CategoryTreeProps {
  categories: Category[];
  selectedId?: number | null;
  onSelect: (category: Category) => void;
  expandedIds?: number[];
}

export function CategoryTree({ 
  categories, 
  selectedId, 
  onSelect,
  expandedIds: initialExpanded = [],
}: CategoryTreeProps) {
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set(initialExpanded));

  const toggleExpand = (id: number, e: React.MouseEvent) => {
    e.stopPropagation();
    setExpandedIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const renderCategory = (category: Category, level: number = 0) => {
    const hasChildren = category.children && category.children.length > 0;
    const isExpanded = expandedIds.has(category.id);
    const isSelected = selectedId === category.id;

    return (
      <div key={category.id}>
        <div
          className={cn(
            'flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-gray-100',
            isSelected && 'bg-blue-50 text-blue-700',
          )}
          style={{ paddingLeft: `${level * 16 + 8}px` }}
          onClick={() => onSelect(category)}
        >
          {hasChildren ? (
            <button
              onClick={(e) => toggleExpand(category.id, e)}
              className="p-0.5 hover:bg-gray-200 rounded"
            >
              {isExpanded ? (
                <ChevronDown className="h-4 w-4" />
              ) : (
                <ChevronRight className="h-4 w-4" />
              )}
            </button>
          ) : (
            <span className="w-5" />
          )}
          
          {isExpanded ? (
            <FolderOpen className="h-4 w-4 text-yellow-500" />
          ) : (
            <Folder className="h-4 w-4 text-yellow-500" />
          )}
          
          <span className="flex-1 truncate">{category.name}</span>
          
          {category.products_count != null && (
            <span className="text-xs text-gray-400">
              {category.products_count}
            </span>
          )}
        </div>

        {hasChildren && isExpanded && (
          <div>
            {category.children.map(child => renderCategory(child, level + 1))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="py-2">
      {categories.map(category => renderCategory(category))}
    </div>
  );
}
```

#### Category Select Component

**File:** `apps/web/src/components/catalog/CategorySelect.tsx`

```typescript
import React from 'react';
import { useCategories } from '@/api/categories';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/Select';

interface CategorySelectProps {
  value?: number | null;
  onChange: (categoryId: number | null) => void;
  placeholder?: string;
  allowClear?: boolean;
}

export function CategorySelect({
  value,
  onChange,
  placeholder = 'Select category',
  allowClear = true,
}: CategorySelectProps) {
  const { data, isLoading } = useCategories({ tree: true });

  const flattenCategories = (categories: any[], depth = 0): { id: number; name: string; depth: number }[] => {
    return categories.flatMap(cat => [
      { id: cat.id, name: cat.name, depth },
      ...flattenCategories(cat.children || [], depth + 1),
    ]);
  };

  const flatCategories = data ? flattenCategories(data.data) : [];

  return (
    <Select
      value={value?.toString() ?? ''}
      onValueChange={(val) => onChange(val ? Number(val) : null)}
    >
      <SelectTrigger>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {allowClear && (
          <SelectItem value="">
            <span className="text-gray-400">No category</span>
          </SelectItem>
        )}
        {flatCategories.map(cat => (
          <SelectItem key={cat.id} value={cat.id.toString()}>
            <span style={{ paddingLeft: `${cat.depth * 12}px` }}>
              {cat.name}
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
```

### B9. Category Factory

**File:** `database/factories/CategoryFactory.php`

```php
<?php

namespace Database\Factories;

use App\Modules\Catalog\Domain\Category;
use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        
        return [
            'company_id' => Company::factory(),
            'parent_id' => null,
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => $this->faker->optional()->sentence(),
            'path' => '',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state([
            'company_id' => $parent->company_id,
            'parent_id' => $parent->id,
        ]);
    }
}
```

### B10. Tests

**File:** `tests/Feature/Catalog/CategoryTest.php`

```php
<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Category;
use App\Modules\Catalog\Domain\Product;
use App\Modules\Company\Domain\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
    }

    public function test_can_list_categories_with_pagination(): void
    {
        Category::factory()->count(30)->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/categories?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'depth']],
                'meta' => ['per_page', 'has_more'],
                'links' => ['next', 'prev'],
            ]);
    }

    public function test_can_get_category_tree(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($parent)->create();
        $grandchild = Category::factory()->childOf($child)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/categories/tree');

        $response->assertOk();
        
        $tree = $response->json('data');
        $this->assertCount(1, $tree);
        $this->assertEquals($parent->id, $tree[0]['id']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertEquals($child->id, $tree[0]['children'][0]['id']);
    }

    public function test_can_create_category(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/categories', [
                'name' => 'Electronics',
                'description' => 'Electronic products',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', [
            'company_id' => $this->company->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
    }

    public function test_can_create_child_category(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/categories', [
                'name' => 'Smartphones',
                'parent_id' => $parent->id,
            ]);

        $response->assertCreated();
        
        $child = Category::find($response->json('data.id'));
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals(1, $child->depth);
        $this->assertStringStartsWith($parent->path, $child->path);
    }

    public function test_cannot_set_category_as_own_parent(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/categories/{$category->id}", [
                'parent_id' => $category->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_create_circular_reference(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($parent)->create();

        $response = $this->actingAs($this->user)
            ->putJson("/api/categories/{$parent->id}", [
                'parent_id' => $child->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_delete_category_with_products(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);
        Product::factory()->create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(422);
    }

    public function test_breadcrumb_is_correct(): void
    {
        $root = Category::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Electronics',
        ]);
        $child = Category::factory()->childOf($root)->create(['name' => 'Phones']);
        $grandchild = Category::factory()->childOf($child)->create(['name' => 'Smartphones']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/categories/{$grandchild->id}");

        $breadcrumb = $response->json('data.breadcrumb');
        
        $this->assertCount(3, $breadcrumb);
        $this->assertEquals('Electronics', $breadcrumb[0]['name']);
        $this->assertEquals('Phones', $breadcrumb[1]['name']);
        $this->assertEquals('Smartphones', $breadcrumb[2]['name']);
    }

    public function test_category_isolation_between_companies(): void
    {
        $otherCompany = Company::factory()->create();
        $otherCategory = Category::factory()->create(['company_id' => $otherCompany->id]);
        $ourCategory = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/categories');

        $ids = collect($response->json('data'))->pluck('id');
        
        $this->assertTrue($ids->contains($ourCategory->id));
        $this->assertFalse($ids->contains($otherCategory->id));
    }
}
```

---

## Part C: Verification

### C1. Backend Verification

```bash
# Run tests
php artisan test --filter=Category
php artisan test --filter=Pagination

# Check no N+1 queries (install Laravel Debugbar or use telescope)
# Verify cursor pagination on large datasets:
php artisan tinker
>>> Product::factory()->count(10000)->create(['company_id' => 1]);
>>> // Then test API response time
```

### C2. Frontend Verification

- [ ] Product list shows pagination controls
- [ ] Can navigate forward/backward through pages
- [ ] Changing per_page resets to first page
- [ ] Search/filter resets pagination
- [ ] Category tree loads and expands correctly
- [ ] Can create/edit categories
- [ ] Can assign category to product
- [ ] Breadcrumbs show correctly

### C3. Performance Targets

| Operation | Target |
|-----------|--------|
| List 25 products (paginated) | < 100ms |
| Category tree (500 categories) | < 200ms |
| Product search with filters | < 200ms |

---

## Summary

| Feature | Backend | Frontend | Tests |
|---------|---------|----------|-------|
| Cursor Pagination | Trait + Controller updates | Hook + Component | ✓ |
| Categories CRUD | Model + Controller + Routes | Tree + Select | ✓ |
| Product-Category relation | Migration + Model update | Form update | ✓ |
| Category tree | /tree endpoint | CategoryTree component | ✓ |
| Breadcrumbs | Model method | Display in UI | ✓ |
| Reordering | /reorder endpoint | Drag-drop (optional) | ✓ |
