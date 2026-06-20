<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\CompanyConfig;
use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Services\VerticalConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CompanyConfigService(
            app(VerticalConfigService::class)
        );
    }

    public function test_get_config_for_tenant_returns_company_config(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode(['Appointments', 'Fleet']),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertInstanceOf(CompanyConfig::class, $config);
        $this->assertEquals(Vertical::Mechanic, $config->vertical);
    }

    public function test_get_config_includes_default_modules(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertContains('Identity', $config->defaultModules);
        $this->assertContains('Tenant', $config->defaultModules);
        $this->assertContains('Catalog', $config->defaultModules);
        $this->assertContains('Vehicle', $config->defaultModules);
        $this->assertContains('Workshop', $config->defaultModules);
    }

    public function test_get_config_includes_enabled_extras(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode(['Appointments', 'Fleet']),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertEquals(['Appointments', 'Fleet'], $config->enabledExtras);
    }

    public function test_get_config_merges_default_modules_and_extras(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'pharmacy',
            'enabled_extras' => json_encode(['Prescription']),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        // Should contain both default modules and extras
        $this->assertContains('BatchExpiry', $config->allEnabledModules); // default for pharmacy
        $this->assertContains('Prescription', $config->allEnabledModules); // extra
        $this->assertContains('Identity', $config->allEnabledModules); // core default
    }

    public function test_all_enabled_modules_is_a_sequential_list_when_default_overlaps_extra(): void
    {
        // Parapharmacy's default_modules include BatchExpiry AND it is also an
        // enabled extra. array_unique() preserves keys, so without re-indexing
        // the deduped value leaves a key gap that json_encode renders as an
        // object ({"0":...,"11":...}) instead of an array — breaking the
        // frontend's `all_enabled_modules.includes(...)`.
        $tenant = Tenant::factory()->create([
            'vertical' => 'parapharmacy',
            'enabled_extras' => json_encode(['BatchExpiry', 'Loyalty', 'Ecommerce']),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertTrue(
            array_is_list($config->allEnabledModules),
            'all_enabled_modules must be a sequential list so it JSON-encodes as an array'
        );
        // No duplicate despite BatchExpiry appearing in both sources.
        $this->assertSame(
            count($config->allEnabledModules),
            count(array_unique($config->allEnabledModules))
        );
        $this->assertContains('BatchExpiry', $config->allEnabledModules);
        $this->assertContains('Loyalty', $config->allEnabledModules);
    }

    public function test_get_config_handles_empty_extras(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'retail',
            'enabled_extras' => json_encode([]),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertEmpty($config->enabledExtras);
        $this->assertEquals($config->defaultModules, $config->allEnabledModules);
    }

    public function test_get_config_handles_null_extras(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'retail',
            'enabled_extras' => null,
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        $this->assertEmpty($config->enabledExtras);
    }

    public function test_service_is_singleton(): void
    {
        $service1 = app(CompanyConfigService::class);
        $service2 = app(CompanyConfigService::class);

        $this->assertSame(
            $service1,
            $service2,
            'CompanyConfigService should be registered as singleton'
        );
    }
}
