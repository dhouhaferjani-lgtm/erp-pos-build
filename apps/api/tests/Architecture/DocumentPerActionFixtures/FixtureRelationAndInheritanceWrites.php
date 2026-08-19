<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two resolution paths the required write vocabulary names but that no other
 * fixture exercises (M1 gate findings 2 and 5):
 *
 *  - RELATION-MEDIATED writes — `$parent->levels()->create([...])`. The relation
 *    map is built from the scanned tree, so this fixture declares its own
 *    `hasMany(StockLevel::class)` host; without a fixture the whole relation
 *    path (including its `hasMany` prefilter) could rot unnoticed — exactly the
 *    detector-rot class this package exists to prevent.
 *  - MODEL-INTERNAL writes — `$this->update([...])` inside a class that IS (or
 *    extends) one of the four models. Live examples the guard missed before this
 *    fix: BatchStock::adjustQuantity and StockLevel::recalculateReserved.
 *
 * PARSED, never executed.
 */
final class FixtureRelationAndInheritanceWrites
{
    public function relationCreateBypassingMovement(FixtureRelationHost $host): void
    {
        $host->fixtureStockLevels()->create([
            'product_id' => 'p-1',
            'location_id' => 'l-1',
            'quantity' => '4.0000',
        ]);
    }

    public function relationCreateWithMovement(FixtureRelationHost $host, string $documentId): void
    {
        StockMovement::create([
            'product_id' => 'p-1',
            'quantity' => '4.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $documentId,
        ]);

        $host->fixtureStockLevels()->create([
            'product_id' => 'p-1',
            'location_id' => 'l-1',
            'quantity' => '4.0000',
        ]);
    }
}

/**
 * Relation host for the fixture above. Declares the only `hasMany` in the
 * fixture tree so the relation map has something to resolve.
 */
final class FixtureRelationHost extends Model
{
    /**
     * @return HasMany<StockLevel, $this>
     */
    public function fixtureStockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}

/**
 * A subclass of a target model: `$this->update([...])` here writes
 * `stock_levels`, and the guard must see it through the `extends` chain.
 */
final class FixtureStockLevelSubclass extends StockLevel
{
    public function setOnHandQuantity(string $quantity): void
    {
        $this->update(['quantity' => $quantity]);
    }

    public function setReservedQuantity(string $reserved): void
    {
        $this->update(['reserved' => $reserved]);
    }

    /**
     * @param  numeric-string  $quantity
     */
    public function persistOnHand(string $quantity): void
    {
        $this->quantity = $quantity;
        $this->save();
    }
}
