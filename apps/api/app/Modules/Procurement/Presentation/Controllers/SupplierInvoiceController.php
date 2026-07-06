<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Procurement\Application\CreateSupplierInvoiceService;
use App\Modules\Procurement\Application\InvoiceFirstOrchestrator;
use App\Modules\Procurement\Application\ProcurementPolicyResolver;
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Application\SupplierInvoiceReceiptLinkingService;
use App\Modules\Procurement\Presentation\Requests\CreateSupplierInvoiceRequest;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * B3 — Supplier Invoice Presentation controller.
 *
 * Wraps the already-built domain services (C2 matcher, C3 posting, policy resolver)
 * with zero business/GL logic. All accounting decisions stay in the services.
 *
 * Endpoints:
 *   GET    /api/v1/supplier-invoices           — index (paginated, filters)
 *   GET    /api/v1/supplier-invoices/{id}      — show (detail + match block)
 *   POST   /api/v1/supplier-invoices           — store (create DRAFT + auto-match)
 *   POST   /api/v1/supplier-invoices/{id}/match — match (re-run matcher)
 *   POST   /api/v1/supplier-invoices/{id}/post  — post (assertPostable + post)
 */
final class SupplierInvoiceController extends Controller
{
    use HandlesDocuments;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CreateSupplierInvoiceService $createService,
        private readonly InvoiceFirstOrchestrator $invoiceFirstOrchestrator,
        private readonly SupplierInvoiceMatcher $matcher,
        private readonly SupplierInvoicePostingService $postingService,
        private readonly SupplierInvoiceReceiptLinkingService $receiptLinkingService,
        private readonly ProcurementPolicyResolver $policyResolver,
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    /**
     * Supplier invoices are searchable by document number OR supplier (partner)
     * name. Grouped so the two clauses OR together and still AND with the other
     * list filters (status, match_status, dates).
     *
     * @param  Builder<Document>  $query
     */
    protected function applySearchFilter(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search): void {
            $q->where('document_number', 'like', "%{$search}%")
                ->orWhereHas('partner', function (Builder $partnerQuery) use ($search): void {
                    $partnerQuery->where('partners.name', 'like', "%{$search}%");
                });
        });
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/supplier-invoices
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);

        $query = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->with('partner');

        // Filters: partner_id, status, match_status, date_from, date_to
        $query = $this->applyFilters($query, $request);

        $matchStatus = $request->query('match_status');
        if (is_string($matchStatus) && $matchStatus !== '') {
            $statusEnum = SupplierInvoiceMatchStatus::tryFrom($matchStatus);
            if ($statusEnum !== null) {
                $query->where('match_status', $statusEnum->value);
            }
        }

        $pendingReceipt = $request->query('pending_receipt');
        if ($pendingReceipt === '1' || $pendingReceipt === 'true') {
            $query->where(function (Builder $pendingQuery): void {
                $driver = $pendingQuery->getModel()->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $pendingQuery->whereRaw("(payload #>> '{supplier_invoice,pending_receipt}') = 'true'");

                    return;
                }
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $pendingQuery->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.supplier_invoice.pending_receipt')) = 'true'");

                    return;
                }

                $pendingQuery->whereRaw("json_extract(payload, '$.supplier_invoice.pending_receipt') = 1");
            });
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $paginator = $query->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        $items = collect($paginator->items())->map(
            fn (Document $doc): array => $this->formatListItem($doc)
        )->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/supplier-invoices/{id}
    // -------------------------------------------------------------------------

    public function show(Request $request, string $id): JsonResponse
    {
        $doc = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->with(['lines', 'partner', 'sourceDocument'])
            ->find($id);

        if ($doc === null) {
            return $this->notFoundResponse('Supplier invoice');
        }

        return response()->json([
            'data' => $this->formatDetail($doc),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/supplier-invoices/duplicate-reference
    // -------------------------------------------------------------------------

    public function duplicateReference(Request $request): JsonResponse
    {
        $partnerId = $request->query('partner_id');
        $reference = $request->query('reference');

        if (! is_string($partnerId) || $partnerId === '' || ! Str::isUuid($partnerId) || ! is_string($reference) || trim($reference) === '') {
            return response()->json([
                'data' => ['exists' => false],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ]);
        }

        $invoice = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->where('partner_id', $partnerId)
            ->where('external_document_number', trim($reference))
            ->orderBy('document_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'data' => $invoice === null
                ? ['exists' => false]
                : [
                    'exists' => true,
                    'invoice_number' => $invoice->document_number,
                ],
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/supplier-invoices
    // -------------------------------------------------------------------------

    public function store(CreateSupplierInvoiceRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        $validated = $request->validated();
        $invoiceFirstDelivered = ($validated['invoice_first_delivered'] ?? false) === true;
        $pendingReceipt = ($validated['pending_receipt'] ?? false) === true;
        $this->assertInvoiceFirstCreatePermission($request, $invoiceFirstDelivered, $pendingReceipt);

        if ($invoiceFirstDelivered) {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(Response::HTTP_UNAUTHORIZED);
            }

            $document = $this->invoiceFirstOrchestrator->createDelivered($validated, $tenantId, $companyId, $user->id);
        } else {
            $document = $this->createService->create($validated, $tenantId, $companyId);
        }

        return response()->json([
            'data' => $this->formatDetail($document),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ], 201);
    }

    private function assertInvoiceFirstCreatePermission(Request $request, bool $invoiceFirstDelivered, bool $pendingReceipt): void
    {
        if (! $invoiceFirstDelivered && ! $pendingReceipt) {
            return;
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->can('supplier-invoices.create-pending')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if ($invoiceFirstDelivered && ! $user->can('goods-receipt.create-standalone')) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/supplier-invoices/{id}/match
    // -------------------------------------------------------------------------

    public function match(Request $request, string $id): JsonResponse
    {
        $doc = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->with('lines')
            ->find($id);

        if ($doc === null) {
            return $this->notFoundResponse('Supplier invoice');
        }

        $matchStatus = $this->matcher->match($doc);
        $doc->match_status = $matchStatus;
        $doc->save();

        return response()->json([
            'data' => $this->buildMatchBlock($doc),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/supplier-invoices/{id}/link-receipts
    // -------------------------------------------------------------------------

    public function linkReceipts(Request $request, string $id): JsonResponse
    {
        /** @var array{links: list<array{invoice_line_id: string, receipt_line_id: string}>} $validated */
        $validated = $request->validate([
            'links' => ['required', 'array', 'min:1'],
            'links.*.invoice_line_id' => ['required', 'uuid'],
            'links.*.receipt_line_id' => ['required', 'uuid'],
        ]);

        $doc = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->with(['lines', 'partner', 'sourceDocument'])
            ->find($id);

        if ($doc === null) {
            return $this->notFoundResponse('Supplier invoice');
        }

        try {
            $doc = $this->receiptLinkingService->link($doc, $validated['links']);
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('LINK_RECEIPTS_FAILED', $e->getMessage());
        }

        return response()->json([
            'data' => $this->formatDetail($doc),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/supplier-invoices/{id}/post
    // -------------------------------------------------------------------------

    public function post(Request $request, string $id): JsonResponse
    {
        $doc = $this->baseQuery()
            ->ofType(DocumentType::SupplierInvoice)
            ->with(['lines', 'partner', 'sourceDocument'])
            ->find($id);

        if ($doc === null) {
            return $this->notFoundResponse('Supplier invoice');
        }

        // Delegate entirely to the posting service which owns the authoritative locked
        // + idempotent boundary. A pre-check here would prevent idempotent retries:
        // on a second POST the invoice is already posted (quantity_invoiced already
        // incremented), so assertPostable() sees over-clear and would return 422
        // instead of the no-op the service already handles under lock.
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(Response::HTTP_UNAUTHORIZED);
            }

            $this->postingService->post($doc, $user->id);
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('POSTING_BLOCKED', $e->getMessage());
        }

        // Reload after posting to get the authoritative post-time match_status.
        $doc->refresh();
        $doc->load(['lines', 'partner', 'sourceDocument']);

        $responseData = $this->formatDetail($doc);

        // Surface price-variance warning based on the authoritative post-time status.
        if ($doc->match_status === SupplierInvoiceMatchStatus::PriceVariance) {
            $responseData['warning'] = 'Price variance detected; posted under warn enforcement.';
        }

        return response()->json([
            'data' => $responseData,
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private formatting helpers
    // -------------------------------------------------------------------------

    /**
     * Format a supplier invoice as a list item (index response).
     *
     * @return array<string, mixed>
     */
    private function formatListItem(Document $doc): array
    {
        $pendingReceipt = $this->hasPendingReceipt($doc);

        return [
            'id' => $doc->id,
            'number' => $doc->document_number,
            'partner' => [
                'id' => $doc->partner_id,
                'name' => $doc->partner->name,
            ],
            'issue_date' => $doc->document_date->toDateString(),
            'currency' => $doc->currency,
            'total' => $doc->total,
            'status' => $doc->status->value,
            'match_status' => $doc->match_status?->value,
            'has_source_document' => $doc->source_document_id !== null,
            'pending_receipt' => $pendingReceipt,
        ];
    }

    /**
     * Format a supplier invoice as the full detail response.
     *
     * @return array<string, mixed>
     */
    private function formatDetail(Document $doc): array
    {
        $pendingReceipt = $this->hasPendingReceipt($doc);

        $sourcePurchaseOrders = $this->sourcePurchaseOrders($doc);
        $sourcePo = $sourcePurchaseOrders[0] ?? null;

        // Lines formatted per contract.
        $lines = $doc->lines->map(fn (DocumentLine $line): array => [
            'id' => $line->id,
            'source_line_id' => $line->source_line_id,
            'product_id' => $line->product_id,
            'variant_id' => $line->variant_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'vat_rate' => $line->tax_rate,
            'recoverable_tax_amount' => $line->recoverable_tax_amount,
            'line_subtotal' => $line->line_total,
        ])->values()->all();

        // posted_at from GL journal entry — company-scoped to prevent cross-tenant leakage.
        $postedAt = null;
        if ($doc->status === DocumentStatus::Posted) {
            /** @var Carbon|null $jeDate */
            $jeDate = JournalEntry::query()
                ->where('source_type', 'supplier_invoice')
                ->where('source_id', $doc->id)
                ->where('company_id', $doc->company_id)
                ->value('created_at');
            $postedAt = $jeDate?->toIso8601String();
        }

        return [
            'id' => $doc->id,
            'type' => DocumentType::SupplierInvoice->value,
            'number' => $doc->document_number,
            'partner' => [
                'id' => $doc->partner_id,
                'name' => $doc->partner->name,
            ],
            'source_document_id' => $doc->source_document_id,
            'pending_receipt' => $pendingReceipt,
            'issue_date' => $doc->document_date->toDateString(),
            'due_date' => $doc->due_date?->toDateString(),
            'currency' => $doc->currency,
            'subtotal' => $doc->subtotal,
            'tax_amount' => $doc->tax_amount,
            'total' => $doc->total,
            'status' => $doc->status->value,
            'match_status' => $doc->match_status?->value,
            'supplier_reference' => $doc->external_document_number,
            'lines' => $lines,
            'source_purchase_order' => $sourcePo,
            'source_purchase_orders' => $sourcePurchaseOrders,
            'consumed_receipts' => $this->consumedReceipts($doc),
            'match' => $this->buildMatchBlock($doc),
            'posted_at' => $postedAt,
            'attachments' => [],
        ];
    }

    /**
     * @return list<array{id: string, number: string|null}>
     */
    private function sourcePurchaseOrders(Document $doc): array
    {
        $payload = is_array($doc->payload) ? $doc->payload : [];
        $supplierInvoicePayload = is_array($payload['supplier_invoice'] ?? null) ? $payload['supplier_invoice'] : [];
        $sourceIds = [];

        if ($doc->source_document_id !== null) {
            $sourceIds[] = $doc->source_document_id;
        }

        if (is_array($supplierInvoicePayload['source_document_ids'] ?? null)) {
            foreach ($supplierInvoicePayload['source_document_ids'] as $sourceId) {
                if (is_string($sourceId) && $sourceId !== '') {
                    $sourceIds[] = $sourceId;
                }
            }
        }

        $sourceIds = array_values(array_unique($sourceIds));
        if ($sourceIds === []) {
            return [];
        }

        $purchaseOrders = Document::query()
            ->where('tenant_id', $doc->tenant_id)
            ->where('company_id', $doc->company_id)
            ->where('type', DocumentType::PurchaseOrder)
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        $links = [];
        foreach ($sourceIds as $sourceId) {
            /** @var Document|null $purchaseOrder */
            $purchaseOrder = $purchaseOrders->get($sourceId);
            if ($purchaseOrder === null) {
                continue;
            }

            $links[] = [
                'id' => $purchaseOrder->id,
                'number' => $purchaseOrder->document_number,
            ];
        }

        return $links;
    }

    /**
     * @return list<array{id: string, receipt_number: string|null, status: string, received_at: string|null, external_reference: string|null}>
     */
    private function consumedReceipts(Document $doc): array
    {
        $receiptLineIds = $doc->lines
            ->pluck('source_line_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($receiptLineIds === []) {
            return [];
        }

        $receiptIds = GoodsReceiptLine::query()
            ->where('tenant_id', $doc->tenant_id)
            ->where('company_id', $doc->company_id)
            ->whereIn('id', $receiptLineIds)
            ->pluck('goods_receipt_id')
            ->unique()
            ->values()
            ->all();

        if ($receiptIds === []) {
            return [];
        }

        return array_values(GoodsReceipt::query()
            ->where('tenant_id', $doc->tenant_id)
            ->where('company_id', $doc->company_id)
            ->whereIn('id', $receiptIds)
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (GoodsReceipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'status' => $receipt->status->value,
                'received_at' => $receipt->received_at->toIso8601String(),
                'external_reference' => $receipt->external_reference,
            ])
            ->values()
            ->all());
    }

    private function hasPendingReceipt(Document $doc): bool
    {
        $payload = is_array($doc->payload) ? $doc->payload : [];
        $supplierInvoicePayload = is_array($payload['supplier_invoice'] ?? null) ? $payload['supplier_invoice'] : [];

        return ($supplierInvoicePayload['pending_receipt'] ?? false) === true;
    }

    /**
     * Build the match block for a supplier invoice.
     *
     * @return array{status: string|null, per_line: list<array<string, mixed>>}
     */
    private function buildMatchBlock(Document $doc): array
    {
        $perLine = [];

        // Index PO lines by id; scope to the same company to prevent leakage.
        $poLineIds = $doc->lines
            ->pluck('source_line_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var array<string, DocumentLine> $poLines */
        $poLines = DocumentLine::query()
            ->whereIn('id', $poLineIds)
            ->whereHas('document', fn ($q) => $q->whereRaw('company_id = ?', [$doc->company_id]))
            ->get()
            ->keyBy('id')
            ->all();

        $policy = $this->policyResolver->forCompany($doc->company_id);

        foreach ($doc->lines as $invoiceLine) {
            if ($invoiceLine->source_line_id === null) {
                continue;
            }

            $poLine = $poLines[$invoiceLine->source_line_id] ?? null;
            if ($poLine === null) {
                continue;
            }

            $matchable = $this->matcher->matchableQty($poLine);

            // Tolerance-aware price variance (consistent with matcher policy).
            $priceStatus = $this->matcher->priceStatus($invoiceLine, $poLine, $policy);
            $priceVariance = $priceStatus === SupplierInvoiceMatchStatus::PriceVariance;

            $perLine[] = [
                'po_line_id' => $invoiceLine->source_line_id,
                'ordered' => $poLine->quantity,
                'received' => $poLine->quantity_received,
                'invoiced' => $poLine->quantity_invoiced,
                'matchable' => $matchable,
                'price_variance' => $priceVariance,
            ];
        }

        return [
            'status' => $doc->match_status?->value,
            'per_line' => $perLine,
        ];
    }
}
