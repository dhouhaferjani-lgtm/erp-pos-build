<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;

/**
 * Tamper/liveness fixtures for the `inventory_batch_stock` row of the matrix.
 *
 * Same movement-pairing predicate as `stock_levels`, plus one extra LINKED
 * arm: an `inventory_batch_movements` row whose `movement_id` (NOT-NULL FK to
 * `stock_movements`) is unconditionally present in the payload. A payload that
 * only CONDITIONALLY carries `movement_id` — the live BatchStockService shape —
 * does not qualify.
 *
 * PARSED, never executed.
 */
final class FixtureBatchStockWrites
{
    // --- create -----------------------------------------------------------

    public function createBypassingMovement(int $batchId, string $locationId): void
    {
        BatchStock::create([
            'batch_id' => $batchId,
            'location_id' => $locationId,
            'quantity' => '5.0000',
        ]);
    }

    public function createWithBatchMovement(int $batchId, string $locationId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '5.0000',
        ]);

        BatchStock::create([
            'batch_id' => $batchId,
            'location_id' => $locationId,
            'quantity' => '5.0000',
        ]);
    }

    // --- firstOrCreate ----------------------------------------------------

    public function firstOrCreateBypassingMovement(int $batchId, string $locationId): void
    {
        BatchStock::firstOrCreate(
            ['batch_id' => $batchId, 'location_id' => $locationId],
            ['quantity' => '0.0000'],
        );
    }

    public function firstOrCreateWithBatchMovement(int $batchId, string $locationId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '0.0000',
        ]);

        BatchStock::firstOrCreate(
            ['batch_id' => $batchId, 'location_id' => $locationId],
            ['quantity' => '0.0000'],
        );
    }

    /**
     * The live BatchStockService::recordBatchMovement shape: `movement_id` is
     * added to the payload only inside an `if`, so the link is NOT statically
     * guaranteed and the batch-stock write stays a violation.
     *
     * @param  array<string, mixed>  $data
     */
    public function firstOrCreateWithConditionalBatchMovement(int $batchId, string $locationId, array $data): void
    {
        BatchMovement::create($data);

        BatchStock::firstOrCreate(
            ['batch_id' => $batchId, 'location_id' => $locationId],
            ['quantity' => '0.0000'],
        );
    }

    // --- updateOrCreate ---------------------------------------------------

    public function updateOrCreateBypassingMovement(int $batchId, string $locationId): void
    {
        BatchStock::updateOrCreate(
            ['batch_id' => $batchId, 'location_id' => $locationId],
            ['quantity' => '7.0000'],
        );
    }

    public function updateOrCreateWithBatchMovement(int $batchId, string $locationId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '7.0000',
        ]);

        BatchStock::updateOrCreate(
            ['batch_id' => $batchId, 'location_id' => $locationId],
            ['quantity' => '7.0000'],
        );
    }

    // --- update -----------------------------------------------------------

    public function updateQuantityBypassingMovement(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->update(['quantity' => '11.0000']);
    }

    public function updateQuantityWithStockMovement(int $stockId, string $productId, string $documentId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '11.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);

        BatchStock::query()->whereKey($stockId)->update(['quantity' => '11.0000']);
    }

    public function updateReservedQuantityOnly(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->update(['reserved_quantity' => '2.0000']);
    }

    // --- save -------------------------------------------------------------

    public function saveBypassingMovement(int $stockId): void
    {
        $stock = BatchStock::query()->findOrFail($stockId);
        $stock->quantity = '13.0000';
        $stock->save();
    }

    public function saveWithBatchMovement(int $stockId, int $batchId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '13.0000',
        ]);

        $stock = BatchStock::query()->findOrFail($stockId);
        $stock->quantity = '13.0000';
        $stock->save();
    }

    // --- delete -----------------------------------------------------------

    public function deleteBypassingMovement(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->delete();
    }

    public function deleteWithBatchMovement(int $stockId, int $batchId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '0.0000',
        ]);

        BatchStock::query()->whereKey($stockId)->delete();
    }

    // --- increment / decrement --------------------------------------------

    public function incrementQuantityBypassingMovement(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->increment('quantity', 2);
    }

    public function incrementQuantityWithBatchMovement(int $stockId, int $batchId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '2.0000',
        ]);

        BatchStock::query()->whereKey($stockId)->increment('quantity', 2);
    }

    public function decrementQuantityBypassingMovement(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->decrement('quantity', 2);
    }

    public function decrementQuantityWithBatchMovement(int $stockId, int $batchId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '2.0000',
        ]);

        BatchStock::query()->whereKey($stockId)->decrement('quantity', 2);
    }

    public function decrementReservedQuantityOnly(int $stockId): void
    {
        BatchStock::query()->whereKey($stockId)->decrement('reserved_quantity', 2);
    }

    // --- query builder ----------------------------------------------------

    public function queryBuilderUpdateBypassingMovement(int $stockId): void
    {
        DB::table('inventory_batch_stock')->where('id', $stockId)->update(['quantity' => '17.0000']);
    }

    public function queryBuilderUpdateWithBatchMovement(int $stockId, int $batchId, string $movementId): void
    {
        BatchMovement::create([
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => '17.0000',
        ]);

        DB::table('inventory_batch_stock')->where('id', $stockId)->update(['quantity' => '17.0000']);
    }

    // --- raw SQL ----------------------------------------------------------

    public function rawSqlUpdate(): void
    {
        DB::statement("UPDATE inventory_batch_stock SET quantity = '0.0000' WHERE quantity < 0");
    }
}
