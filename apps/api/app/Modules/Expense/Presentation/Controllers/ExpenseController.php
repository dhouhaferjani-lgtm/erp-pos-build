<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Presentation\Requests\ExpenseRequest;
use App\Modules\Expense\Presentation\Resources\ExpenseResource;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for expense management endpoints.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Display a listing of expenses.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = Document::where('type', DocumentType::Expense)
            ->where('company_id', $companyId)
            ->with([
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ]);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->whereHas('expenseMetadata', function ($q) use ($request) {
                // @phpstan-ignore-next-line
                $q->where('expense_category_id', $request->input('category_id'));
            });
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('document_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('document_date', '<=', $request->input('date_to'));
        }

        // Search by vendor name or receipt number
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'ilike', "%{$search}%")
                    ->orWhereHas('expenseMetadata', function ($metaQuery) use ($search) {
                        // @phpstan-ignore-next-line
                        $metaQuery->where('vendor_name', 'ilike', "%{$search}%")
                            // @phpstan-ignore-next-line
                            ->orWhere('receipt_number', 'ilike', "%{$search}%");
                    });
            });
        }

        $expenses = $query->latest('document_date')
            ->latest('created_at')
            ->paginate($request->input('per_page', 20));

        return ExpenseResource::collection($expenses);
    }

    /**
     * Store a newly created expense.
     */
    public function store(ExpenseRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $data = array_merge(
            $request->validated(),
            [
                'company_id' => $companyId,
            ]
        );

        /** @var User $user */
        $user = $request->user();

        $expense = $this->expenseService->create($data, $user);

        return response()->json([
            'message' => __('messages.created', ['resource' => 'Expense']),
            'data' => new ExpenseResource($expense->load([
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ])),
        ], 201);
    }

    /**
     * Display the specified expense.
     */
    public function show(Request $request, string $id): ExpenseResource
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with([
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
                'company',
            ])
            ->firstOrFail();

        Gate::authorize('view', $expense);

        return new ExpenseResource($expense);
    }

    /**
     * Update the specified expense.
     */
    public function update(ExpenseRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        Gate::authorize('update', $expense);

        if ($expense->status !== DocumentStatus::Draft) {
            return response()->json([
                'error' => __('messages.cannot_edit_posted_document'),
            ], 422);
        }

        $expense = $this->expenseService->update($expense, $request->validated());

        return response()->json([
            'message' => __('messages.updated', ['resource' => 'Expense']),
            'data' => new ExpenseResource($expense->load([
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ])),
        ]);
    }

    /**
     * Remove the specified expense.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        Gate::authorize('delete', $expense);

        if ($expense->status !== DocumentStatus::Draft) {
            return response()->json([
                'error' => __('messages.cannot_delete_posted_document'),
            ], 422);
        }

        $expense->delete();

        return response()->json([
            'message' => __('messages.deleted', ['resource' => 'Expense']),
        ]);
    }

    /**
     * Post the expense (finalize and create GL entries).
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('expenseMetadata')
            ->firstOrFail();

        Gate::authorize('post', $expense);

        if ($expense->status === DocumentStatus::Posted) {
            return response()->json([
                'error' => __('messages.document_already_posted'),
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        $expense = $this->expenseService->post($expense, $user);

        return response()->json([
            'message' => __('messages.expense_posted'),
            'data' => new ExpenseResource($expense->load([
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ])),
        ]);
    }
}
