<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Application\Commands\CreateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\DeactivateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\UpdateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class CreateBundleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private BundleAuthoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->service = $this->app->make(BundleAuthoringService::class);
    }

    public function test_create_persists_bundle_with_scaled_fields(): void
    {
        $bundle = $this->service->create(new CreateBundleCommand(
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            code: 'VIDANGE-10K-DIESEL',
            name: 'Vidange 10 000 km diesel',
            description: 'Engine oil + filter + labor',
            pricing_mode: BundlePricingMode::FixedBundle,
            base_price: '130',
            currency: 'TND',
            tax_rate: '19',
            estimated_labor_hours: '0.75',
            service_interval_km: 10000,
            service_interval_months: 12,
        ));

        $this->assertSame('VIDANGE-10K-DIESEL', $bundle->code);
        $this->assertSame(BundlePricingMode::FixedBundle, $bundle->pricing_mode);
        $this->assertSame('130.000', $bundle->base_price);
        $this->assertSame('TND', $bundle->currency);
        $this->assertSame(10000, $bundle->service_interval_km);
        $this->assertTrue($bundle->is_active);
    }

    public function test_create_requires_base_price_when_fixed_bundle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(new CreateBundleCommand(
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            code: 'X',
            name: 'X',
            description: null,
            pricing_mode: BundlePricingMode::FixedBundle,
            base_price: null, // invalid combo
            currency: 'TND',
            tax_rate: null,
            estimated_labor_hours: null,
            service_interval_km: null,
            service_interval_months: null,
        ));
    }

    public function test_create_rejects_invalid_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(new CreateBundleCommand(
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            code: 'X',
            name: 'X',
            description: null,
            pricing_mode: BundlePricingMode::Standard,
            base_price: null,
            currency: 'EURO', // not ISO-4217 3-letter
            tax_rate: null,
            estimated_labor_hours: null,
            service_interval_km: null,
            service_interval_months: null,
        ));
    }

    public function test_update_replaces_only_supplied_fields(): void
    {
        $created = $this->service->create(new CreateBundleCommand(
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            code: 'B1',
            name: 'Original',
            description: 'd',
            pricing_mode: BundlePricingMode::Standard,
            base_price: null,
            currency: 'TND',
            tax_rate: '19',
            estimated_labor_hours: null,
            service_interval_km: null,
            service_interval_months: null,
        ));

        $updated = $this->service->update(new UpdateBundleCommand(
            bundle_id: $created->id,
            name: 'Renamed',
            description: null,
            pricing_mode: null,
            base_price: null,
            currency: null,
            tax_rate: null,
            estimated_labor_hours: null,
            service_interval_km: null,
            service_interval_months: null,
            is_active: null,
        ));

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame('d', $updated->description); // untouched
        $this->assertSame('B1', $updated->code);
    }

    public function test_deactivate_sets_is_active_false(): void
    {
        $created = $this->service->create(new CreateBundleCommand(
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            code: 'B2',
            name: 'X',
            description: null,
            pricing_mode: BundlePricingMode::Standard,
            base_price: null,
            currency: 'TND',
            tax_rate: null,
            estimated_labor_hours: null,
            service_interval_km: null,
            service_interval_months: null,
        ));

        $updated = $this->service->deactivate(new DeactivateBundleCommand($created->id));

        $this->assertFalse($updated->is_active);
    }
}
