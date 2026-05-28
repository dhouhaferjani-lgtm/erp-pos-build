<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1.1.6 — verify variant_id column and indexes on stock_movements
 * and stock_reservations.
 *
 * Migrations:
 *   - 2026_06_02_100006_add_variant_id_to_stock_movements
 *   - 2026_06_02_100007_add_variant_id_to_stock_reservations
 *
 * Note: stock_reservations has company_id (not tenant_id); its covering index
 * is stock_reservations_company_product_variant_released_idx.
 */
class StockMovementsVariantColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movements_has_variant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_movements', 'variant_id'));
    }

    public function test_stock_reservations_has_variant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_reservations', 'variant_id'));
    }

    public function test_stock_movements_variant_index_exists(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent indexes are PostgreSQL-only.');
        }

        $row = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'stock_movements_tenant_product_variant_created_idx'"
        );

        $this->assertNotNull($row, 'Index stock_movements_tenant_product_variant_created_idx should exist.');
    }

    public function test_stock_reservations_variant_index_exists(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent indexes are PostgreSQL-only.');
        }

        $row = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'stock_reservations_company_product_variant_released_idx'"
        );

        $this->assertNotNull($row, 'Index stock_reservations_company_product_variant_released_idx should exist.');
    }
}
