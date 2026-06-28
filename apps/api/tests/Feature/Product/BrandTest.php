<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Product\Domain\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_brand_and_slug_for_returns_normalized_slug(): void
    {
        $tenantId = (string) Str::uuid();

        $brand = Brand::create([
            'tenant_id' => $tenantId,
            'name' => 'La Roche-Posay',
            'slug' => Brand::slugFor('La Roche-Posay'),
            'is_active' => true,
        ]);

        $this->assertSame('la-roche-posay', $brand->slug);
        $this->assertSame($tenantId, $brand->tenant_id);
        $this->assertTrue($brand->is_active);
    }

    public function test_duplicate_slug_within_same_tenant_throws_query_exception(): void
    {
        $this->expectException(QueryException::class);

        $tenantId = (string) Str::uuid();

        Brand::create([
            'tenant_id' => $tenantId,
            'name' => 'La Roche-Posay',
            'slug' => Brand::slugFor('La Roche-Posay'),
            'is_active' => true,
        ]);

        // Duplicate slug within same tenant — must violate unique(tenant_id, slug)
        Brand::create([
            'tenant_id' => $tenantId,
            'name' => 'X',
            'slug' => 'la-roche-posay',
            'is_active' => true,
        ]);
    }

    public function test_same_slug_in_different_tenants_is_allowed(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();

        $brandA = Brand::create([
            'tenant_id' => $tenantA,
            'name' => 'La Roche-Posay',
            'slug' => Brand::slugFor('La Roche-Posay'),
            'is_active' => true,
        ]);

        $brandB = Brand::create([
            'tenant_id' => $tenantB,
            'name' => 'La Roche-Posay',
            'slug' => Brand::slugFor('La Roche-Posay'),
            'is_active' => true,
        ]);

        $this->assertNotSame($brandA->id, $brandB->id);
        $this->assertSame('la-roche-posay', $brandA->slug);
        $this->assertSame('la-roche-posay', $brandB->slug);
    }
}
