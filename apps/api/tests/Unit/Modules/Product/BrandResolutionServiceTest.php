<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\Product\Application\Services\BrandResolutionService;
use App\Modules\Product\Domain\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BrandResolutionService $service;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BrandResolutionService::class);
        $this->tenantId = (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeBrand(array $attrs = []): Brand
    {
        return Brand::create(array_merge([
            'tenant_id' => $this->tenantId,
            'name' => 'La Roche-Posay',
            'slug' => 'la-roche-posay',
            'is_active' => true,
        ], $attrs));
    }

    public function test_reuses_brand_by_external_id_and_does_not_push(): void
    {
        $canonical = (string) Str::uuid();
        $brand = $this->makeBrand();

        $resolution = $this->service->resolve($this->tenantId, 'Different Name', $canonical, $brand->id);

        $this->assertTrue($resolution->brand->is($brand));
        $this->assertFalse($resolution->shouldPushMapping);
        $this->assertSame($canonical, $resolution->brand->fresh()->canonical_brand_id, 'fills canonical when null');
        $this->assertSame('La Roche-Posay', $resolution->brand->fresh()->name, 'never renames');
        $this->assertSame(1, Brand::count(), 'no duplicate created');
    }

    public function test_external_id_with_conflicting_canonical_keeps_local_value(): void
    {
        $localCanonical = (string) Str::uuid();
        $brand = $this->makeBrand(['canonical_brand_id' => $localCanonical]);

        $resolution = $this->service->resolve($this->tenantId, 'X', (string) Str::uuid(), $brand->id);

        $this->assertSame($localCanonical, $resolution->brand->fresh()->canonical_brand_id);
        $this->assertFalse($resolution->shouldPushMapping);
    }

    public function test_stale_external_id_falls_back_to_canonical_match_without_push(): void
    {
        $canonical = (string) Str::uuid();
        $brand = $this->makeBrand(['canonical_brand_id' => $canonical]);

        $resolution = $this->service->resolve($this->tenantId, 'Renamed Brand', $canonical, (string) Str::uuid());

        $this->assertTrue($resolution->brand->is($brand));
        $this->assertFalse($resolution->shouldPushMapping, 'no local state change');
        $this->assertSame(1, Brand::count());
    }

    public function test_non_uuid_external_id_does_not_crash_and_falls_back(): void
    {
        $canonical = (string) Str::uuid();
        $brand = $this->makeBrand(['canonical_brand_id' => $canonical]);

        $resolution = $this->service->resolve($this->tenantId, 'X', $canonical, 'erp-brand-42-not-a-uuid');

        $this->assertTrue($resolution->brand->is($brand));
    }

    public function test_resolves_by_canonical_id_when_slug_differs(): void
    {
        $canonical = (string) Str::uuid();
        $this->makeBrand(['canonical_brand_id' => $canonical]);

        $resolution = $this->service->resolve($this->tenantId, 'LRP (new name)', $canonical, null);

        $this->assertSame(1, Brand::count(), 'no duplicate for renamed canonical brand');
        $this->assertFalse($resolution->shouldPushMapping, 'read-only rung');
    }

    public function test_resolves_by_slug_and_fills_canonical(): void
    {
        $canonical = (string) Str::uuid();
        $brand = $this->makeBrand();

        $resolution = $this->service->resolve($this->tenantId, 'La Roche-Posay', $canonical, null);

        $this->assertTrue($resolution->brand->is($brand));
        $this->assertSame($canonical, $resolution->brand->fresh()->canonical_brand_id);
        $this->assertTrue($resolution->shouldPushMapping);
    }

    public function test_slug_match_with_conflicting_canonical_does_not_overwrite_or_push(): void
    {
        $existing = (string) Str::uuid();
        $brand = $this->makeBrand(['canonical_brand_id' => $existing]);

        $resolution = $this->service->resolve($this->tenantId, 'La Roche-Posay', (string) Str::uuid(), null);

        $this->assertTrue($resolution->brand->is($brand));
        $this->assertSame($existing, $resolution->brand->fresh()->canonical_brand_id);
        $this->assertFalse($resolution->shouldPushMapping);
    }

    public function test_creates_brand_with_canonical_and_pushes(): void
    {
        $canonical = (string) Str::uuid();

        $resolution = $this->service->resolve($this->tenantId, 'Avène', $canonical, null);

        $this->assertSame('avene', $resolution->brand->slug);
        $this->assertSame($canonical, $resolution->brand->canonical_brand_id);
        $this->assertTrue($resolution->brand->is_active);
        $this->assertTrue($resolution->shouldPushMapping);
    }

    public function test_legacy_payload_without_canonical_matches_today_behavior(): void
    {
        $resolution = $this->service->resolve($this->tenantId, 'Avène', null, null);

        $this->assertSame('avene', $resolution->brand->slug);
        $this->assertNull($resolution->brand->canonical_brand_id);
        $this->assertFalse($resolution->shouldPushMapping);
    }

    public function test_canonical_fill_skipped_when_another_brand_holds_it(): void
    {
        $canonical = (string) Str::uuid();
        $this->makeBrand(['slug' => 'holder', 'name' => 'Holder', 'canonical_brand_id' => $canonical]);
        $orphan = $this->makeBrand(['slug' => 'avene', 'name' => 'Avène']);

        $resolution = $this->service->resolve($this->tenantId, 'Avène', $canonical, $orphan->id);

        $this->assertTrue($resolution->brand->is($orphan));
        $this->assertNull($orphan->fresh()->canonical_brand_id, 'fill skipped');
        $this->assertFalse($resolution->shouldPushMapping);
    }

    public function test_scopes_to_tenant(): void
    {
        $canonical = (string) Str::uuid();
        $otherTenantBrand = Brand::create([
            'tenant_id' => (string) Str::uuid(),
            'name' => 'Avène',
            'slug' => 'avene',
            'canonical_brand_id' => $canonical,
            'is_active' => true,
        ]);

        $resolution = $this->service->resolve($this->tenantId, 'Avène', $canonical, $otherTenantBrand->id);

        $this->assertFalse($resolution->brand->is($otherTenantBrand));
        $this->assertSame($this->tenantId, $resolution->brand->tenant_id);
    }
}
