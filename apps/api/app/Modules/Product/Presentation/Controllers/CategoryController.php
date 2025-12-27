<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\DTOs\CategoryData;
use App\Modules\Product\Domain\Category;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

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
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

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
     * @param  \Illuminate\Database\Eloquent\Collection<int, Category>  $categories
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
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

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
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

        // Verify parent belongs to same company
        if (!empty($validated['parent_id'])) {
            $parent = Category::where('company_id', $companyId)
                ->find($validated['parent_id']);

            if (!$parent) {
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
        ]);

        return response()->json([
            'data' => CategoryData::fromModel($category->fresh()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

        $category = Category::query()
            ->where('company_id', $companyId)
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
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

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
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
            'categories.*.parent_id' => 'nullable|exists:categories,id',
        ]);

        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

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
