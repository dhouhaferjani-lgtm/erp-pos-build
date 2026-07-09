<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\DTOs\CategoryData;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Presentation\Requests\Concerns\ValidatesMarginBand;
use App\Shared\Presentation\Validation\ScopedExists;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    use PaginatesResults, ValidatesMarginBand;

    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);
        $companyId = $this->companyContext->requireCompanyId();

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
                $q->where('name', 'ilike', '%'.$request->input('search').'%');
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
        $companyId = $this->companyContext->requireCompanyId();

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

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function buildTree($categories, ?int $parentId = null): array
    {
        $branch = [];

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);

                $item = CategoryData::fromModel($category);
                $itemArray = (array) $item;
                $itemArray['children'] = $children;

                $branch[] = $itemArray;
            }
        }

        return $branch;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $category = Category::query()
            ->where('company_id', $companyId)
            ->withCount('products')
            ->findOrFail($id);

        return response()->json([
            'data' => CategoryData::fromModel($category, includeChildren: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;

        $validated = $request->validate([
            'parent_id' => ['nullable', ScopedExists::company('categories', $companyId)],
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            // api.product.tax-cat: Defense-in-depth coherence — mirrors composite-item
            // pattern (StoreCompositeItemRequest). A mismatched country_code on
            // default_tax_configuration_id is silently ignored by TaxCalculationService
            // (it selects by company.country_code at runtime), but storing incoherent
            // data is rejected here as a guard.
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'default_tax_configuration_id' => [
                'nullable', 'uuid', 'exists:tax_configurations,id',
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
            'target_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        if ($this->marginBandInverts($validated['minimum_margin_override'] ?? null, $validated['target_margin_override'] ?? null)) {
            throw ValidationException::withMessages([
                'minimum_margin_override' => [__('The minimum margin override must be less than or equal to the target margin override.')],
            ]);
        }

        // Verify parent belongs to same company
        // api.unmapped.018 (api.catalog): use Category::query() prefix so the
        // PhpAstFindScanner's chain visitor recognises the where('company_id')
        // predicate. The static-call form (`Category::where(...)->find()`)
        // bypasses chainIsScoped() because the visitor only checks
        // SCOPE_METHODS (forCompany/forTenant) on StaticCalls. categories has
        // no tenant_id column (company-scoped global reference); the
        // company_id predicate alone IS the cluster invariant.
        if (! empty($validated['parent_id'])) {
            $parent = Category::query()
                ->where('company_id', $companyId)
                ->find($validated['parent_id']);

            if (! $parent) {
                return response()->json(['error' => 'Parent category not found'], 404);
            }
        }

        $category = Category::create([
            'company_id' => $companyId,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'default_tax_rate' => $validated['default_tax_rate'] ?? null,
            'default_tax_configuration_id' => $validated['default_tax_configuration_id'] ?? null,
            'target_margin_override' => $validated['target_margin_override'] ?? null,
            'minimum_margin_override' => $validated['minimum_margin_override'] ?? null,
            'max_discount_percent' => $validated['max_discount_percent'] ?? null,
        ]);

        $category->refresh();

        return response()->json([
            'data' => CategoryData::fromModel($category),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;

        $category = Category::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $validated = $request->validate([
            'parent_id' => ['nullable', ScopedExists::company('categories', $companyId)],
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            // api.product.tax-cat: mirrors store() — same defense-in-depth country
            // coherence check on both create and update paths.
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'default_tax_configuration_id' => [
                'nullable', 'uuid', 'exists:tax_configurations,id',
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
            'target_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        if ($this->marginBandInverts($validated['minimum_margin_override'] ?? null, $validated['target_margin_override'] ?? null)) {
            throw ValidationException::withMessages([
                'minimum_margin_override' => [__('The minimum margin override must be less than or equal to the target margin override.')],
            ]);
        }

        // Prevent circular reference
        if (! empty($validated['parent_id'])) {
            if ($validated['parent_id'] == $category->id) {
                return response()->json(['error' => 'Category cannot be its own parent'], 422);
            }

            // api.unmapped.019 (api.catalog): same scanner-blind-spot guard as
            // store(); use Category::query() prefix so the chain visitor sees
            // the where('company_id') predicate.
            $newParent = Category::query()
                ->where('company_id', $companyId)
                ->find($validated['parent_id']);
            if ($newParent instanceof Category && $category->isAncestorOf($newParent)) {
                return response()->json(['error' => 'Cannot move category under its own descendant'], 422);
            }
        }

        $category->update($validated);
        $category->refresh();

        return response()->json([
            'data' => CategoryData::fromModel($category),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $category = Category::query()
            ->where('company_id', $companyId)
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
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => ['required', ScopedExists::company('categories', $companyId)],
            'categories.*.sort_order' => 'required|integer|min:0',
            'categories.*.parent_id' => ['nullable', ScopedExists::company('categories', $companyId)],
        ]);

        foreach ($validated['categories'] as $item) {
            Category::where('id', $item['id'])
                ->where('company_id', $companyId)
                ->update([
                    'sort_order' => $item['sort_order'],
                    'parent_id' => $item['parent_id'] ?? null,
                ]);
        }

        // Rebuild paths for affected categories — scope to this company so a
        // race that snuck a foreign id through could not still reparent
        // another company's categories.
        $categoryIds = array_column($validated['categories'], 'id');
        Category::whereIn('id', $categoryIds)
            ->where('company_id', $companyId)
            ->each(function ($cat) {
                $cat->updatePath();
                $cat->updateDescendantPaths();
            });

        return response()->json(['message' => 'Categories reordered successfully']);
    }
}
