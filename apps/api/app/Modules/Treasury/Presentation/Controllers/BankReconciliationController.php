<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\Services\BankReconciliationService;
use App\Modules\Treasury\Domain\BankReconciliation;
use App\Modules\Treasury\Domain\BankReconciliationItem;
use App\Modules\Treasury\Presentation\Requests\StartBankReconciliationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BankReconciliationService $reconciliationService,
    ) {}

    /**
     * List all reconciliations.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->with(['repository', 'creator'])
            ->orderByDesc('statement_date')
            ->orderByDesc('created_at');

        if ($request->has('repository_id')) {
            $query->where('repository_id', $request->input('repository_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $reconciliations = $query->get();

        return response()->json([
            'data' => $reconciliations->map(fn (BankReconciliation $rec) => $this->formatReconciliation($rec)),
        ]);
    }

    /**
     * Get a single reconciliation with items.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var BankReconciliation $reconciliation */
        $reconciliation = BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->with(['repository', 'items.payment.partner', 'creator', 'completer'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatReconciliationWithItems($reconciliation),
        ]);
    }

    /**
     * Start a new reconciliation session.
     */
    public function store(StartBankReconciliationRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        /** @var array{repository_id: string, statement_date: string, opening_balance?: string, statement_balance: string, notes?: string} $validated */
        $validated = $request->validated();

        $reconciliation = $this->reconciliationService->startReconciliation(
            $companyId,
            $tenantId,
            $userId,
            $validated
        );

        return response()->json([
            'data' => $this->formatReconciliationWithItems($reconciliation),
        ], 201);
    }

    /**
     * Match a payment item.
     */
    public function matchItem(Request $request, string $reconciliationId, string $paymentId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        // Verify reconciliation belongs to tenant
        BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($reconciliationId);

        $validated = $request->validate([
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->reconciliationService->matchItem(
            $reconciliationId,
            $paymentId,
            $userId,
            $validated['bank_reference'] ?? null,
            $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $this->formatItem($item),
        ]);
    }

    /**
     * Unmatch a payment item.
     */
    public function unmatchItem(Request $request, string $reconciliationId, string $paymentId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Verify reconciliation belongs to tenant
        BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($reconciliationId);

        $item = $this->reconciliationService->unmatchItem($reconciliationId, $paymentId);

        return response()->json([
            'data' => $this->formatItem($item),
        ]);
    }

    /**
     * Complete a reconciliation.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        // Verify reconciliation belongs to tenant
        BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $reconciliation = $this->reconciliationService->completeReconciliation($id, $userId);

        return response()->json([
            'data' => $this->formatReconciliationWithItems($reconciliation),
        ]);
    }

    /**
     * Cancel a reconciliation.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Verify reconciliation belongs to tenant
        BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $reconciliation = $this->reconciliationService->cancelReconciliation($id);

        return response()->json([
            'data' => $this->formatReconciliation($reconciliation),
        ]);
    }

    /**
     * Get reconciliation summary.
     */
    public function summary(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Verify reconciliation belongs to tenant
        BankReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $summary = $this->reconciliationService->getReconciliationSummary($id);

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReconciliation(BankReconciliation $reconciliation): array
    {
        return [
            'id' => $reconciliation->id,
            'repository_id' => $reconciliation->repository_id,
            'repository_name' => $reconciliation->repository->name,
            'statement_date' => $reconciliation->statement_date->toDateString(),
            'opening_balance' => $reconciliation->opening_balance,
            'closing_balance' => $reconciliation->closing_balance,
            'statement_balance' => $reconciliation->statement_balance,
            'difference' => $reconciliation->difference,
            'status' => $reconciliation->status->value,
            'created_by' => $reconciliation->creator->name,
            'completed_by' => $reconciliation->completer?->name,
            'completed_at' => $reconciliation->completed_at?->toIso8601String(),
            'notes' => $reconciliation->notes,
            'created_at' => $reconciliation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReconciliationWithItems(BankReconciliation $reconciliation): array
    {
        $data = $this->formatReconciliation($reconciliation);
        $data['items'] = $reconciliation->items->map(fn ($item) => $this->formatItem($item))->toArray();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatItem(BankReconciliationItem $item): array
    {
        return [
            'id' => $item->id,
            'payment_id' => $item->payment_id,
            'payment_date' => $item->payment->payment_date->toDateString(),
            'payment_reference' => $item->payment->reference,
            'payment_amount' => $item->payment->amount,
            'partner_name' => $item->payment->partner?->name,
            'is_matched' => $item->is_matched,
            'bank_reference' => $item->bank_reference,
            'notes' => $item->notes,
            'matched_at' => $item->matched_at?->toIso8601String(),
        ];
    }
}
