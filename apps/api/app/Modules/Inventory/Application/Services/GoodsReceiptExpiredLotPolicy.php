<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Inventory\Domain\DTOs\GoodsReceiptFailureDetails;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptFailureReason;
use App\Modules\Inventory\Domain\Exceptions\GoodsReceiptException;
use App\Modules\Product\Domain\Product;
use Carbon\CarbonImmutable;

final class GoodsReceiptExpiredLotPolicy
{
    private const int QUANTITY_SCALE = 4;

    /**
     * Return the canonical refusal for the first positive receipt line whose
     * supplied lot expiry is in the past. Document order wins over payload order.
     *
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, string>  $freeQuantities
     * @param  array<string, array{expiry_date?: string|null}>  $batchData
     */
    public function firstRefusal(
        ?Document $document,
        array $receivedQuantities,
        array $freeQuantities,
        array $batchData,
    ): ?GoodsReceiptException {
        if ($document === null) {
            return $this->firstSanitizedRefusal($receivedQuantities, $freeQuantities, $batchData);
        }

        $document->loadMissing('lines');
        foreach ($document->lines->sortBy('line_number') as $line) {
            if (! $this->hasPositiveQuantity((string) $line->id, $receivedQuantities, $freeQuantities)) {
                continue;
            }

            $expiry = $batchData[(string) $line->id]['expiry_date'] ?? null;
            if (! is_string($expiry) || ! $this->isPastExpiry($expiry)) {
                continue;
            }

            $product = Product::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $document->company_id)
                ->find($line->product_id);

            return $this->refusal($this->lineDetails($line, $product), $expiry);
        }

        return null;
    }

    public function isPastExpiry(?string $expiry): bool
    {
        return $expiry !== null
            && strtotime($expiry) !== false
            && CarbonImmutable::parse($expiry)->startOfDay()->lt(now()->startOfDay());
    }

    /**
     * @param  array{line_number: int, sku: string|null, description: string}  $line
     */
    private function refusal(array $line, string $expiry): GoodsReceiptException
    {
        return new GoodsReceiptException(
            GoodsReceiptFailureReason::ExpiredLotRefused,
            sprintf(
                'Cannot receive expired lot for %s: expiry date %s is before today.',
                $this->label($line),
                $expiry,
            ),
            new GoodsReceiptFailureDetails(
                lines: [$line],
                suppliedExpiry: $expiry,
            ),
        );
    }

    /**
     * Preserve a typed, non-disclosing refusal when the route document is not
     * visible in the active tenant/company/purchase-order scope.
     *
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, string>  $freeQuantities
     * @param  array<string, array{expiry_date?: string|null}>  $batchData
     */
    private function firstSanitizedRefusal(
        array $receivedQuantities,
        array $freeQuantities,
        array $batchData,
    ): ?GoodsReceiptException {
        foreach ($batchData as $lineId => $batch) {
            $expiry = $batch['expiry_date'] ?? null;
            if (! $this->hasPositiveQuantity($lineId, $receivedQuantities, $freeQuantities)
                || ! is_string($expiry)
                || ! $this->isPastExpiry($expiry)) {
                continue;
            }

            return $this->refusal([
                'line_number' => 1,
                'sku' => null,
                'description' => '',
            ], $expiry);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, string>  $freeQuantities
     */
    private function hasPositiveQuantity(string $lineId, array $receivedQuantities, array $freeQuantities): bool
    {
        $paid = $receivedQuantities[$lineId] ?? '0';
        $free = $freeQuantities[$lineId] ?? '0';

        return (is_numeric($paid) && bccomp($paid, '0', self::QUANTITY_SCALE) > 0)
            || (is_numeric($free) && bccomp($free, '0', self::QUANTITY_SCALE) > 0);
    }

    /** @return array{line_number: int, sku: string|null, description: string} */
    private function lineDetails(DocumentLine $line, ?Product $product): array
    {
        $productSku = $product instanceof Product ? trim((string) $product->sku) : '';

        return [
            'line_number' => (int) $line->line_number,
            'sku' => $productSku !== '' ? $productSku : null,
            'description' => trim((string) ($line->description ?? $line->designation_default_snapshot ?? '')),
        ];
    }

    /** @param array{line_number: int, sku: string|null, description: string} $line */
    private function label(array $line): string
    {
        $designations = [];
        if ($line['sku'] !== null && $line['sku'] !== '') {
            $designations[] = $line['sku'];
        }
        if ($line['description'] !== '' && $line['description'] !== $line['sku']) {
            $designations[] = $line['description'];
        }

        return sprintf(
            'line %d%s',
            $line['line_number'],
            $designations === [] ? '' : ' ('.implode(' — ', $designations).')',
        );
    }
}
