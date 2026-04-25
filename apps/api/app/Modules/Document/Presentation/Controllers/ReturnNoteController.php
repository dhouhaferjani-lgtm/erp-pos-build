<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\ReturnCondition;
use App\Modules\Document\Domain\Enums\ReturnReason;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Controller for return note operations.
 *
 * Return notes document customer returns and include fiscal hash chain compliance.
 * When confirmed, they:
 * - Receive stock back from customer
 * - Become fiscally sealed (tamper-proof)
 * - Are added to the return note hash chain
 * - Trigger WAC recalculation
 */
class ReturnNoteController extends Controller
{
    use HandlesDocuments;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly ReturnNoteService $returnNoteService,
        private readonly DocumentNumberingService $numberingService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Get the CompanyContext service (required by HandlesDocuments trait).
     */
    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    /**
     * List all return notes.
     *
     * GET /api/v1/return-notes
     *
     * Query parameters:
     * - status: Filter by status (draft, confirmed)
     * - partner_id: Filter by partner UUID
     * - source_invoice_id: Filter by source invoice UUID
     * - search: Search by document number
     * - date_from: Filter from date (YYYY-MM-DD)
     * - date_to: Filter to date (YYYY-MM-DD)
     * - per_page: Pagination limit (default 50, max 200)
     * - cursor: Pagination cursor
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery()
            ->where('type', DocumentType::ReturnNote)
            ->with(['partner', 'sourceDocument']);

        // Apply common filters from HandlesDocuments trait
        $query = $this->applyFilters($query, $request);

        // Additional filter: source invoice
        if ($request->has('source_invoice_id')) {
            $query->where('source_document_id', $request->input('source_invoice_id'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        $returnNotes = $query
            ->orderByDesc('created_at')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => $returnNotes->items(),
            'meta' => [
                'per_page' => $returnNotes->perPage(),
                'has_more' => $returnNotes->hasMorePages(),
            ],
            'links' => [
                'next' => $returnNotes->nextCursor()?->encode(),
                'prev' => $returnNotes->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * Get a single return note.
     *
     * GET /api/v1/return-notes/{id}
     */
    public function show(string $id): JsonResponse
    {
        $returnNote = $this->baseQuery()
            ->where('type', DocumentType::ReturnNote)
            ->with($this->detailRelations())
            ->findOrFail($id);

        return $this->documentResponse($returnNote, 200, $this->scale());
    }

