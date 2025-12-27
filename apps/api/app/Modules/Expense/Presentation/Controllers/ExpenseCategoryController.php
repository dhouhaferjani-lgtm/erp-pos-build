<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Presentation\Requests\ExpenseCategoryRequest;
use App\Modules\Expense\Presentation\Resources\ExpenseCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for expense category management endpoints.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Display a listing of expense categories.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = ExpenseCategory::where('company_id', $companyId)
            ->with(['parent', 'children', 'account']);

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by parent (root categories only)
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        // Filter by specific parent
        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        // Search by name
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        $categories = $query->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ExpenseCategoryResource::collection($categories);
    }

    /**
     * Store a newly created expense category.
     */
    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        $category = ExpenseCategory::create(array_merge(
            $request->validated(),
            [
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
            ]
        ));

        return response()->json([
            'message' => __('messages.created', ['resource' => 'Expense Category']),
            'data' => new ExpenseCategoryResource($category->load(['parent', 'account'])),
        ], 201);
    }

    /**
     * Display the specified expense category.
     */
    public function show(Request $request, string $id): ExpenseCategoryResource
    {
        $companyId = $this->companyContext->requireCompanyId();

        $category = ExpenseCategory::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['parent', 'children', 'account', 'company'])
            ->firstOrFail();

        Gate::authorize('view', $category);

        return new ExpenseCategoryResource($category);
    }

    /**
     * Update the specified expense category.
     */
    public function update(ExpenseCategoryRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $category = ExpenseCategory::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        Gate::authorize('update', $category);

        // Prevent circular references in hierarchy
        if ($request->filled('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($this->wouldCreateCircularReference($category->id, $parentId)) {
                return response()->json([
                    'error' => __('messages.circular_reference_not_allowed'),
                ], 422);
            }
        }

        $category->update($request->validated());

        return response()->json([
            'message' => __('messages.updated', ['resource' => 'Expense Category']),
            'data' => new ExpenseCategoryResource($category->load(['parent', 'account'])),
        ]);
    }

    /**
     * Remove the specified expense category.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $category = ExpenseCategory::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('children')
            ->firstOrFail();

        Gate::authorize('delete', $category);

        if ($category->children_count > 0) {
            return response()->json([
                'error' => __('messages.cannot_delete_category_with_children'),
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => __('messages.deleted', ['resource' => 'Expense Category']),
        ]);
    }

    /**
     * Check if setting a parent would create a circular reference.
     */
    private function wouldCreateCircularReference(string $categoryId, string $parentId): bool
    {
        $current = ExpenseCategory::find($parentId);

        while ($current !== null) {
            if ($current->id === $categoryId) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }
}
