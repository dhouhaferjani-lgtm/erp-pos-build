<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Procurement\Application\CreateRfqData;
use App\Modules\Procurement\Application\PurchaseQuoteRequestAwardService;
use App\Modules\Procurement\Application\PurchaseQuoteRequestService;
use App\Modules\Procurement\Application\UpdateRfqData;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use App\Modules\Procurement\Presentation\Requests\CreatePurchaseQuoteRequestRequest;
use App\Modules\Procurement\Presentation\Requests\UpdatePurchaseQuoteRequestRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseQuoteRequestController extends Controller
{
    use HandlesDocuments;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PurchaseQuoteRequestService $service,
        private readonly PurchaseQuoteRequestAwardService $awardService,
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    public function index(Request $request): JsonResponse
    {
        $documents = $this->rfqBaseQuery()
            ->with('partner')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Document $document): array => $this->formatListItem($document))
            ->values()
            ->all();

        return response()->json(['data' => $documents]);
    }

    public function show(string $id): JsonResponse
    {
        $document = $this->rfqBaseQuery()->with(['partner', 'lines'])->find($id);

        if ($document === null) {
            return $this->notFoundResponse('Purchase quote request');
        }

        return response()->json(['data' => $this->formatDetail($document)]);
    }

    public function group(string $groupId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $siblings = $this->rfqBaseQuery()
            ->with(['partner', 'lines'])
            ->where('tenant_id', $company->tenant_id)
            ->whereRaw("payload->'rfq'->>'group_id' = ?", [$groupId])
            ->orderBy('document_number')
            ->get();

        if ($siblings->isEmpty()) {
            return $this->notFoundResponse('Purchase quote request group');
        }

        return response()->json([
            'data' => [
                'group_id' => $groupId,
                'siblings' => $siblings->map(fn (Document $document): array => $this->formatComparisonSibling($document))->values()->all(),
            ],
        ]);
    }

    public function store(CreatePurchaseQuoteRequestRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();

        $documents = $this->service->createGroup(
            new CreateRfqData(
                partnerIds: $this->stringList($validated['partner_ids'] ?? []),
                lines: $this->lineList($validated['lines'] ?? []),
                validityDate: is_string($validated['validity_date'] ?? null) ? $validated['validity_date'] : null,
                notes: is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            ),
            $company->tenant_id,
            $company->id,
            $company->currency,
        );

        $first = $documents->first();
        if (! $first instanceof Document) {
            throw new \RuntimeException('RFQ group creation returned no documents.');
        }

        $payload = RfqPayload::fromArray($first->payload ?? []);

        return response()->json([
            'data' => [
                'group_id' => $payload->groupId,
                'siblings' => $documents->map(fn (Document $document): array => $this->formatDetail($document))->values()->all(),
            ],
        ], 201);
    }

    public function update(UpdatePurchaseQuoteRequestRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();

        try {
            $document = $this->service->recordResponse(
                $id,
                $company->tenant_id,
                $company->id,
                new UpdateRfqData(
                    lines: $this->lineList($validated['lines'] ?? []),
                    validityDate: is_string($validated['validity_date'] ?? null) ? $validated['validity_date'] : null,
                    supplierReference: is_string($validated['supplier_reference'] ?? null) ? $validated['supplier_reference'] : null,
                    leadTimeDays: is_int($validated['lead_time_days'] ?? null) ? $validated['lead_time_days'] : null,
                ),
            );
        } catch (\DomainException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->getMessage());
        }

        return response()->json(['data' => $this->formatDetail($document)]);
    }

    public function send(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        try {
            $document = $this->service->markSent($id, $company->tenant_id, $company->id);
        } catch (\DomainException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->getMessage());
        }

        return response()->json(['data' => $this->formatDetail($document)]);
    }

    public function convertToPo(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        try {
            $po = $this->awardService->award($id, $company->tenant_id, $company->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->getMessage());
        }

        return response()->json(['data' => ['id' => $po->id, 'type' => $po->type->value, 'status' => $po->status->value]]);
    }

    public function reopen(string $groupId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        try {
            $count = $this->service->reopenGroup($groupId, $company->tenant_id, $company->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->getMessage());
        }

        return response()->json(['data' => ['reopened' => $count]]);
    }

    /**
     * @return Builder<Document>
     */
    private function rfqBaseQuery(): Builder
    {
        return $this->baseQuery()->ofType(DocumentType::PurchaseQuoteRequest);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(Document $document): array
    {
        return [
            'id' => $document->id,
            'number' => $document->document_number,
            'partner' => [
                'id' => $document->partner_id,
                'name' => $document->partner->name,
            ],
            'status' => $document->status->value,
            'total' => $document->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(Document $document): array
    {
        $payload = RfqPayload::fromArray($document->payload ?? []);

        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'number' => $document->document_number,
            'group_id' => $payload->groupId,
            'partner' => [
                'id' => $document->partner_id,
                'name' => $document->partner->name,
            ],
            'status' => $document->status->value,
            'validity_date' => $payload->validityDate,
            'lead_time_days' => $payload->leadTimeDays,
            'responded_at' => $payload->responseRecordedAt,
            'total' => $document->total,
            'lines' => $document->lines->map(fn (DocumentLine $line): array => $this->formatLine($line))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatComparisonSibling(Document $document): array
    {
        return $this->formatDetail($document);
    }

    /**
     * @return array{product_id: string|null, variant_id: string|null, quantity: string, unit_price: string}
     */
    private function formatLine(DocumentLine $line): array
    {
        return [
            'product_id' => $line->product_id,
            'variant_id' => $line->variant_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<array{product_id: string, variant_id?: string|null, quantity: string, unit_price?: string|null, description?: string|null}>
     */
    private function lineList(array $values): array
    {
        $lines = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $productId = $value['product_id'] ?? null;
            $quantity = $value['quantity'] ?? null;
            if (! is_string($productId) || ! is_string($quantity)) {
                continue;
            }

            $variantId = $value['variant_id'] ?? null;
            $unitPrice = $value['unit_price'] ?? null;
            $description = $value['description'] ?? null;

            $lines[] = [
                'product_id' => $productId,
                'variant_id' => is_string($variantId) ? $variantId : null,
                'quantity' => $quantity,
                'unit_price' => is_string($unitPrice) ? $unitPrice : null,
                'description' => is_string($description) ? $description : null,
            ];
        }

        return $lines;
    }
}
