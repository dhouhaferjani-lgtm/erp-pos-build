<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk product by location stock read model.
 *
 * The product dimension is paginated first. Stock, variant labels, and
 * optional incoming quantities are then loaded in grouped queries for that
 * page, so the response never performs one query per matrix cell.
 *
 * @phpstan-type StockRow object{product_id: string, variant_id: string|null, location_id: string, quantity: string|int|float, reserved: string|int|float, min_quantity: string|int|float|null, max_quantity: string|int|float|null}
 */
final class StockMatrixQueryService
{
    private const SCALE = QuantityScale::SCALE;

    /**
     * @param  list<string>  $locationIds
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public function matrix(
        string $tenantId,
        string $companyId,
        array $locationIds,
        string $search,
        int $page,
        int $perPage,
        bool $includeIncoming,
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $productsPage = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function (Builder $nested) use ($like): void {
                    $nested->whereRaw('LOWER(name) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(sku) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(barcode) LIKE LOWER(?)', [$like]);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'name', 'sku'], 'page', $page);

        /** @var list<object{id: string, name: string, sku: string}> $productRows */
        $productRows = array_values($productsPage->items());
        /** @var list<string> $productIds */
        $productIds = array_map(static fn (object $product): string => (string) $product->id, $productRows);

        if ($productIds === []) {
            return [
                'data' => [],
                'meta' => $this->meta($productsPage),
            ];
        }

        $products = [];
        foreach ($productRows as $product) {
            $products[(string) $product->id] = [
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
            ];
        }

