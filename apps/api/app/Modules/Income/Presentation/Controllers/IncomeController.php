<?php

declare(strict_types=1);

namespace App\Modules\Income\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Application\Services\IncomeService;
use App\Modules\Income\Presentation\Requests\IncomeRequest;
use App\Modules\Income\Presentation\Resources\IncomeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Controller for income recording endpoints — mirror of ExpenseController.
 */
class IncomeController extends Controller
{
    public function __construct(
        private readonly IncomeService $incomeService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Display a listing of income records.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = Document::where('type', DocumentType::Income)
            ->where('company_id', $companyId)
            ->with([
                'incomeMetadata.incomeAccount',
                'incomeMetadata.paymentMethod',
                'incomeMetadata.paymentRepository',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('document_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('document_date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('document_number', 'ilike', "%{$search}%")
                    ->orWhereHas('incomeMetadata', function ($metaQuery) use ($search): void {
                        // @phpstan-ignore-next-line
                        $metaQuery->where('source_name', 'ilike', "%{$search}%")
                            // @phpstan-ignore-next-line
                            ->orWhere('reference_number', 'ilike', "%{$search}%");
                    });
            });
        }

        $income = $query->latest('document_date')
            ->latest('created_at')
            ->paginate($request->input('per_page', 20));

        return IncomeResource::collection($income);
    }

    /**
     * Store a newly created income record.
     */
    public function store(IncomeRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $data = array_merge(
            $request->validated(),
            ['company_id' => $companyId],
        );

        /** @var User $user */
        $user = $request->user();

        $income = $this->incomeService->create($data, $user);

        return response()->json([
            'message' => __('messages.created', ['resource' => 'Income']),
            'data' => new IncomeResource($income->load([
                'incomeMetadata.incomeAccount',
                'incomeMetadata.paymentMethod',
                'incomeMetadata.paymentRepository',
            ])),
        ], 201);
    }

    /**
     * Display the specified income record.
     */
    public function show(Request $request, string $id): IncomeResource
    {
        $companyId = $this->companyContext->requireCompanyId();

        $income = Document::where('type', DocumentType::Income)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with([
                'incomeMetadata.incomeAccount',
                'incomeMetadata.paymentMethod',
                'incomeMetadata.paymentRepository',
                'company',
            ])
            ->firstOrFail();

        return new IncomeResource($income);
    }

    /**
     * Update the specified draft income record.
     */
    public function update(IncomeRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $income = Document::where('type', DocumentType::Income)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('incomeMetadata')
            ->firstOrFail();

        if ($income->status !== DocumentStatus::Draft) {
            return response()->json([
                'error' => __('messages.cannot_edit_posted_document'),
            ], 422);
        }

        $income = $this->incomeService->update($income, $request->validated());

        return response()->json([
            'message' => __('messages.updated', ['resource' => 'Income']),
            'data' => new IncomeResource($income->load([
                'incomeMetadata.incomeAccount',
                'incomeMetadata.paymentMethod',
                'incomeMetadata.paymentRepository',
            ])),
        ]);
    }

    /**
     * Remove the specified draft income record.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $income = Document::where('type', DocumentType::Income)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        if ($income->status !== DocumentStatus::Draft) {
            return response()->json([
                'error' => __('messages.cannot_delete_posted_document'),
            ], 422);
        }

        $income->delete();

        return response()->json([
            'message' => __('messages.deleted', ['resource' => 'Income']),
        ]);
    }

    /**
     * Post the income (finalize, create GL entry, increment repository balance).
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $income = Document::where('type', DocumentType::Income)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with('incomeMetadata')
            ->firstOrFail();

        // Only Draft can be posted; any other status (Posted, Cancelled, …)
        // must return 422, not fall through to the service's RuntimeException.
        if ($income->status !== DocumentStatus::Draft) {
            return response()->json([
                'error' => __('messages.document_already_posted'),
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        $income = $this->incomeService->post($income, $user);

        return response()->json([
            'message' => __('messages.income_posted'),
            'data' => new IncomeResource($income->load([
                'incomeMetadata.incomeAccount',
                'incomeMetadata.paymentMethod',
                'incomeMetadata.paymentRepository',
            ])),
        ]);
    }
}
