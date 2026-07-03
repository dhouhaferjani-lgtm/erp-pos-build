<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Product\Domain\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandCanonicalUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_brands_with_same_tenant_and_canonical_id_are_rejected(): void
    {
        $tenantId = (string) Str::uuid();
        $canonicalId = (string) Str::uuid();

        Brand::create([
            'tenant_id' => $tenantId,
            'name' => 'Avene',
            'slug' => 'avene',
            'canonical_brand_id' => $canonicalId,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Brand::create([
            'tenant_id' => $tenantId,
            'name' => 'Avène (dup)',
            'slug' => 'avene-dup',
            'canonical_brand_id' => $canonicalId,
            'is_active' => true,
        ]);
    }

    public function test_multiple_brands_with_null_canonical_id_are_allowed(): void
    {
        $tenantId = (string) Str::uuid();

        Brand::create(['tenant_id' => $tenantId, 'name' => 'A', 'slug' => 'a', 'is_active' => true]);
        Brand::create(['tenant_id' => $tenantId, 'name' => 'B', 'slug' => 'b', 'is_active' => true]);

        $this->assertSame(2, Brand::where('tenant_id', $tenantId)->count());
    }

    public function test_same_canonical_id_allowed_across_tenants(): void
    {
        $canonicalId = (string) Str::uuid();

        Brand::create(['tenant_id' => (string) Str::uuid(), 'name' => 'A', 'slug' => 'a', 'canonical_brand_id' => $canonicalId, 'is_active' => true]);
        Brand::create(['tenant_id' => (string) Str::uuid(), 'name' => 'A', 'slug' => 'a', 'canonical_brand_id' => $canonicalId, 'is_active' => true]);

        $this->assertSame(2, Brand::where('canonical_brand_id', $canonicalId)->count());
    }
}
