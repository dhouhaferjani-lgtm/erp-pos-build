<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Enum;

class CreditNoteController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CreditNoteService $creditNoteService,
    ) {}

    /**
     * Get credit notes for a specific invoice.
     *
     * GET /api/v1/credit-notes?source_invoice_id=uuid
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('type', DocumentType::CreditNote)
            ->with(['partner', 'sourceDocument']);

        // Filter by source invoice
        if ($request->has('source_invoice_id')) {
            $query->where('source_document_id', $request->input('source_invoice_id'));
        }

        $creditNotes = $query->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $creditNotes->map(fn (Document $cn) => $this->formatCreditNote($cn)),
        ]);
    }

    /**
     * Get a single credit note by ID.
     *
     * GET /api/v1/credit-notes/{id}
     */
    public function show(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $creditNote = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('type', DocumentType::CreditNote)
            ->with(['partner', 'sourceDocument', 'lines'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatCreditNote($creditNote),
        ]);
    }

    /**
     * Create a credit note from an invoice.
     *
     * POST /api/v1/credit-notes
     *
     * Request body:
     * {
     *   "source_invoice_id": "uuid",
     *   "amount": "1200.00",
     *   "reason": "return|price_adjustment|billing_error|damaged_goods|service_issue|other",
     *   "notes": "Optional description"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        // Determine if this is amount-based or line-based credit note
        $isLineBased = $request->has('lines') && is_array($request->input('lines'));

        $rules = [
            'source_invoice_id' => ['required', 'string', 'exists:documents,id'],
            'reason' => ['required', new Enum(CreditNoteReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        // Amount-based validation
        if (! $isLineBased) {
            $rules['amount'] = ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'];
        } else {
            // Line-based validation
            $rules['lines'] = ['required', 'array', 'min:1'];
            $rules['lines.*.line_id'] = ['required', 'string', 'exists:document_lines,id'];
            $rules['lines.*.quantity'] = ['required', 'numeric', 'gt:0'];
        }

        $validated = $request->validate($rules);

        try {
            if ($isLineBased) {
                // Line-based credit note
                $creditNote = $this->creditNoteService->createLineBasedCreditNote(
                    sourceInvoiceId: $validated['source_invoice_id'],
                    lines: $validated['lines'],
                    reason: CreditNoteReason::from($validated['reason']),
                    notes: $validated['notes'] ?? null
                );
            } else {
                // Amount-based credit note
                $creditNote = $this->creditNoteService->createCreditNote(
                    sourceInvoiceId: $validated['source_invoice_id'],
                    amount: $validated['amount'],
                    reason: CreditNoteReason::from($validated['reason']),
                    notes: $validated['notes'] ?? null
                );
            }

            return response()->json([
                'data' => $this->formatCreditNote($creditNote->load(['partner', 'sourceDocument'])),
                'message' => 'Credit note created successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Format credit note for API response.
     *
     * @return array<string, mixed>
     */
    private function formatCreditNote(Document $creditNote): array
    {
        return [
            'id' => $creditNote->id,
            'document_number' => $creditNote->document_number,
            'document_date' => $creditNote->document_date->toIso8601String(),
            'source_invoice_id' => $creditNote->source_document_id,
            'source_invoice_number' => $creditNote->sourceDocument?->document_number,
            'partner' => [
                'id' => $creditNote->partner->id,
                'name' => $creditNote->partner->name,
            ],
            'currency' => $creditNote->currency,
            'subtotal' => $creditNote->subtotal,
            'tax_amount' => $creditNote->tax_amount,
            'total' => $creditNote->total,
            /** @phpstan-ignore-next-line property.nonObject */
            'reason' => $creditNote->credit_note_reason?->value,
            /** @phpstan-ignore-next-line method.nonObject */
            'reason_label' => $creditNote->credit_note_reason?->label(),
            'notes' => $creditNote->notes,
            'status' => $creditNote->status->value,
            'created_at' => $creditNote->created_at?->toIso8601String(),
        ];
    }
}
