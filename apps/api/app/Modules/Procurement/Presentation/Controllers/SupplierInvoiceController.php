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
use App\Modules\Procurement\Application\CreateSupplierInvoiceService;
use App\Modules\Procurement\Application\ProcurementPolicyResolver;
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Presentation\Requests\CreateSupplierInvoiceRequest;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        private readonly SupplierInvoiceMatcher $matcher,
        private readonly SupplierInvoicePostingService $postingService,
        private readonly ProcurementPolicyResolver $policyResolver,
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
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
    // POST /api/v1/supplier-invoices
    // -------------------------------------------------------------------------

    public function store(CreateSupplierInvoiceRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        $document = $this->createService->create($request->validated(), $tenantId, $companyId);

        return response()->json([
            'data' => $this->formatDetail($document),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ], 201);
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

        $policy = $this->policyResolver->forCompany($doc->company_id);
        $enforcement = $policy->match_enforcement;

        // Let matcher pre-check (fast path; C3 will recheck under lock).
        try {
            $this->matcher->assertPostable($doc, $enforcement);
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('POSTING_BLOCKED', $e->getMessage());
        }

        try {
            $this->postingService->post($doc);
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
        return [
            'id' => $doc->id,
            'number' => $doc->document_number,
            'partner' => [
                'id' => $doc->partner_id,
                'name' => $doc->partner?->name,
            ],
            'issue_date' => $doc->document_date?->toDateString(),
            'currency' => $doc->currency,
            'total' => $doc->total,
            'status' => $doc->status->value,
            'match_status' => $doc->match_status?->value,
            'has_source_document' => $doc->source_document_id !== null,
        ];
    }

    /**
     * Format a supplier invoice as the full detail response.
     *
     * @return array<string, mixed>
     */
    private function formatDetail(Document $doc): array
    {
        // Source PO reference.
        $sourcePo = null;
        if ($doc->sourceDocument !== null) {
            $sourcePo = [
                'id' => $doc->sourceDocument->id,
                'number' => $doc->sourceDocument->document_number,
            ];
        }

        // Lines formatted per contract.
        $lines = $doc->lines->map(fn (DocumentLine $line): array => [
            'id' => $line->id,
            'source_line_id' => $line->source_line_id,
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
                'name' => $doc->partner?->name,
            ],
            'source_document_id' => $doc->source_document_id,
            'issue_date' => $doc->document_date?->toDateString(),
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
            'match' => $this->buildMatchBlock($doc),
            'posted_at' => $postedAt,
            'attachments' => [],
        ];
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
