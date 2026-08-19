<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;

/**
 * Tamper/liveness fixtures for the `stock_movements` row of the matrix.
 *
 * The movement ledger is append-only: only CREATE-class writes have a LINKED
 * form (paired `reference_type` + `reference_id` — the S0 seam). Every MUTATE
 * and DELETE cell is a violation by rule and therefore has no negative case.
 *
 * PARSED, never executed.
 */
final class FixtureStockMovementWrites
{
    // --- create -----------------------------------------------------------

    public function createUnlinked(string $productId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference' => 'ad-hoc',
        ]);
    }

    public function createLinked(string $productId, string $documentId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference' => 'DOC-1',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);
    }

    /**
     * Half a pair is not a pair — `assertReferenceLinkagePaired` refuses this
     * shape at runtime and the guard must refuse it statically.
     */
    public function createHalfLinked(string $productId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => StockMovementReferenceType::Document,
        ]);
    }

    // --- firstOrCreate ----------------------------------------------------

    public function firstOrCreateUnlinked(string $productId): void
    {
        StockMovement::firstOrCreate(
            ['product_id' => $productId, 'reference' => 'ad-hoc'],
            ['quantity' => '1.0000'],
        );
    }

    public function firstOrCreateLinked(string $productId, string $documentId): void
    {
        StockMovement::firstOrCreate(
            ['product_id' => $productId, 'reference' => 'DOC-2'],
            [
                'quantity' => '1.0000',
                'reference_type' => StockMovementReferenceType::Document,
                'reference_id' => $documentId,
            ],
        );
    }

    // --- updateOrCreate ---------------------------------------------------

    public function updateOrCreateUnlinked(string $productId): void
    {
        StockMovement::updateOrCreate(
            ['product_id' => $productId, 'reference' => 'ad-hoc'],
            ['quantity' => '2.0000'],
        );
    }

    public function updateOrCreateLinked(string $productId, string $documentId): void
    {
        StockMovement::updateOrCreate(
            ['product_id' => $productId, 'reference' => 'DOC-3'],
            [
                'quantity' => '2.0000',
                'reference_type' => StockMovementReferenceType::Document,
                'reference_id' => $documentId,
            ],
        );
    }

    // --- update / save / delete / increment / decrement --------------------

    public function updateExistingMovement(string $movementId): void
    {
        StockMovement::query()->whereKey($movementId)->update(['quantity' => '3.0000']);
    }

    public function saveExistingMovement(string $movementId): void
    {
        $movement = StockMovement::query()->findOrFail($movementId);
        $movement->quantity = '4.0000';
        $movement->save();
    }

    public function deleteMovement(string $movementId): void
    {
        StockMovement::query()->whereKey($movementId)->delete();
    }

    public function incrementMovementQuantity(string $movementId): void
    {
        StockMovement::query()->whereKey($movementId)->increment('quantity');
    }

    public function decrementMovementQuantity(string $movementId): void
    {
        StockMovement::query()->whereKey($movementId)->decrement('quantity');
    }

    // --- query builder ----------------------------------------------------

    public function queryBuilderInsertUnlinked(string $productId): void
    {
        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'quantity' => '1.0000',
        ]);
    }

    public function queryBuilderInsertLinked(string $productId, string $documentId): void
    {
        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => 'document',
            'reference_id' => $documentId,
        ]);
    }

    // --- raw SQL ----------------------------------------------------------

    public function rawSqlInsert(): void
    {
        DB::statement("INSERT INTO stock_movements (id, quantity) VALUES (gen_random_uuid(), '1.0000')");
    }

    public function rawSqlDelete(): void
    {
        DB::statement('DELETE FROM stock_movements WHERE quantity = 0');
    }
}
