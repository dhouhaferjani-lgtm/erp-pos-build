<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Domain\QuantityScale;
use App\Shared\DTOs\LocationIncomingRowDTO;
use App\Shared\DTOs\LocationStockPageDTO;
use App\Shared\DTOs\LocationStockRowDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventory-owned implementation of the POS location stock feed (spec §4.1).
 *
 * Stock term: per-(product, variant) stock_levels rows at the location,
 * paginated, delta-filtered on updated_at when a cursor is given.
 *
 * Incoming term (page 1 only, ALWAYS the complete set for the location —
 * incoming changes don't touch destination stock_levels.updated_at, so the
 * delta cursor must never filter it):
 * - in-transit stock-transfer lines whose destination is this location
 *   (variant-grain), and
 * - confirmed purchase-order unreceived remainders at this location
 *   (product-grain — mirrors ProductController's stock-levels incoming query).
 *
 * Both terms are tenant-DB-internal (no central-DB join).
 */
final class LocationStockQueryService implements LocationStockReader
{
    private const QTY_SCALE = 4;

    public function read(
        string $tenantId,
        string $companyId,
        string $locationId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): LocationStockPageDTO {
        $paginator = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->when($updatedSince !== null, fn ($q) => $q->where('updated_at', '>', $updatedSince))
            ->orderBy('product_id')
            ->orderBy('variant_id')
            ->paginate(perPage: $perPage, page: $page);

        $stock = [];
        /** @var StockLevel $level */
        foreach ($paginator->items() as $level) {
            $stock[] = new LocationStockRowDTO(
                productId: $level->product_id,
                variantId: $level->variant_id,
                quantity: $level->quantity,
                reserved: $level->reserved,
                available: $level->getAvailableQuantity(),
                updatedAt: $level->updated_at?->toIso8601String() ?? '',
            );
        }

        return new LocationStockPageDTO(
            stock: $stock,
            incoming: $page === 1 ? $this->incoming($tenantId, $companyId, $locationId) : [],
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }

    /**
     * Complete incoming set for the location: in-transit transfer quantities
     * (variant-grain) merged with confirmed-PO unreceived remainders
     * (product-grain — they land on the variantId = null key).
     *
     * @return list<LocationIncomingRowDTO>
     */
    private function incoming(string $tenantId, string $companyId, string $locationId): array
    {
        /** @var array<string, array{productId: string, variantId: string|null, transfer: string, po: string}> $byKey */
        $byKey = [];

        /** @var Collection<int, object{product_id: string, variant_id: string|null, incoming: float|int|string}> $transferRows */
        $transferRows = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfer_lines.transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfer_lines.tenant_id', $tenantId)
            ->where('stock_transfer_lines.company_id', $companyId)
            ->where('stock_transfers.destination_location_id', $locationId)
            ->where('stock_transfers.status', TransferStatus::InTransit->value)
            ->groupBy('stock_transfer_lines.product_id', 'stock_transfer_lines.variant_id')
            ->selectRaw('
                stock_transfer_lines.product_id,
                stock_transfer_lines.variant_id,
                SUM(stock_transfer_lines.quantity) as incoming
            ')
            ->get();

        foreach ($transferRows as $row) {
            $key = $row->product_id.'|'.($row->variant_id ?? '');
            $byKey[$key] = [
                'productId' => $row->product_id,
                'variantId' => $row->variant_id,
                'transfer' => QuantityScale::round((string) $row->incoming, self::QTY_SCALE, QuantityScale::FLOOR),
                'po' => '0.0000',
            ];
        }

        // Confirmed-PO unreceived remainder, product-grain, scoped to this
        // location — same shape as ProductController@stockLevels' incoming
        // query, but grouped by product for ONE location instead of by
        // location for one product.
        // Query-builder, NOT the Document model (Rule 6: never import models
        // across modules — enums are the sanctioned shared vocabulary).
        /** @var Collection<int, object{product_id: string, incoming: float|int|string}> $poRows */
        $poRows = DB::table('document_lines')
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->where('documents.tenant_id', $tenantId)
            ->where('documents.company_id', $companyId)
            ->where('documents.type', DocumentType::PurchaseOrder->value)
            ->where('documents.status', DocumentStatus::Confirmed->value)
            ->where('document_lines.location_id', $locationId)
            ->whereNotNull('document_lines.product_id')
            ->whereRaw('document_lines.quantity > COALESCE(document_lines.quantity_received, 0)')
            ->selectRaw('
                document_lines.product_id,
                SUM(document_lines.quantity - COALESCE(document_lines.quantity_received, 0)) as incoming
            ')
            ->groupBy('document_lines.product_id')
            ->get();

        foreach ($poRows as $row) {
            $key = $row->product_id.'|';
            $po = QuantityScale::round((string) $row->incoming, self::QTY_SCALE, QuantityScale::FLOOR);

            if (isset($byKey[$key])) {
                $byKey[$key]['po'] = $po;
            } else {
                $byKey[$key] = [
                    'productId' => $row->product_id,
                    'variantId' => null,
                    'transfer' => '0.0000',
                    'po' => $po,
                ];
            }
        }

        ksort($byKey);

        $rows = [];
        foreach ($byKey as $key => $sums) {
            $rows[] = new LocationIncomingRowDTO(
                productId: $sums['productId'],
                variantId: $sums['variantId'],
                incomingTransfer: $sums['transfer'],
                incomingPo: $sums['po'],
            );
        }

        return $rows;
    }
}
