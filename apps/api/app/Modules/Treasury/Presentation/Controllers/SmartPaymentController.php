<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Enum;

class SmartPaymentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PaymentToleranceService $toleranceService,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    /**
     * Get payment tolerance settings for the current company.
     *
     * GET /api/v1/smart-payment/tolerance-settings
     */
    public function getToleranceSettings(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $settings = $this->toleranceService->getToleranceSettings($companyId);

        return response()->json([
            'data' => $settings,
        ]);
    }

    /**
     * Preview payment allocation before applying.
     *
     * POST /api/v1/smart-payment/preview-allocation
     *
     * Request body:
     * {
     *   "partner_id": "uuid",
     *   "payment_amount": "1500.00",
     *   "allocation_method": "fifo|due_date|manual",
     *   "manual_allocations": [  // only for manual method
     *     {"document_id": "uuid", "amount": "500.00"},
     *     {"document_id": "uuid", "amount": "1000.00"}
     *   ]
     * }
     */
    public function previewAllocation(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $validated = $request->validate([
            'partner_id' => [
                'required',
                'string',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'payment_amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'allocation_method' => ['required', new Enum(AllocationMethod::class)],
            'manual_allocations' => ['nullable', 'array'],
            'manual_allocations.*.document_id' => [
                'required_with:manual_allocations',
                'string',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'manual_allocations.*.amount' => ['required_with:manual_allocations', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $preview = $this->allocationService->previewAllocation(
            companyId: $companyId,
            partnerId: $validated['partner_id'],
            paymentAmount: $validated['payment_amount'],
            allocationMethod: AllocationMethod::from($validated['allocation_method']),
            manualAllocations: $validated['manual_allocations'] ?? null
        );

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * Apply payment allocation to a payment.
     *
     * POST /api/v1/smart-payment/apply-allocation
     *
     * Request body:
     * {
     *   "payment_id": "uuid",
     *   "allocation_method": "fifo|due_date|manual",
     *   "manual_allocations": [  // only for manual method
     *     {"document_id": "uuid", "amount": "500.00"}
     *   ]
     * }
     */
    public function applyAllocation(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $validated = $request->validate([
            'payment_id' => [
                'required',
                'string',
                ScopedExists::tenantAndCompany('payments', $tenantId, $companyId),
            ],
            'allocation_method' => ['required', new Enum(AllocationMethod::class)],
            'manual_allocations' => ['nullable', 'array'],
            'manual_allocations.*.document_id' => [
                'required_with:manual_allocations',
                'string',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'manual_allocations.*.amount' => ['required_with:manual_allocations', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $result = $this->allocationService->applyAllocation(
            paymentId: $validated['payment_id'],
            allocationMethod: AllocationMethod::from($validated['allocation_method']),
            manualAllocations: $validated['manual_allocations'] ?? null
        );

        return response()->json([
            'data' => $result,
            'message' => 'Payment allocation applied successfully',
        ]);
    }

    /**
     * Get open invoices for a partner (for payment allocation).
     *
     * GET /api/v1/partners/{partner}/open-invoices
     *
     * Returns invoices with status 'posted' that have remaining balance.
     */
    public function getOpenInvoices(string $partnerId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        // Opus round-4 Finding 15 — resolve the partner under BOTH tenant_id
        // AND company_id (cluster invariant Codex established in round-3
        // Finding 14: BOTH tenant_id AND company_id on every read whose
        // anchor came from a route param). The service-tier read in
        // PaymentAllocationService::getOpenInvoices is similarly hardened
        // for defense in depth.
        $partner = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('id', $partnerId)
            ->first();

        if (! $partner) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
            ], 404);
        }

        // Get invoices with outstanding balance (posted, not fully paid)
        $openInvoices = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            ->whereRaw('COALESCE(balance_due, total) > 0')
            ->orderBy('document_date', 'asc')
            ->orderBy('document_number', 'asc')
            ->get();

        $data = $openInvoices->map(function (Document $invoice) {
            /** @var string $total */
            $total = $invoice->total;

            /** @var string $balanceDue */
            $balanceDue = $invoice->balance_due ?? $total;

            // Calculate days overdue
            $dueDate = $invoice->due_date ?? $invoice->document_date;
            $daysOverdue = 0;
            if ($dueDate->isPast()) {
                $daysOverdue = (int) $dueDate->diffInDays(now());
            }

            return [
                'id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'document_date' => $invoice->document_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $total,
                'balance_due' => $balanceDue,
                'days_overdue' => $daysOverdue,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
