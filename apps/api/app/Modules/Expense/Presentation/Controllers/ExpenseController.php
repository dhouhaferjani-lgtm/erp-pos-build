<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\DTOs\PayExpenseRequestData;
use App\Modules\Expense\Application\Exceptions\LinkedCostException;
use App\Modules\Expense\Application\Queries\ExpenseIndexQuery;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Presentation\Requests\ExpenseRequest;
use App\Modules\Expense\Presentation\Requests\PayExpenseRequest;
use App\Modules\Expense\Presentation\Resources\ExpenseResource;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\Document\OperationResolverInterface;
use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

/**
 * Controller for expense management endpoints.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly CompanyContext $companyContext,
        private readonly OperationResolverInterface $operationResolver,
        private readonly ExpenseIndexQuery $expenseIndexQuery,
        private readonly LocationScopeResolver $locationScopeResolver,
    ) {}

    /**
     * Display a listing of expenses.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locationIds = $this->resolveLocationIds($request, $companyId);
        $query = $this->expenseIndexQuery->build($request, $companyId, $locationIds)
            ->with([
                'partner' => $this->partnerForCompany($companyId),
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ]);

        $expenses = $query->latest('document_date')
            ->latest('created_at')
            ->paginate($request->input('per_page', 20));

        return ExpenseResource::collection($expenses);
    }

    /** @return list<string> */
    private function resolveLocationIds(Request $request, string $companyId): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $effective = $this->locationScopeResolver->resolve($user, $this->requestedLocationIds($request->input('location_ids')), null);
        $all = Location::query()->where('company_id', $companyId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return count(array_diff($all, $effective)) === 0 ? [] : $effective;
    }

    /** @return list<string> */
    private function requestedLocationIds(mixed $value): array
    {
        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $id): bool => is_string($id),
        ));
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

        try {
            $expense = $this->expenseService->create($data, $user);
        } catch (LinkedCostException $exception) {
            return $this->linkedCostError($exception);
        }

        return response()->json([
            'message' => __('messages.created', ['resource' => 'Expense']),
            'data' => new ExpenseResource($expense->load([
                'partner' => $this->partnerForCompany($companyId),
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
                'partner' => $this->partnerForCompany($companyId),
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
                'partner' => $this->partnerForCompany($companyId),
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

        try {
            $expense = $this->expenseService->post($expense, $user);
        } catch (LinkedCostException $exception) {
            return $this->linkedCostError($exception);
        }

        return response()->json([
            'message' => __('messages.expense_posted'),
            'data' => new ExpenseResource($expense->load([
                'partner' => $this->partnerForCompany($companyId),
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ])),
        ]);
    }

    /**
     * Settle a posted, unpaid expense (pay down the AP liability).
     */
    public function pay(PayExpenseRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            abort(404);
        }

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('expenseMetadata')
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        $data = PayExpenseRequestData::fromArray($request->validated());

        try {
            $expense = $this->expenseService->settle($expense, $data, $user);
        } catch (\DomainException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => __('messages.expense_settled'),
            'data' => new ExpenseResource($expense->load([
                'partner' => $this->partnerForCompany($companyId),
                'expenseMetadata.category',
                'expenseMetadata.paymentMethod',
                'expenseMetadata.paymentRepository',
            ])),
        ]);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expense = Document::where('type', DocumentType::Expense)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('expenseMetadata.paymentRepository')
            ->firstOrFail();

        Gate::authorize('post', $expense);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->expenseService->reverse($expense, $user);
        } catch (LinkedCostException $exception) {
            return $this->linkedCostError($exception);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * @return Closure(Relation<*, *, *>): void
     */
    private function partnerForCompany(string $companyId): Closure
    {
        return static function (Relation $relation) use ($companyId): void {
            if (! $relation instanceof BelongsTo || ! $relation->getRelated() instanceof Partner) {
                throw new LogicException('Expense partner eager load must use the partner relation.');
            }

            $relation->select(['id', 'name'])
                ->whereRaw('company_id = ?', [$companyId]);
        };
    }

    public function linkableInvoices(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $invoices = Document::query()
            ->where('company_id', $companyId)
            ->whereIn('type', [DocumentType::SupplierInvoice, DocumentType::SupplierCreditNote])
            ->whereNotNull('source_document_id')
            ->latest('document_date')
            ->limit(50)
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'document_number' => $document->document_number,
                'partner_name' => $document->partner->name,
                'document_date' => $document->document_date->toDateString(),
                'total' => $document->total,
                'currency' => $document->currency,
                'side' => 'purchase',
            ])
            ->values();

        return response()->json(['data' => $invoices]);
    }

    public function linkableOperations(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $request->validate(['invoice_id' => ['required', 'uuid']]);

        $invoice = Document::query()
            ->where('company_id', $companyId)
            ->whereKey($request->string('invoice_id')->toString())
            ->firstOrFail();

        try {
            $resolution = $this->operationResolver->resolve($invoice);
        } catch (LinkedCostException $exception) {
            return $this->linkedCostError($exception);
        }

        return response()->json(['data' => $resolution]);
    }

    private function linkedCostError(LinkedCostException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->codeName,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }
}
