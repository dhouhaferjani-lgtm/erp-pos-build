<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Committers;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\DocumentIngestion\Application\Contracts\IngestionCommitterInterface;
use App\Modules\DocumentIngestion\Application\DTO\CommitResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedLineData;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedPayloadData;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateSupplierInvoiceService;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SupplierInvoiceCommitter implements IngestionCommitterInterface
{
    public function __construct(
        private CreateSupplierInvoiceService $createSupplierInvoiceService,
    ) {}

    public function supports(DocumentKind $kind): bool
    {
        return $kind === DocumentKind::SupplierInvoice;
    }

    public function commit(DocumentIngestion $ingestion, ReviewedPayloadData $payload, string $actorId): CommitResultData
    {
        $actor = User::query()->findOrFail($actorId);
        $pendingReceipt = $payload->pendingReceipt === true;
        if ($pendingReceipt) {
            Gate::forUser($actor)->authorize('supplier-invoices.create-pending');
        }

        Partner::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('type', PartnerType::Supplier)
            ->findOrFail($payload->supplierId);

        $this->assertInvoiceRequiredFields($payload);

        return DB::transaction(function () use ($ingestion, $payload, $pendingReceipt): CommitResultData {
            $sourceDocuments = $pendingReceipt ? [] : $this->sourceDocuments($ingestion, $payload);
            $sourceDocumentIds = array_keys($sourceDocuments);

            $this->guardDuplicateReference($ingestion, $payload);

            $document = $this->createSupplierInvoiceService->create([
                'partner_id' => $payload->supplierId,
                'source_document_ids' => $sourceDocumentIds,
                'currency' => $payload->currency,
                'issue_date' => $payload->documentDate,
                'supplier_reference' => $payload->reference,
                'pending_receipt' => $pendingReceipt,
                'lines' => $this->lines($ingestion, $payload, $sourceDocuments, $pendingReceipt),
            ], $ingestion->tenant_id, $ingestion->company_id);

            $ingestion->forceFill([
                'committed_type' => 'supplier_invoice',
                'committed_id' => $document->id,
            ])->save();

            return new CommitResultData(
                committedType: 'supplier_invoice',
                committedId: $document->id,
            );
        });
    }

    private function assertInvoiceRequiredFields(ReviewedPayloadData $payload): void
    {
        if ($payload->currency === null) {
            $this->throwValidation('VALIDATION_ERROR', 'currency', 'Currency is required for supplier invoice commits.');
        }

        if ($payload->documentDate === null) {
            $this->throwValidation('VALIDATION_ERROR', 'documentDate', 'Document date is required for supplier invoice commits.');
        }

        foreach ($payload->lines as $index => $line) {
            if ($line->unitPrice === null) {
                $this->throwValidation('VALIDATION_ERROR', "lines.{$index}.unitPrice", 'Unit price is required for supplier invoice commits.');
            }

            if ($line->vatRate === null) {
                $this->throwValidation('VALIDATION_ERROR', "lines.{$index}.vatRate", 'VAT rate is required for supplier invoice commits.');
            }
        }
    }

    /**
     * @return array<string, Document>
     */
    private function sourceDocuments(DocumentIngestion $ingestion, ReviewedPayloadData $payload): array
    {
        $documents = [];

        foreach ($payload->lines as $index => $line) {
            if ($line->sourceLineId === null) {
                $this->throwValidation('VALIDATION_ERROR', "lines.{$index}.sourceLineId", 'Source line is required for receipt-mapped supplier invoice commits.');
            }

            $poLine = $this->sourceLine($ingestion, $line->sourceLineId);
            $po = $poLine->document;

            if ($po->type !== DocumentType::PurchaseOrder) {
                throw new \DomainException('The source document must be a purchase order.');
            }

            if ($po->status === DocumentStatus::Cancelled) {
                throw new \DomainException('Cancelled purchase orders cannot be invoiced.');
            }

            if ($po->partner_id !== $payload->supplierId) {
                throw new \DomainException('The supplier invoice partner must match every source purchase order.');
            }

            if ($payload->currency !== $po->currency) {
                throw new \DomainException('The invoice currency must match every source purchase order.');
            }

            $documents[$po->id] = $po;
        }

        return $documents;
    }

    private function sourceLine(DocumentIngestion $ingestion, string $sourceLineId): DocumentLine
    {
        /** @var DocumentLine $line */
        $line = DocumentLine::query()
            ->whereIn('document_id', Document::query()
                ->select('id')
                ->where('tenant_id', $ingestion->tenant_id)
                ->where('company_id', $ingestion->company_id))
            ->with('document')
            ->findOrFail($sourceLineId);

        return $line;
    }

    /**
     * @param  array<string, Document>  $sourceDocuments
     * @return list<array<string, string|bool|null>>
     */
    private function lines(DocumentIngestion $ingestion, ReviewedPayloadData $payload, array $sourceDocuments, bool $pendingReceipt): array
    {
        $lines = [];
        foreach ($payload->lines as $index => $line) {
            $lines[] = $this->line($ingestion, $line, $index, $sourceDocuments, $pendingReceipt);
        }

        return $lines;
    }

    /**
     * @param  array<string, Document>  $sourceDocuments
     * @return array<string, string|bool|null>
     */
    private function line(
        DocumentIngestion $ingestion,
        ReviewedLineData $line,
        int $index,
        array $sourceDocuments,
        bool $pendingReceipt,
    ): array {
        $product = Product::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->findOrFail($line->productId);

        $variantId = null;
        if ($line->variantId !== null) {
            $variantId = (string) ProductVariant::query()
                ->where('tenant_id', $ingestion->tenant_id)
                ->where('company_id', $ingestion->company_id)
                ->where('product_id', $product->id)
                ->findOrFail($line->variantId)
                ->id;
        }

        $sourceLineId = $line->sourceLineId;
        if (! $pendingReceipt) {
            if ($sourceLineId === null) {
                $this->throwValidation('VALIDATION_ERROR', "lines.{$index}.sourceLineId", 'Source line is required for receipt-mapped supplier invoice commits.');
            }

            $sourceLine = $this->sourceLine($ingestion, $sourceLineId);
            if (! array_key_exists($sourceLine->document_id, $sourceDocuments)) {
                throw new \DomainException('The source line must belong to one of the derived purchase orders.');
            }
        }

        return [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice,
            'vat_rate' => $line->vatRate,
            'source_line_id' => $sourceLineId,
        ];
    }

    private function guardDuplicateReference(DocumentIngestion $ingestion, ReviewedPayloadData $payload): void
    {
        if ($payload->reference === null || trim($payload->reference) === '') {
            return;
        }

        $lockKey = sprintf('si-ref:%s:%s:%s', $ingestion->company_id, $payload->supplierId, trim($payload->reference));
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
        }

        $exists = Document::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('type', DocumentType::SupplierInvoice)
            ->where('partner_id', $payload->supplierId)
            ->where('external_document_number', trim($payload->reference))
            ->exists();

        if ($exists) {
            $this->throwValidation(
                'DUPLICATE_SUPPLIER_REFERENCE',
                'reference',
                'A supplier invoice with this supplier reference already exists.',
            );
        }
    }

    private function throwValidation(string $code, string $field, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ],
        ], 422));
    }
}
