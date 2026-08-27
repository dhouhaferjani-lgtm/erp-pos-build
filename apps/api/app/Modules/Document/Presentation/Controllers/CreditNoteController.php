<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

class CreditNoteController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CreditNoteService $creditNoteService,
        private readonly DocumentPostingService $postingService,
        private readonly DocumentStatusService $documentStatusService,
        private readonly TaxCalculationService $taxCalculationService,
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
     * Create a credit note.
     *
     * Supports two modes:
     * 1. Invoice-linked: References a source invoice (partial or full credit)
     * 2. Standalone: Direct credit to customer (no invoice reference)
     *
     * POST /api/v1/credit-notes
     *
     * Invoice-linked mode (amount-based):
     * {
     *   "source_invoice_id": "uuid",
     *   "amount": "1200.00",
     *   "reason": "return",
     *   "notes": "Optional"
     * }
     *
     * Invoice-linked mode (line-based):
     * {
     *   "source_invoice_id": "uuid",
     *   "lines": [{"line_id": "uuid", "quantity": 2}],
     *   "reason": "return",
     *   "notes": "Optional"
     * }
     *
     * Standalone mode (customer-based):
     * {
     *   "partner_id": "uuid",
     *   "lines": [
     *     {
     *       "product_id": "uuid",
     *       "description": "Compensation for mistake",
     *       "quantity": 1,
     *       "unit_price": "100.00",
     *       "tax_rate": "19.00"
     *     }
     *   ],
     *   "reason": "service_issue",
     *   "notes": "Compensation"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        // Determine credit note mode
        $hasSourceInvoice = $request->filled('source_invoice_id');
        $isLineBased = $request->has('lines') && is_array($request->input('lines'));

        $rules = [
            'reason' => ['required', new Enum(CreditNoteReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if ($hasSourceInvoice) {
            // Invoice-linked mode
            // api.document.001: tenant+company-scoped exists prevents a cross-tenant
            // source_invoice_id from satisfying the FK validator.
            $rules['source_invoice_id'] = ['required', 'string', ScopedExists::tenantAndCompany('documents', $tenantId, $companyId)];

            if (! $isLineBased) {
                // Amount-based
                $rules['amount'] = ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'];
            } else {
                // Line-based (partial)
                $rules['lines'] = ['required', 'array', 'min:1'];
                $rules['lines.*.line_id'] = ['required', 'string', 'exists:document_lines,id'];
                $rules['lines.*.quantity'] = ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'];
            }
        } else {
            // Standalone mode (customer-based)
            // api.document.002: scope partner_id by tenant + company.
            $rules['partner_id'] = ['required', 'string', ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)];
            $rules['lines'] = ['required', 'array', 'min:1'];
            // api.document.003: scope per-line product_id (nullable) by tenant + company.
            $rules['lines.*.product_id'] = ['nullable', 'string', ScopedExists::tenantAndCompany('products', $tenantId, $companyId)];
            $rules['lines.*.description'] = ['required', 'string', 'max:500'];
            $rules['lines.*.quantity'] = ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'];
            $rules['lines.*.unit_price'] = ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'];
            $rules['lines.*.tax_rate'] = ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'];
        }

        $validated = $request->validate($rules);

        try {
            if ($hasSourceInvoice) {
                // Invoice-linked credit note
                if ($isLineBased) {
                    $creditNote = $this->creditNoteService->createLineBasedCreditNote(
                        sourceInvoiceId: $validated['source_invoice_id'],
                        lines: $validated['lines'],
                        reason: CreditNoteReason::from($validated['reason']),
                        notes: $validated['notes'] ?? null
                    );
                } else {
                    $creditNote = $this->creditNoteService->createCreditNote(
                        sourceInvoiceId: $validated['source_invoice_id'],
                        amount: $validated['amount'],
                        reason: CreditNoteReason::from($validated['reason']),
                        notes: $validated['notes'] ?? null
                    );
                }
            } else {
                // Standalone credit note (customer-based)
                $creditNote = $this->creditNoteService->createStandaloneCreditNote(
                    partnerId: $validated['partner_id'],
                    lines: $validated['lines'],
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
        } catch (\RuntimeException $e) {
            // RULING A (2026-08-03 re-gate): the money-allocation search
            // (CreditNoteService::allocateGroupExactly()) no longer throws --
            // it always quantizes to the nearest reachable amount and
            // returns 2xx. This catch is defense-in-depth only, so ANY
            // unexpected internal failure surfaces as a clean 422 instead of
            // an uncaught 500 leaking an internal exception message.
            return response()->json([
                'error' => [
                    'code' => 'ALLOCATION_ERROR',
                    'message' => 'Unable to create the credit note.',
                ],
            ], 422);
        }
    }

    /**
     * Confirm a credit note (Draft -> Confirmed).
     *
     * Confirming a credit note makes it ready for posting.
     * The credit note remains editable until posted.
     *
     * POST /api/v1/credit-notes/{id}/confirm
     */
    public function confirm(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Initial existence check (without lock - for fast 404 response)
        $exists = Document::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('type', DocumentType::CreditNote)
            ->where('id', $id)
            ->exists();

        if (! $exists) {
            return response()->json([
                'error' => [
                    'code' => 'CREDIT_NOTE_NOT_FOUND',
                    'message' => 'Credit note not found',
                ],
            ], 404);
        }

        try {
            $creditNote = DB::transaction(function () use ($tenantId, $companyId, $id): Document {
                // Re-fetch with pessimistic lock inside transaction to prevent race conditions
                $lockedDocument = Document::where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->where('type', DocumentType::CreditNote)
                    ->lockForUpdate()
                    ->find($id);

                if ($lockedDocument === null) {
                    throw new \DomainException('Credit note not found');
                }

                // Check status inside the lock - this is the idempotency check
                if (! $lockedDocument->isDraft()) {
                    // Already confirmed - return silently (idempotent)
                    if ($lockedDocument->status === DocumentStatus::Confirmed) {
                        return $lockedDocument;
                    }
                    throw new \DomainException('Only draft credit notes can be confirmed. Current status: '.$lockedDocument->status->value);
                }

                // For credit notes, simple status change (posting creates GL entries).
                //
                // R-2 / LEDGER D-T9-1 — routed through `DocumentStatusService`,
                // the ONE place a `documents` row is numbered. A credit note
                // authored in the editor (auto-save accepts `credit_note`) is a
                // draft with no `document_number` until this moment; the
                // allocation is folded into the same UPDATE, inside this
                // transaction, and lands before the seal that hashes it.
                $this->documentStatusService->transition($lockedDocument, DocumentStatus::Confirmed, [
                    'confirmed_at' => now(),
                    'confirmed_by' => auth()->id(),
                ]);

                // Calculate and snapshot taxes for immutable audit trail
                $taxResult = $this->taxCalculationService->calculateDocumentTaxes($lockedDocument);

                // Update document with calculated totals. B2 (2026-08-03
                // re-gate): subtotal must be rewritten alongside tax_amount/
                // total -- leaving it stale meant a persisted fiscal document
                // where subtotal + tax_amount != total whenever the
                // recomputed subtotal differed from the one stored at draft
                // time (e.g. the line-based discount bug, B1).
                //
                // Gate C-1 (2026-08-07, Q1 lane) — persist `stamp_duty_amount`
                // / `line_tax_amount` here too, mirroring
                // CreditNoteService::applyConfirmEquivalentTotals(). This
                // endpoint recomputes independently (it does not call that
                // service method), so a PRE-FIX draft confirmed POST-fix must
                // still get the column populated here — closes gate m-3's
                // draft case.
                $lockedDocument->update([
                    'subtotal' => $taxResult->subtotal,
                    'line_tax_amount' => $taxResult->lineItemsTaxTotal,
                    'stamp_duty_amount' => $taxResult->documentTaxTotal,
                    'tax_amount' => $taxResult->totalTax,
                    'total' => $taxResult->total,
                ]);

                // Snapshot for immutable audit trail
                $this->taxCalculationService->snapshotTaxDetails($lockedDocument, $taxResult);

                return $lockedDocument;
            });
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        // Reload with relations
        $creditNote->load(['partner', 'sourceDocument', 'lines']);

        return response()->json([
            'data' => $this->formatCreditNote($creditNote),
            'message' => 'Credit note confirmed successfully',
        ]);
    }

    /**
     * Post a credit note (Confirmed -> Posted).
     *
     * Posting makes the credit note final and immutable. For fiscal documents,
     * this creates an entry in the SHA-256 hash chain for compliance.
     *
     * The credit note becomes:
     * - Fiscally sealed (cannot be modified)
     * - Part of the hash chain (tamper-proof)
     * - Ready for GL entry creation (reverses invoice GL entries)
     *
     * POST /api/v1/credit-notes/{id}/post
     */
    public function post(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $creditNote = Document::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('type', DocumentType::CreditNote)
            ->find($id);

        if ($creditNote === null) {
            return response()->json([
                'error' => [
                    'code' => 'CREDIT_NOTE_NOT_FOUND',
                    'message' => 'Credit note not found',
                ],
            ], 404);
        }

        if (! $creditNote->isConfirmed()) {
            return response()->json([
                'error' => [
                    'code' => 'CREDIT_NOTE_NOT_CONFIRMED',
                    'message' => 'Only confirmed credit notes can be posted. Current status: '.$creditNote->status->value,
                ],
            ], 422);
        }

        try {
            $postedCreditNote = $this->postingService->post($creditNote);

            // Allocate the credit note to reduce invoice balance (only for invoice-linked credit notes)
            // Standalone credit notes (without source_document_id) are NOT allocated
            if ($postedCreditNote->source_document_id !== null) {
                $userId = auth()->id();
                $this->creditNoteService->allocateCreditNote(
                    $postedCreditNote,
                    $userId !== null ? (string) $userId : null
                );
            }

            // Surface per-line quantity_decimals (unit precision) in the response.
            $postedCreditNote->load(['lines.product.unitOfMeasure']);

            return response()->json([
                'data' => DocumentData::fromModel($postedCreditNote),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'fiscal_hash' => $postedCreditNote->fiscal_hash,
                    'chain_sequence' => $postedCreditNote->chain_sequence,
                ],
                'message' => 'Credit note posted successfully',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'POSTING_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CONFIGURATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Format credit note for API response.
     *
     * @return array<string, mixed>
     */
    private function formatCreditNote(Document $creditNote): array
    {
        $data = [
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
            // C-F0w / SPEC §2.4 — the PREDICATE, not its symptoms. `CreditNoteDetail`
            // guessed from `status` and said so in a comment; a `Posted` credit note
            // the chain never sealed is a proforma and a status heuristic calls it
            // definitive. Same answer the PDF gets.
            'is_proforma' => $creditNote->isProformaOutput(),
            'created_at' => $creditNote->created_at?->toIso8601String(),
        ];

        // RULING A (2026-08-03 re-gate): when the amount-based allocation
        // search could not reach the operator's requested amount exactly (a
        // genuine truncation "hole"), CreditNoteService::createCreditNote()
        // stamps these two IN-MEMORY-ONLY attributes on the just-created
        // model so the immediate create response exposes both the request
        // and what was actually credited -- never a silent upward drift.
        // Not persisted: a subsequent GET/show re-fetches a fresh model
        // without them, since the quantization is a create-time event.
        $requestedAmount = $creditNote->getAttribute('requested_amount');
        if ($requestedAmount !== null) {
            $data['requested_amount'] = $requestedAmount;
            $data['credited_amount'] = $creditNote->getAttribute('credited_amount');
        }

        return $data;
    }
}
