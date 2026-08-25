<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.1.7 — verify partial unique indexes on product_batches.
 *
 * These tests assert the two PostgreSQL partial unique indexes introduced by
 * migration 2026_06_02_100008:
 *   - product_batches_non_variant  (variant_id IS NULL)
 *   - product_batches_with_variant (variant_id IS NOT NULL)
 *
 * The legacy unique constraint unique_batch_per_product over
 * (company_id, product_id, batch_number) is dropped by the same migration;
 * the partial indexes replace its semantics and extend them to support variants.
 */
class BatchesVariantUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * Build the base column set required for a product_batches row.
     *
     * Required NOT NULL columns (no DB default):
     *   uuid, tenant_id, company_id, product_id, batch_number, expiry_date
     * Columns with DB defaults (is_active = true, is_expired/is_recalled = false): omitted.
     *
     * @return array<string, mixed>
     */
    private function baseRow(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'batch_number' => 'LOT-001',
            'expiry_date' => '2027-12-31',
        ];
    }

    /**
     * A non-variant batch and a variant batch with the same batch_number for the
     * same (company, product) must both succeed — they fall under different partial
     * indexes.
     */
    public function test_can_have_non_variant_and_variant_batch_with_same_number(): void
    {
        // DRIVER-AWARE (inherited red). The two sibling cases below already
        // carry this guard; this one needs it for the SAME reason and had been
        // erroring on the SQLite leg ever since the partial indexes landed.
        // `2026_06_02_100008_add_variant_id_to_product_batches` returns early on
        // any non-pgsql driver, so under SQLite the pre-variant
        // `unique_batch_per_product (company_id, product_id, batch_number)`
        // constraint survives and rejects exactly the coexistence this test
        // asserts. The partitioning it pins only exists on PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        // Non-variant batch (variant_id IS NULL)
        DB::table('product_batches')->insert($this->baseRow());

        // Variant batch (variant_id IS NOT NULL) — same batch_number
        DB::table('product_batches')->insert(array_merge($this->baseRow(), [
            'uuid' => (string) Str::uuid(),
            'variant_id' => $variant->id,
        ]));

        $this->assertSame(2, DB::table('product_batches')
            ->where('batch_number', 'LOT-001')
            ->count());
    }

    /**
     * Two variant batches with the same (company_id, product_id, variant_id, batch_number)
     * must be rejected by the product_batches_with_variant partial unique index.
     */
    public function test_duplicate_variant_batch_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        DB::table('product_batches')->insert(array_merge($this->baseRow(), [
            'variant_id' => $variant->id,
        ]));

        $this->expectException(QueryException::class);

        // Second insert — same (company_id, product_id, variant_id, batch_number)
        DB::table('product_batches')->insert(array_merge($this->baseRow(), [
            'uuid' => (string) Str::uuid(),
            'variant_id' => $variant->id,
        ]));
    }

    /**
     * Two non-variant batches with the same (company_id, product_id, batch_number)
     * must be rejected by the product_batches_non_variant partial unique index.
     */
    public function test_duplicate_non_variant_batch_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        DB::table('product_batches')->insert($this->baseRow());

        // Second insert — same (company_id, product_id, batch_number) with variant_id null
        DB::table('product_batches')->insert(array_merge($this->baseRow(), [
            'uuid' => (string) Str::uuid(),
            'variant_id' => null,
        ]));
    }
}
