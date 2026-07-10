<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Models\User;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReplenishmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_replenishment_requests_and_capture_receipts(): void
    {
        $this->assertTrue(Schema::hasTable('replenishment_requests'));
        $this->assertTrue(Schema::hasColumns('replenishment_requests', [
            'id',
            'tenant_id',
            'company_id',
            'location_id',
            'product_id',
            'variant_id',
            'requested_qty',
            'note',
            'request_count',
            'status',
            'source_channel',
            'requested_by_user_id',
            'first_requested_at',
            'last_requested_at',
            'client_request_uuid',
            'sourcing_document_id',
            'fulfillment_type',
            'fulfillment_id',
            'processed_by_user_id',
            'processed_at',
            'rejection_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasTable('replenishment_capture_receipts'));
        $this->assertTrue(Schema::hasColumns('replenishment_capture_receipts', [
            'id',
            'tenant_id',
            'company_id',
            'client_request_uuid',
            'request_id',
            'applied_at',
        ]));
    }

    public function test_open_rows_are_unique_per_product_location(): void
    {
        $base = $this->baseRow();

        DB::table('replenishment_requests')->insert($base);

        $this->expectException(QueryException::class);
        DB::table('replenishment_requests')->insert([...$base, 'id' => Str::uuid()->toString()]);
    }

    public function test_closed_rows_do_not_block_a_new_open_row(): void
    {
        $base = $this->baseRow();

        DB::table('replenishment_requests')->insert([...$base, 'status' => 'fulfilled']);
        DB::table('replenishment_requests')->insert([...$base, 'id' => Str::uuid()->toString()]);

        $this->assertSame(2, DB::table('replenishment_requests')->count());
    }

    public function test_variant_rows_are_unique_separately(): void
    {
        $base = $this->baseRow();
        $product = Product::query()->findOrFail($base['product_id']);
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $base['tenant_id'],
            'company_id' => $base['company_id'],
            'product_id' => $product->id,
        ]);
        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $base['tenant_id'],
            'company_id' => $base['company_id'],
            'product_id' => $product->id,
        ]);

        DB::table('replenishment_requests')->insert([...$base, 'variant_id' => $variantA->id]);
        DB::table('replenishment_requests')->insert([
            ...$base,
            'id' => Str::uuid()->toString(),
            'variant_id' => $variantB->id,
        ]);

        $this->assertSame(2, DB::table('replenishment_requests')->count());

        $this->expectException(QueryException::class);
        DB::table('replenishment_requests')->insert([
            ...$base,
            'id' => Str::uuid()->toString(),
            'variant_id' => $variantA->id,
        ]);
    }

    public function test_open_scope_qualifies_status_for_joined_queries(): void
    {
        $sql = ReplenishmentRequest::query()->open()->toSql();

        $this->assertStringContainsString('"replenishment_requests"."status"', $sql);
    }

    /** @return array<string, int|string|null> */
    private function baseRow(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $now = now();

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'requested_qty' => null,
            'note' => null,
            'request_count' => 1,
            'status' => 'pending',
            'source_channel' => 'web',
            'requested_by_user_id' => $user->id,
            'first_requested_at' => $now,
            'last_requested_at' => $now,
            'client_request_uuid' => null,
            'sourcing_document_id' => null,
            'fulfillment_type' => null,
            'fulfillment_id' => null,
            'processed_by_user_id' => null,
            'processed_at' => null,
            'rejection_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
