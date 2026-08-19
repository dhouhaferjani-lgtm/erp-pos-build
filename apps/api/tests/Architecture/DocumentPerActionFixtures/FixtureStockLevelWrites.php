<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;

/**
 * Tamper/liveness fixtures for the `stock_levels` row of the matrix.
 *
 * `stock_levels` has no reference column, so the LINKED form of every cell is
 * the MOVEMENT-PAIRING PREDICATE: the same function also records a movement —
 * either through the `recordMovement` chokepoint or by creating a linked
 * `stock_movements` row. Writes that touch only `reserved`/`min_quantity`/
 * `max_quantity` are soft holds and are not in contract at all.
 *
 * PARSED, never executed.
 */
final class FixtureStockLevelWrites
{
    // --- create -----------------------------------------------------------

    public function createBypassingMovement(string $productId, string $locationId): void
    {
        StockLevel::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '5.0000',
        ]);
    }

    public function createWithMovement(string $productId, string $locationId, string $documentId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '5.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);

        StockLevel::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '5.0000',
        ]);
    }

    // --- firstOrCreate ----------------------------------------------------

    public function firstOrCreateBypassingMovement(string $productId, string $locationId): void
    {
        StockLevel::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => '0.0000'],
        );
    }

    public function firstOrCreateWithMovement(string $productId, string $locationId, string $documentId): void
    {
        StockLevel::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => '0.0000'],
        );

        StockMovement::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '0.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);
    }

    // --- updateOrCreate ---------------------------------------------------

    public function updateOrCreateBypassingMovement(string $productId, string $locationId): void
    {
        StockLevel::updateOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => '9.0000'],
        );
    }

    public function updateOrCreateWithMovement(string $productId, string $locationId, string $documentId): void
    {
        StockLevel::updateOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => '9.0000'],
        );

        StockMovement::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '9.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);
    }

    // --- update -----------------------------------------------------------

    public function updateQuantityBypassingMovement(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->update(['quantity' => '12.0000']);
    }

    /**
     * The `recordMovement` chokepoint arm of the pairing predicate.
     */
    public function updateQuantityWithRecordedMovement(string $levelId): void
    {
        $this->recordMovement($levelId);

        StockLevel::query()->whereKey($levelId)->update(['quantity' => '12.0000']);
    }

    /**
     * Reservation bookkeeping: no on-hand change, no ledger effect, not in
     * contract even though no movement is recorded.
     */
    public function updateReservedOnly(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->update(['reserved' => '3.0000']);
    }

    // --- save -------------------------------------------------------------

    public function saveBypassingMovement(string $levelId): void
    {
        $level = StockLevel::query()->findOrFail($levelId);
        $level->quantity = '15.0000';
        $level->save();
    }

    public function saveWithMovement(string $levelId, string $productId, string $documentId): void
    {
        $level = StockLevel::query()->findOrFail($levelId);
        $level->quantity = '15.0000';
        $level->save();

        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '15.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);
    }

    // --- delete -----------------------------------------------------------

    public function deleteBypassingMovement(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->delete();
    }

    public function deleteWithMovement(string $levelId, string $productId, string $documentId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '0.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);

        StockLevel::query()->whereKey($levelId)->delete();
    }

    // --- increment / decrement --------------------------------------------

    public function incrementQuantityBypassingMovement(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->increment('quantity', 2);
    }

    public function incrementQuantityWithRecordedMovement(string $levelId): void
    {
        $this->recordMovement($levelId);

        StockLevel::query()->whereKey($levelId)->increment('quantity', 2);
    }

    public function decrementQuantityBypassingMovement(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->decrement('quantity', 2);
    }

    public function decrementQuantityWithRecordedMovement(string $levelId): void
    {
        $this->recordMovement($levelId);

        StockLevel::query()->whereKey($levelId)->decrement('quantity', 2);
    }

    public function decrementReservedOnly(string $levelId): void
    {
        StockLevel::query()->whereKey($levelId)->decrement('reserved', 2);
    }

    // --- query builder ----------------------------------------------------

    public function queryBuilderUpdateBypassingMovement(string $levelId): void
    {
        DB::table('stock_levels')->where('id', $levelId)->update(['quantity' => '20.0000']);
    }

    public function queryBuilderUpdateWithMovement(string $levelId, string $productId, string $documentId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '20.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);

        DB::table('stock_levels')->where('id', $levelId)->update(['quantity' => '20.0000']);
    }

    // --- raw SQL ----------------------------------------------------------

    public function rawSqlUpdate(): void
    {
        DB::statement("UPDATE stock_levels SET quantity = '0.0000' WHERE quantity < 0");
    }

    // --- helper -----------------------------------------------------------

    /**
     * Stands in for the S0 chokepoint call. The predicate matches on the call
     * NAME, exactly as it does for StockAdjustmentService::recordMovement.
     */
    private function recordMovement(string $levelId): void
    {
        // Intentionally empty: the fixture proves the pairing predicate, not
        // the chokepoint's own behaviour.
        unset($levelId);
    }
}
