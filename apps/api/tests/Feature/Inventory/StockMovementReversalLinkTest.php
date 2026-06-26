<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C1 — reverses_movement_id self-FK + relations + double-reverse DB guard.
 *
 * Gates:
 *  1. reverses_movement_id column is nullable and present in the schema.
 *  2. reversesMovement() BelongsTo resolves to the original movement.
 *  3. reversalOf() HasOne resolves to the reversal from the original's perspective.
 *  4. Inserting two rows with the same reverses_movement_id raises a unique violation
 *     (PostgreSQL only via partial unique index; skipped on SQLite).
 */
final class StockMovementReversalLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * Minimal set of non-nullable column values required to persist a StockMovement.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function movementAttributes(array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '5.0000',
            'quantity_before' => '10.0000',
            'quantity_after' => '15.0000',
        ], $overrides);
    }

    /** Gate 1: reverses_movement_id column is nullable and present. */
    public function test_column_is_nullable_and_present(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_movements', 'reverses_movement_id'));

        $movement = StockMovement::create($this->movementAttributes());

        $this->assertNull($movement->reverses_movement_id);
    }

    /** Gate 2: reversesMovement() BelongsTo resolves to the original movement. */
    public function test_reverses_movement_belongs_to_relation(): void
    {
        $original = StockMovement::create($this->movementAttributes());
        $reversal = StockMovement::create($this->movementAttributes([
            'reverses_movement_id' => $original->id,
        ]));

        $this->assertSame($original->id, $reversal->reverses_movement_id);

        $resolved = $reversal->reversesMovement;

        $this->assertInstanceOf(StockMovement::class, $resolved);
        $this->assertSame($original->id, $resolved->id);
    }

    /** Gate 3: reversalOf() HasOne resolves to the reversal from the original's perspective. */
    public function test_reversal_of_has_one_relation(): void
    {
        $original = StockMovement::create($this->movementAttributes());
        $reversal = StockMovement::create($this->movementAttributes([
            'reverses_movement_id' => $original->id,
        ]));

        $resolved = $original->reversalOf;

        $this->assertInstanceOf(StockMovement::class, $resolved);
        $this->assertSame($reversal->id, $resolved->id);
    }

    /**
     * Gate 4: inserting two rows with the same reverses_movement_id raises a unique violation.
     *
     * The guard is a PostgreSQL partial unique index:
     *   CREATE UNIQUE INDEX … ON stock_movements (reverses_movement_id)
     *   WHERE reverses_movement_id IS NOT NULL
     *
     * SQLite does support partial unique indexes via CREATE UNIQUE INDEX … WHERE …
     * and the migration creates one, but enforcement in `:memory:` SQLite is
     * inconsistent between driver versions.  The test is therefore skipped on
     * non-PostgreSQL connections.  The production DB (PostgreSQL) is fully guarded.
     */
    public function test_double_reverse_violates_unique_constraint(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index enforcement tested on PostgreSQL only.');
        }

        $original = StockMovement::create($this->movementAttributes());

        StockMovement::create($this->movementAttributes([
            'reverses_movement_id' => $original->id,
        ]));

        $this->expectException(QueryException::class);

        StockMovement::create($this->movementAttributes([
            'reverses_movement_id' => $original->id,
        ]));
    }
}