        /** @var Collection<int, StockRow> $stockRows */
        $stockRows = DB::table('stock_levels')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $locationIds)
            ->get([
                'product_id',
                'variant_id',
                'location_id',
                'quantity',
                'reserved',
                'min_quantity',
                'max_quantity',
            ]);

        /** @var array<string, array{label: string, order: int}> $variantLabels */
        $variantLabels = [];
        /** @var Collection<int, object{id: string, product_id: string, name_suffix: string, display_order: int}> $variantRows */
        $variantRows = DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'name_suffix', 'display_order']);
        foreach ($variantRows as $variant) {
            $variantLabels[(string) $variant->id] = [
                'label' => (string) $variant->name_suffix,
                'order' => (int) $variant->display_order,
            ];
        }

        /** @var array<string, numeric-string> $incomingByKey */
        $incomingByKey = [];
        if ($includeIncoming) {
            $incomingByKey = $this->incoming($tenantId, $companyId, $productIds, $locationIds);
        }

        /** @var array<string, Collection<int, StockRow>> $byProduct */
        $byProduct = [];
        foreach ($stockRows as $stockRow) {
            $byProduct[(string) $stockRow->product_id] ??= collect();
            $byProduct[(string) $stockRow->product_id]->push($stockRow);
        }

        $rows = [];
        foreach ($productIds as $productId) {
            $rowsForProduct = $byProduct[$productId] ?? collect();
            $hasVariantRows = $rowsForProduct->contains(
                static fn (object $row): bool => $row->variant_id !== null,
            );

            $parentCells = $this->zeroFilledCells($locationIds, $includeIncoming);
            foreach ($rowsForProduct as $stockRow) {
                $locationId = (string) $stockRow->location_id;
                if (! isset($parentCells[$locationId])) {
                    continue;
                }
                $parentCells[$locationId]['on_hand'] = bcadd(
                    $parentCells[$locationId]['on_hand'],
                    $this->quantity((string) $stockRow->quantity),
                    self::SCALE,
                );
                $parentCells[$locationId]['reserved'] = bcadd(
                    $parentCells[$locationId]['reserved'],
                    $this->quantity((string) $stockRow->reserved),
                    self::SCALE,
                );
            }
            if ($includeIncoming) {
                $this->addIncoming($parentCells, $incomingByKey, $productId, null);
            }
            $parentCells = $this->finalizeCells($parentCells, $hasVariantRows);

            if (! $hasVariantRows) {
                $cells = $this->applyThresholds($parentCells, $rowsForProduct);
                $rows[] = $this->row(
                    $productId,
                    null,
                    $products[$productId]['name'],
                    $products[$productId]['sku'],
                    false,
                    $cells,
                );

                continue;
            }

            $rows[] = $this->row(
                $productId,
                null,
                $products[$productId]['name'],
                $products[$productId]['sku'],
                true,
                $parentCells,
            );

            $variantGroups = [];
            foreach ($rowsForProduct as $stockRow) {
                if ($stockRow->variant_id !== null) {
                    $variantGroups[(string) $stockRow->variant_id] ??= collect();
                    $variantGroups[(string) $stockRow->variant_id]->push($stockRow);
                }
            }
            uksort($variantGroups, function (string $left, string $right) use ($variantLabels): int {
                $leftOrder = $variantLabels[$left]['order'] ?? PHP_INT_MAX;
                $rightOrder = $variantLabels[$right]['order'] ?? PHP_INT_MAX;

                return $leftOrder <=> $rightOrder ?: strcmp($left, $right);
            });

            foreach ($variantGroups as $variantId => $variantGroup) {
                $cells = $this->cellsForRows($variantGroup, $locationIds, $includeIncoming, $incomingByKey, $productId, $variantId);
                $suffix = $variantLabels[$variantId]['label'] ?? $variantId;
                $rows[] = $this->row(
                    $productId,
                    $variantId,
                    $products[$productId]['name'].' '.$suffix,
                    $products[$productId]['sku'],
                    false,
                    $cells,
                );
            }

            $baseRows = $rowsForProduct->filter(static fn (object $row): bool => $row->variant_id === null);
            $baseNonzero = $baseRows->contains(fn (object $row): bool => bccomp($this->quantity((string) $row->quantity), '0', self::SCALE) !== 0
                || bccomp($this->quantity((string) $row->reserved), '0', self::SCALE) !== 0,
            );
            if ($baseNonzero) {
                $cells = $this->cellsForRows($baseRows, $locationIds, $includeIncoming, $incomingByKey, $productId, null);
                $rows[] = $this->row(
                    $productId,
                    null,
                    $products[$productId]['name'].' (base)',
                    $products[$productId]['sku'],
                    false,
                    $cells,
                );
            }
        }

        return [
            'data' => $rows,
            'meta' => $this->meta($productsPage),
        ];
    }

    /**
     * @param  list<string>  $locationIds
     * @return array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>
     */
    private function zeroFilledCells(array $locationIds, bool $includeIncoming): array
    {
        $cells = [];
        foreach ($locationIds as $locationId) {
            $cell = [
                'on_hand' => '0.0000',
                'reserved' => '0.0000',
                'available' => '0.0000',
                'min_quantity' => null,
                'max_quantity' => null,
            ];
            if ($includeIncoming) {
                $cell['incoming'] = '0.0000';
            }
            $cells[(string) $locationId] = $cell;
        }

        return $cells;
    }

    /**
     * @param  array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>  $cells
     * @param  array<string, numeric-string>  $incomingByKey
     */
    private function addIncoming(array &$cells, array $incomingByKey, string $productId, ?string $variantId): void
    {
        foreach ($cells as $locationId => &$cell) {
            $incoming = '0.0000';
            if ($variantId !== null) {
                $incoming = $incomingByKey[$productId.'|'.$variantId.'|'.$locationId] ?? '0.0000';
            } else {
                // Rollups include every incoming grain, including variants
                // that do not yet have an on-hand row on this page.
                foreach ($incomingByKey as $key => $value) {
                    if (str_starts_with($key, $productId.'|') && str_ends_with($key, '|'.$locationId)) {
                        $incoming = bcadd($incoming, $value, self::SCALE);
                    }
                }
            }
            $cell['incoming'] = $this->quantity($incoming);
        }
        unset($cell);
    }

    /**
     * @param  Collection<int, StockRow>  $rows
     * @param  list<string>  $locationIds
     * @param  array<string, numeric-string>  $incomingByKey
     * @return array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>
     */
    private function cellsForRows(Collection $rows, array $locationIds, bool $includeIncoming, array $incomingByKey, string $productId, ?string $variantId): array
    {
        $cells = $this->zeroFilledCells($locationIds, $includeIncoming);
        foreach ($rows as $row) {
            $locationId = (string) $row->location_id;
            if (! isset($cells[$locationId])) {
                continue;
            }
            $cells[$locationId]['on_hand'] = bcadd($cells[$locationId]['on_hand'], $this->quantity((string) $row->quantity), self::SCALE);
            $cells[$locationId]['reserved'] = bcadd($cells[$locationId]['reserved'], $this->quantity((string) $row->reserved), self::SCALE);
            $cells[$locationId]['min_quantity'] = $row->min_quantity === null ? null : $this->quantity((string) $row->min_quantity);
            $cells[$locationId]['max_quantity'] = $row->max_quantity === null ? null : $this->quantity((string) $row->max_quantity);
        }
        if ($includeIncoming) {
            foreach ($cells as $locationId => &$cell) {
                $key = $productId.'|'.($variantId ?? '').'|'.$locationId;
                $cell['incoming'] = $incomingByKey[$key] ?? '0.0000';
            }
            unset($cell);
        }

        return $this->finalizeCells($cells, false);
    }

    /**
     * @param  array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>  $cells
     * @return array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>
     */
    private function finalizeCells(array $cells, bool $thresholdsNull): array
    {
        foreach ($cells as &$cell) {
            $cell['available'] = bcsub($cell['on_hand'], $cell['reserved'], self::SCALE);
            if ($thresholdsNull) {
                $cell['min_quantity'] = null;
                $cell['max_quantity'] = null;
            }
        }
        unset($cell);

        return $cells;
    }

    /**
     * @param  array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>  $cells
     * @param  Collection<int, StockRow>  $rows
     * @return array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>
     */
    private function applyThresholds(array $cells, Collection $rows): array
    {
        foreach ($cells as $locationId => &$cell) {
            $row = $rows->first(static fn (object $candidate): bool => (string) $candidate->location_id === (string) $locationId
            );
            if ($row === null) {
                continue;
            }
            $cell['min_quantity'] = $row->min_quantity === null ? null : $this->quantity((string) $row->min_quantity);
            $cell['max_quantity'] = $row->max_quantity === null ? null : $this->quantity((string) $row->max_quantity);
        }
        unset($cell);

        return $cells;
    }

    /**
     * @param  array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>  $cells
     * @return array{product_id: string, variant_id: string|null, name: string, sku: string, is_variant_parent: bool, cells: array<string, array{on_hand: numeric-string, reserved: numeric-string, available: numeric-string, min_quantity: string|null, max_quantity: string|null, incoming?: numeric-string}>}
     */
    private function row(string $productId, ?string $variantId, string $name, string $sku, bool $isVariantParent, array $cells): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'name' => $name,
            'sku' => $sku,
            'is_variant_parent' => $isVariantParent,
            'cells' => $cells,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, object>  $paginator
     * @return array{current_page: int, last_page: int, total: int}
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @return numeric-string */
    private function quantity(string $value): string
    {
        return QuantityScale::round($value, self::SCALE, QuantityScale::HALF_UP);
    }

    /**
     * @param  list<string>  $productIds
     * @param  list<string>  $locationIds
     * @return array<string, numeric-string>
     */
    private function incoming(string $tenantId, string $companyId, array $productIds, array $locationIds): array
    {
        /** @var array<string, numeric-string> $byKey */
        $byKey = [];

        /** @var Collection<int, object{product_id: string, variant_id: string|null, location_id: string, incoming: string|int|float}> $transferRows */
        $transferRows = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfer_lines.transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfer_lines.tenant_id', $tenantId)
            ->where('stock_transfer_lines.company_id', $companyId)
            ->whereIn('stock_transfer_lines.product_id', $productIds)
            ->whereIn('stock_transfers.destination_location_id', $locationIds)
            ->where('stock_transfers.status', TransferStatus::InTransit->value)
            ->groupBy('stock_transfer_lines.product_id', 'stock_transfer_lines.variant_id', 'stock_transfers.destination_location_id')
            ->selectRaw('stock_transfer_lines.product_id, stock_transfer_lines.variant_id, stock_transfers.destination_location_id as location_id, SUM(stock_transfer_lines.quantity) as incoming')
            ->get();
        foreach ($transferRows as $row) {
            $key = (string) $row->product_id.'|'.($row->variant_id ?? '').'|'.(string) $row->location_id;
            $byKey[$key] = bcadd($byKey[$key] ?? '0.0000', $this->quantity((string) $row->incoming), self::SCALE);
        }

        /** @var Collection<int, object{product_id: string, location_id: string, incoming: string|int|float}> $poRows */
        $poRows = DB::table('document_lines')
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->where('documents.tenant_id', $tenantId)
            ->where('documents.company_id', $companyId)
            ->where('documents.type', DocumentType::PurchaseOrder->value)
            ->where('documents.status', DocumentStatus::Confirmed->value)
            ->whereIn('document_lines.product_id', $productIds)
            ->whereIn('document_lines.location_id', $locationIds)
            ->whereNotNull('document_lines.product_id')
            ->whereRaw('document_lines.quantity > COALESCE(document_lines.quantity_received, 0)')
            ->selectRaw('document_lines.product_id, document_lines.location_id, SUM(document_lines.quantity - COALESCE(document_lines.quantity_received, 0)) as incoming')
            ->groupBy('document_lines.product_id', 'document_lines.location_id')
            ->get();
        foreach ($poRows as $row) {
            $key = (string) $row->product_id.'||'.(string) $row->location_id;
            $byKey[$key] = bcadd($byKey[$key] ?? '0.0000', $this->quantity((string) $row->incoming), self::SCALE);
        }

        return $byKey;
    }
}
