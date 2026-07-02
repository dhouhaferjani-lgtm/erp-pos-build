<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ReconcileModulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refreshes_existing_tenant_module_config_when_vertical_defaults_change(): void
    {
        $tenant = Tenant::create([
            'name' => 'Existing Parapharmacy',
            'slug' => 'existing-parapharmacy',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => [],
        ]);

        $service = app(CompanyConfigService::class);
        $originalConfig = config('verticals.parapharmacy');
        $this->assertIsArray($originalConfig);

        $withoutPurchaseBonus = $originalConfig;
        $withoutPurchaseBonus['default_modules'] = array_values(array_filter(
            $withoutPurchaseBonus['default_modules'],
            static fn (string $module): bool => $module !== 'PurchaseBonus',
        ));

        Config::set('verticals.parapharmacy', $withoutPurchaseBonus);
        $this->assertNotContains('PurchaseBonus', $service->getConfigForTenant($tenant)->allEnabledModules);

        Config::set('verticals.parapharmacy', $originalConfig);
        $this->assertNotContains(
            'PurchaseBonus',
            $service->getConfigForTenant($tenant)->allEnabledModules,
            'Precondition: stale tenant module cache still reflects the old vertical defaults.'
        );

        $this->assertSame(0, Artisan::call('tenant:reconcile-modules'));
        $this->assertStringContainsString(
            'Reconciled tenant modules: 1 refreshed, 0 pruned extras across 1 tenant(s).',
            Artisan::output(),
        );

        $freshTenant = $tenant->fresh();
        $this->assertInstanceOf(Tenant::class, $freshTenant);

        $this->assertContains('PurchaseBonus', $service->getConfigForTenant($freshTenant)->allEnabledModules);
    }
}
