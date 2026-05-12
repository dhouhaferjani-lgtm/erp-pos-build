<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Application\Services\BundleExpansionService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\Exceptions\MixedVatInFixedBundleException;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MixedVatRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_bundle_with_mixed_vat_rates_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'TND']);
        $unit = Unit::factory()->create();

        $bundle = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create([
            'pricing_mode' => BundlePricingMode::FixedBundle,
            'base_price' => '150.000',
            'currency' => 'TND',
            'tax_rate' => '19.000',
        ]);

        $at19 = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '15.000',
            'tax_rate' => '19.000',
        ]);
        $at7 = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '12.000',
            'tax_rate' => '7.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($at19, '1.000')->create(['unit_id' => $unit->id]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($at7, '1.000')->create(['unit_id' => $unit->id]);

        $service = $this->app->make(BundleExpansionService::class);

        $this->expectException(MixedVatInFixedBundleException::class);
        $service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);
    }

    public function test_standard_mode_tolerates_mixed_vat(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'TND']);
        $unit = Unit::factory()->create();

        $bundle = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);

        $at19 = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '10.000',
            'tax_rate' => '19.000',
        ]);
        $at7 = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '10.000',
            'tax_rate' => '7.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($at19, '1.000')->create(['unit_id' => $unit->id]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($at7, '1.000')->create(['unit_id' => $unit->id]);

        $service = $this->app->make(BundleExpansionService::class);
        $lines = $service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);

        $this->assertCount(2, $lines);
    }
}
