<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Observers\TenantObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for TenantObserver - cache invalidation on vertical changes.
 *
 * These tests verify that:
 * 1. Tenant config cache is invalidated when tenant.vertical changes
 * 2. Tenant config cache is invalidated when tenant.enabled_extras changes
 * 3. Cache is NOT invalidated when other tenant fields change
 *
 * Note: Cache is tenant-based (tenant_config:{tenant_id}) since all companies
 * within a tenant share the same vertical configuration.
 */
class TenantObserverTest extends TestCase
{
    use RefreshDatabase;

    private TenantObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->observer = new TenantObserver;
    }

    public function test_invalidates_tenant_config_cache_when_vertical_changes(): void
    {
        // Create tenant
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        // Simulate cache being set
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'data'], 3600);

        // Verify cache exists
        $this->assertTrue(Cache::has($cacheKey), 'Cache should exist before update');

        // Change vertical
        $tenant->vertical = 'pharmacy';
        $tenant->save();

        // Verify cache was invalidated
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after vertical change');
    }

    public function test_invalidates_tenant_config_cache_when_enabled_extras_changes(): void
    {
        // Create tenant
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        // Simulate cache being set
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'data'], 3600);

        // Verify cache exists
        $this->assertTrue(Cache::has($cacheKey), 'Cache should exist before update');

        // Change enabled_extras
        $tenant->enabled_extras = ['Fleet'];
        $tenant->save();

        // Verify cache was invalidated
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after enabled_extras change');
    }

    public function test_invalidates_cache_even_with_multiple_companies(): void
    {
        // Create tenant with multiple companies (to ensure observer works with relationships)
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        // Create multiple companies for this tenant
        Company::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        // Simulate cache being set
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'data'], 3600);

        // Verify cache exists
        $this->assertTrue(Cache::has($cacheKey));

        // Change vertical
        $tenant->vertical = 'pharmacy';
        $tenant->save();

        // Verify cache was invalidated (one cache entry per tenant, regardless of company count)
        $this->assertFalse(Cache::has($cacheKey), 'Tenant cache should be invalidated');
    }

    public function test_does_not_invalidate_cache_when_other_fields_change(): void
    {
        // Create tenant
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
            'name' => 'Original Name',
        ]);

        // Simulate cache being set
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'data'], 3600);

        // Verify cache exists
        $this->assertTrue(Cache::has($cacheKey));

        // Change non-vertical field
        $tenant->name = 'Updated Name';
        $tenant->save();

        // Verify cache was NOT invalidated
        $this->assertTrue(Cache::has($cacheKey), 'Cache should NOT be invalidated when non-vertical fields change');
    }

    public function test_does_not_throw_error_when_tenant_has_no_companies(): void
    {
        // Create tenant without companies
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        // Change vertical - should not throw error
        $tenant->vertical = 'pharmacy';
        $tenant->save();

        // If we get here, no error was thrown
        $this->assertTrue(true);
    }

    public function test_invalidates_cache_when_vertical_and_extras_change_together(): void
    {
        // Create tenant
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        // Simulate cache being set
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'data'], 3600);

        // Verify cache exists
        $this->assertTrue(Cache::has($cacheKey));

        // Change both vertical and enabled_extras
        $tenant->vertical = 'pharmacy';
        $tenant->enabled_extras = ['BatchExpiry'];
        $tenant->save();

        // Verify cache was invalidated (should only invalidate once)
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated when multiple relevant fields change');
    }
}
