<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Domain\QuantityScale;
use App\Shared\DTOs\LocationIncomingRowDTO;
use App\Shared\DTOs\LocationStockPageDTO;
use App\Shared\DTOs\LocationStockRowDTO;
use App\Shared\DTOs\StockDistributionDTO;
use App\Shared\DTOs\StockDistributionRowDTO;
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
        // Clamp: page 0 would make paginate() fall back to the HTTP request's
        // current page (request-coupled behavior inside a service), and a
        // negative page would report page 1 stock with empty incoming.
        $page = max(1, $page);

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
                updatedAt: $level->updated_at?->toIso8601String(),
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
     * Cross-location stock distribution for one product (+ optional variant)
     * across all active shop+warehouse locations of the company (Task B4).
     *
     * Three grouped queries only — no per-location loop of read():
     *  (1) the active shop+warehouse locations,
     *  (2) on-hand per location (variant-grain matched), and
     *  (3) in-transit incoming per destination location (variant-grain matched).
     */
    public function stockDistributionForProduct(
        string $tenantId,
        string $companyId,
        string $productId,
        ?string $variantId,
        string $currentLocationId,
    ): StockDistributionDTO {
        // (1) Active shop+warehouse locations (one query). Office/mobile and
        // inactive locations are excluded.
        /** @var \Illuminate\Database\Eloquent\Collection<int, Location> $locations */
        $locations = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('type', [LocationType::Shop->value, LocationType::Warehouse->value])
            ->get(['id', 'name', 'type']);

        // (2) On-hand per location, variant-grain matched (one query). When
        // variantId is null we must match variant_id IS NULL — never sum the
        // product-grain row together with variant rows.
        $stockQuery = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('product_id', $productId);
        $variantId === null
            ? $stockQuery->whereNull('variant_id')
            : $stockQuery->where('variant_id', $variantId);

        // available = quantity − reserved, both already quantity-scale-4
        // numeric strings (StockLevel decimal:4 cast). Mirrors
        // StockLevel::getAvailableQuantity().
        /** @var array<string, numeric-string> $availableByLoc */
        $availableByLoc = [];
        foreach ($stockQuery->get(['location_id', 'quantity', 'reserved']) as $level) {
            $availableByLoc[(string) $level->location_id] = bcsub(
                $level->quantity,
                $level->reserved,
                self::QTY_SCALE,
            );
        }

        // (3) In-transit incoming per destination location (one grouped query).
        // Attributed to the destination only, only while the transfer carries an open remainder.
        $transferQuery = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfer_lines.transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfer_lines.tenant_id', $tenantId)
            ->where('stock_transfer_lines.company_id', $companyId)
            ->where('stock_transfer_lines.product_id', $productId)
            ->whereIn('stock_transfers.status', StockTransfer::CARRYING_STATUSES);
        $variantId === null
            ? $transferQuery->whereNull('stock_transfer_lines.variant_id')
            : $transferQuery->where('stock_transfer_lines.variant_id', $variantId);

        /** @var array<string, numeric-string> $incomingByLoc */
        $incomingByLoc = [];
        /** @var Collection<int, object{loc: string, incoming: float|int|string}> $incomingRows */
        $incomingRows = $transferQuery
            ->groupBy('stock_transfers.destination_location_id')
            ->selectRaw('
                stock_transfers.destination_location_id as loc,
                SUM('.StockTransferLine::REMAINDER_SQL.') as incoming
            ')
            ->get();
        foreach ($incomingRows as $row) {
            $incomingByLoc[(string) $row->loc] = QuantityScale::round(
                (string) $row->incoming,
                self::QTY_SCALE,
                QuantityScale::FLOOR,
            );
        }

        // (4) Assemble zero-filled rows, current first then by name, and
        // accumulate the totals from the same numeric-string source values.
        /** @var numeric-string $totalOnHand */
        $totalOnHand = '0.0000';
        /** @var numeric-string $totalIncoming */
        $totalIncoming = '0.0000';
        $rows = [];
        foreach ($locations as $loc) {
            $id = (string) $loc->id;
            $onHand = $availableByLoc[$id] ?? '0.0000';
            $incoming = $incomingByLoc[$id] ?? '0.0000';

            $totalOnHand = bcadd($totalOnHand, $onHand, self::QTY_SCALE);
            $totalIncoming = bcadd($totalIncoming, $incoming, self::QTY_SCALE);

            $rows[] = new StockDistributionRowDTO(
                locationId: $id,
                locationName: (string) $loc->name,
                locationType: $loc->type->value,
                isCurrent: $id === $currentLocationId,
                onHand: $onHand,
                incomingTransfer: $incoming,
            );
        }

        usort($rows, function (StockDistributionRowDTO $a, StockDistributionRowDTO $b): int {
            if ($a->isCurrent !== $b->isCurrent) {
                return $a->isCurrent ? -1 : 1;
            }

            return strcmp($a->locationName, $b->locationName);
        });

        return new StockDistributionDTO(
            productId: $productId,
            variantId: $variantId,
            variantLabel: null,
            locations: $rows,
            totalOnHand: $totalOnHand,
            totalIncomingTransfer: $totalIncoming,
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
            ->whereIn('stock_transfers.status', StockTransfer::CARRYING_STATUSES)
            ->groupBy('stock_transfer_lines.product_id', 'stock_transfer_lines.variant_id')
            ->selectRaw('
                stock_transfer_lines.product_id,
                stock_transfer_lines.variant_id,
                SUM('.StockTransferLine::REMAINDER_SQL.') as incoming
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