    /**
     * Create a new return note.
     *
     * POST /api/v1/return-notes
     *
     * Request body (from CreateDocumentRequest):
     * {
     *   "partner_id": "uuid",
     *   "source_document_id": "uuid", // Optional: link to invoice/delivery note
     *   "document_date": "2025-12-27",
     *   "currency": "TND",
     *   "return_reason": "defective|damaged|wrong_item|customer_request|other",
     *   "return_condition": "unopened|opened|damaged|defective",
     *   "notes": "Optional description",
     *   "lines": [
     *     {
     *       "product_id": "uuid",
     *       "description": "Product name",
     *       "quantity": "2.00",
     *       "unit_price": "100.00",
     *       "tax_rate": "19.00",
     *       "location_id": "uuid" // Required for stock receipt
     *     }
     *   ]
     * }
     */
    public function store(CreateDocumentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Validate return note specific fields
        $request->validate([
            'return_reason' => ['nullable', new Enum(ReturnReason::class)],
            'return_condition' => ['nullable', new Enum(ReturnCondition::class)],
        ]);

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $company = $this->companyContext->requireCompany();
            $companyId = $company->id;
            $tenantId = $company->tenant_id;

            // Generate document number
            $documentNumber = $this->numberingService->generateNumber(
                $tenantId,
                $companyId,
                DocumentType::ReturnNote
            );

            // Resolve location using LocationContext fallback chain
            $locationId = $this->locationContext->resolveLocationId(
                $data['location_id'] ?? null,
                $company->id
            );

            // Create return note in Draft status
            $returnNote = Document::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'type' => DocumentType::ReturnNote,
                'fiscal_category' => FiscalCategory::ReturnNote,
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $documentNumber,
                'document_date' => $data['document_date'],
                'partner_id' => $data['partner_id'],
                'source_document_id' => $data['source_document_id'] ?? null,
                'location_id' => $locationId,
                'currency' => $data['currency'] ?? $company->default_currency ?? 'EUR',
                'notes' => $data['notes'] ?? null,
                'subtotal' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
            ]);

            // Store return note metadata (reason, condition)
            $metadata = [];
            if ($request->has('return_reason')) {
                $metadata['return_reason'] = $request->input('return_reason');
            }
            if ($request->has('return_condition')) {
                $metadata['return_condition'] = $request->input('return_condition');
            }
            if (! empty($metadata)) {
                $returnNote->update(['payload' => $metadata]);
            }

            // Create document lines
            if (isset($data['lines']) && is_array($data['lines'])) {
                $subtotal = '0.00';
                $taxAmount = '0.00';

                // Batch-fetch products for snapshot capture (1 query)
                /** @var array<int, array{product_id?: string, description: string, quantity: string, unit_price: string, tax_rate?: string, location_id?: string, notes?: string}> $storeLines */
                $storeLines = $data['lines'];
                $storeProductIds = collect($storeLines)->pluck('product_id')->filter()->unique()->values()->toArray();
                /** @var Collection<int, Product> $storeProducts */
                $storeProducts = Product::whereIn('id', $storeProductIds)->get()->keyBy('id');

                foreach ($data['lines'] as $index => $lineData) {
                    $lineTotal = bcmul(
                        $lineData['quantity'],
                        $lineData['unit_price'],
                        $this->scale()
                    );

                    $lineTax = bcmul(
                        $lineTotal,
                        bcdiv($lineData['tax_rate'] ?? '0.00', '100', 4),
                        $this->scale()
                    );

                    /** @var Product|null $storeLineProduct */
                    $storeLineProduct = isset($lineData['product_id']) ? $storeProducts->get($lineData['product_id']) : null;
                    $storeDefaultName = $storeLineProduct !== null ? (string) $storeLineProduct->name : '';

                    DocumentLine::create([
                        'document_id' => $returnNote->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'location_id' => $lineData['location_id'] ?? null, // Optional per-line location
                        'line_number' => $index + 1,
                        'description' => $lineData['description'],
                        'designation_default_snapshot' => $storeDefaultName !== '' ? mb_substr($storeDefaultName, 0, 500) : null,
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'tax_rate' => $lineData['tax_rate'] ?? '0.00',
                        'line_total' => $lineTotal,
                        'notes' => $lineData['notes'] ?? null,
                    ]);

                    $subtotal = bcadd($subtotal, $lineTotal, $this->scale());
                    $taxAmount = bcadd($taxAmount, $lineTax, $this->scale());
                }

                $total = bcadd($subtotal, $taxAmount, $this->scale());

                // Update document totals
                $returnNote->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ]);
            }

            // Load relations and return
            $returnNote->load($this->defaultRelations());

            return $this->documentCreatedResponse($returnNote, $this->scale());
        });
    }

    /**
     * Update a draft return note.
     *
     * PATCH /api/v1/return-notes/{id}
     *
     * Can only update draft return notes.
     * Confirmed return notes are fiscally sealed and cannot be modified.
     */
    public function update(string $id, UpdateDocumentRequest $request): JsonResponse
    {
        $returnNote = $this->baseQuery()
            ->where('type', DocumentType::ReturnNote)
            ->findOrFail($id);

        if (! $returnNote->isDraft()) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_UPDATE_CONFIRMED_DOCUMENT',
                    'message' => 'Cannot update a confirmed return note. Current status: '.$returnNote->status->value,
                ],
            ], 422);
        }

        $data = $request->validated();

        // Validate return note specific fields if provided
        if ($request->has('return_reason') || $request->has('return_condition')) {
            $request->validate([
                'return_reason' => ['nullable', new Enum(ReturnReason::class)],
                'return_condition' => ['nullable', new Enum(ReturnCondition::class)],
            ]);
        }

        return DB::transaction(function () use ($returnNote, $data, $request): JsonResponse {
            // Update main fields
            $updateData = [];
            if (isset($data['partner_id'])) {
                $updateData['partner_id'] = $data['partner_id'];
            }
            if (isset($data['document_date'])) {
                $updateData['document_date'] = $data['document_date'];
            }
            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }
            if (isset($data['source_document_id'])) {
                $updateData['source_document_id'] = $data['source_document_id'];
            }

            // Update metadata (reason, condition)
            if ($request->has('return_reason') || $request->has('return_condition')) {
                $payload = $returnNote->payload ?? [];
                if ($request->has('return_reason')) {
                    $payload['return_reason'] = $request->input('return_reason');
                }
                if ($request->has('return_condition')) {
                    $payload['return_condition'] = $request->input('return_condition');
                }
                $updateData['payload'] = $payload;
            }

            if (! empty($updateData)) {
                $returnNote->update($updateData);
            }

            // Update lines if provided
            if (isset($data['lines']) && is_array($data['lines'])) {
                // Delete existing lines
                $returnNote->lines()->delete();

                // Batch-fetch products for snapshot capture (1 query)
                /** @var array<int, array{product_id?: string, description: string, quantity: string, unit_price: string, tax_rate?: string, location_id?: string, notes?: string}> $updateLines */
                $updateLines = $data['lines'];
                $updateProductIds = collect($updateLines)->pluck('product_id')->filter()->unique()->values()->toArray();
                /** @var Collection<int, Product> $updateProducts */
                $updateProducts = Product::whereIn('id', $updateProductIds)->get()->keyBy('id');

                // Create new lines
                $subtotal = '0.00';
                $taxAmount = '0.00';

                foreach ($data['lines'] as $index => $lineData) {
                    $lineTotal = bcmul(
                        $lineData['quantity'],
                        $lineData['unit_price'],
                        $this->scale()
                    );

                    $lineTax = bcmul(
                        $lineTotal,
                        bcdiv($lineData['tax_rate'] ?? '0.00', '100', 4),
                        $this->scale()
                    );

                    /** @var Product|null $updateLineProduct */
                    $updateLineProduct = isset($lineData['product_id']) ? $updateProducts->get($lineData['product_id']) : null;
                    $updateDefaultName = $updateLineProduct !== null ? (string) $updateLineProduct->name : '';

                    DocumentLine::create([
                        'document_id' => $returnNote->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'location_id' => $lineData['location_id'] ?? null, // Optional per-line location
                        'line_number' => $index + 1,
                        'description' => $lineData['description'],
                        'designation_default_snapshot' => $updateDefaultName !== '' ? mb_substr($updateDefaultName, 0, 500) : null,
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'tax_rate' => $lineData['tax_rate'] ?? '0.00',
                        'line_total' => $lineTotal,
                        'notes' => $lineData['notes'] ?? null,
                    ]);

                    $subtotal = bcadd($subtotal, $lineTotal, $this->scale());
                    $taxAmount = bcadd($taxAmount, $lineTax, $this->scale());
                }

                $total = bcadd($subtotal, $taxAmount, $this->scale());

                // Update document totals
                $returnNote->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ]);
            }

            // Reload and return
            $returnNote->refresh()->load($this->defaultRelations());

            return $this->documentResponse($returnNote, 200, $this->scale());
        });
    }

    /**
     * Delete a draft return note.
     *
     * DELETE /api/v1/return-notes/{id}
     *
     * Can only delete draft return notes.
     * Confirmed return notes are in the fiscal chain and cannot be deleted.
     */
    public function destroy(string $id): JsonResponse
    {
        $returnNote = $this->baseQuery()
            ->where('type', DocumentType::ReturnNote)
            ->findOrFail($id);

        if (! $returnNote->isDraft()) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_DELETE_CONFIRMED_DOCUMENT',
                    'message' => 'Cannot delete a confirmed return note. Current status: '.$returnNote->status->value,
                ],
            ], 422);
        }

        DB::transaction(function () use ($returnNote): void {
            // Delete lines first
            $returnNote->lines()->delete();

            // Delete return note
            $returnNote->delete();
        });

        return response()->json([
            'message' => 'Return note deleted successfully',
        ]);
    }

    /**
     * Confirm a return note.
     *
     * POST /api/v1/return-notes/{id}/confirm
     *
     * Confirming a return note:
     * - Receives stock back from customer
     * - Adds the return note to the fiscal hash chain
     * - Seals the document (tamper-proof)
     * - Updates WAC based on returned goods
     * - Makes the document immutable
     */
    public function confirm(string $id): JsonResponse
    {
        $returnNote = $this->baseQuery()
            ->where('type', DocumentType::ReturnNote)
            ->with($this->defaultRelations())
            ->findOrFail($id);

        // Idempotency: if already confirmed, return success with current state
        if ($returnNote->status === DocumentStatus::Confirmed) {
            return $this->documentResponse($returnNote, 200, $this->scale());
        }

        try {
            $confirmedReturnNote = $this->returnNoteService->confirm($returnNote);

            return $this->documentResponse($confirmedReturnNote, 200, $this->scale());
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'RETURN_NOTE_CONFIRMATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
