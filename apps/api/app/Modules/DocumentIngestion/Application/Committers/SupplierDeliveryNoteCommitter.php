<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Committers;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Location;
use App\Modules\DocumentIngestion\Application\Contracts\IngestionCommitterInterface;
use App\Modules\DocumentIngestion\Application\DTO\CommitResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedLineData;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedPayloadData;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

final readonly class SupplierDeliveryNoteCommitter implements IngestionCommitterInterface
{
    public function __construct(
        private StandaloneReceiptService $standaloneReceiptService,
    ) {}

    public function supports(DocumentKind $kind): bool
    {
        return $kind === DocumentKind::SupplierDeliveryNote;
    }

    public function commit(DocumentIngestion $ingestion, ReviewedPayloadData $payload, string $actorId): CommitResultData
    {
        if ($payload->locationId === null) {
            $this->throwValidation('VALIDATION_ERROR', 'locationId', 'A delivery note commit requires a receiving location.');
        }

        $actor = User::query()->findOrFail($actorId);
        Gate::forUser($actor)->authorize('goods-receipt.create-standalone');

        Partner::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('type', PartnerType::Supplier)
            ->findOrFail($payload->supplierId);

        Location::query()
            ->where('company_id', $ingestion->company_id)
            ->findOrFail($payload->locationId);

        $result = $this->standaloneReceiptService->execute(new StandaloneReceiptInput(
            companyId: $ingestion->company_id,
            supplierId: $payload->supplierId,
            locationId: $payload->locationId,
            actorId: $actorId,
            idempotencyKey: 'ing-'.$ingestion->id,
            source: 'standalone_receipt',
            externalReference: $payload->reference,
            externalDate: $payload->documentDate,
            postImmediately: true,
            lines: $this->lines($ingestion, $payload),
        ));

        return new CommitResultData(
            committedType: 'goods_receipt',
            committedId: $result->receipt->id,
            goodsReceiptNumber: $result->receipt->receipt_number,
        );
    }

    /**
     * @return list<StandaloneReceiptLineInput>
     */
    private function lines(DocumentIngestion $ingestion, ReviewedPayloadData $payload): array
    {
        $lines = [];
        foreach ($payload->lines as $index => $line) {
            $lines[] = $this->line($ingestion, $line, $index);
        }

        return $lines;
    }

    private function line(DocumentIngestion $ingestion, ReviewedLineData $line, int $index): StandaloneReceiptLineInput
    {
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

        if ($product->requires_batch_tracking && $line->batch === null) {
            $this->throwValidation(
                'VALIDATION_ERROR',
                "lines.{$index}.batch",
                'Batch number and expiry date are required for this product. Enter them from the physical goods.',
            );
        }

        $unitPrice = $this->unitPrice($product, $line, $index);

        return new StandaloneReceiptLineInput(
            productId: $product->id,
            variantId: $variantId,
            quantity: CurrencyScale::bcformatStrict($line->quantity, 4),
            freeQuantity: CurrencyScale::bcformatStrict($line->freeQuantity, 4),
            unitPrice: $unitPrice,
            batch: $line->batch?->toReceiptArray(),
        );
    }

    private function unitPrice(Product $product, ReviewedLineData $line, int $index): string
    {
        $candidate = $line->unitPrice ?? $product->purchase_price;
        $paidQuantity = CurrencyScale::bcformatStrict($line->quantity, 4);

        $candidatePrice = $candidate !== null ? CurrencyScale::bcformatStrict((string) $candidate, 3) : null;

        if ($candidatePrice === null || bccomp($candidatePrice, '0', 3) <= 0) {
            if (bccomp($paidQuantity, '0', 4) === 0) {
                return '0.001';
            }

            $this->throwValidation(
                'LINE_PRICE_REQUIRED',
                "lines.{$index}.unitPrice",
                'A positive unit price is required for paid delivery note quantities.',
            );
        }

        return $candidatePrice;
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
