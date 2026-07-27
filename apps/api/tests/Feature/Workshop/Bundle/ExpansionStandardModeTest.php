<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Application\Services\BundleExpansionService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpansionStandardModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Unit $unit;

    private BundleExpansionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        $this->unit = Unit::factory()->create(['symbol' => 'L']);
        $this->service = $this->app->make(BundleExpansionService::class);
    }

    public function test_standard_mode_emits_one_line_per_component_with_line_totals(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);

        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Engine Oil 5W-30',
            'sale_price' => '15.000',
            'tax_rate' => '19.000',
            'unit' => 'liter',
        ]);
        $filter = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Filter',
            'sale_price' => '12.000',
            'tax_rate' => '19.000',
            'unit' => 'piece',
        ]);
        $labor = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '20.000',
            'currency' => 'TND',
            'tax_rate' => '19.000',
            'name' => 'Oil-Change Labor',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '4.000')->create([
            'unit_id' => $this->unit->id,
        ]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($filter, '1.000')->create([
            'unit_id' => $this->unit->id,
        ]);
        ServiceBundleComponent::factory()->forBundle($bundle)->labor($labor, '1.000')->create([
            'unit_id' => $this->unit->id,
        ]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);

        $this->assertCount(3, $lines);

        $totals = $lines->map(fn ($line): string => $line->line_total)->all();
        $this->assertContains('60.000', $totals);   // oil: 4 * 15
        $this->assertContains('12.000', $totals);   // filter
        $this->assertContains('20.000', $totals);   // labor
    }

    public function test_standard_mode_applies_override_unit_price(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);
        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '15.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '2.000')
            ->overridePrice('10.000')
            ->create(['unit_id' => $this->unit->id]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);

        $this->assertCount(1, $lines);
        $line = $lines->first();
        $this->assertNotNull($line);
        $this->assertSame('10.000', $line->unit_price);
        $this->assertSame('20.000', $line->line_total);
    }

    public function test_expand_quantity_scales_each_line(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);
        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '10.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '2.000')
            ->create(['unit_id' => $this->unit->id]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '3', null);

        $this->assertCount(1, $lines);
        $line = $lines->first();
        $this->assertNotNull($line);
        $this->assertSame('6.0000', $line->quantity);    // 2 * 3, fallback unit scale 4
        $this->assertSame('60.000', $line->line_total);  // 6 * 10
    }

    public function test_component_id_is_non_null_for_standard_mode_lines(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);
        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '10.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '1.000')
            ->create(['unit_id' => $this->unit->id]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);
        $line = $lines->first();
        $this->assertNotNull($line);
        $this->assertSame($oil->id, $line->component_id);
        $this->assertSame(BundleComponentType::Part, $line->component_type);
        $this->assertFalse($line->is_from_fixed_bundle);
    }

    public function test_expansion_lines_expose_part_and_labor_quantity_precision(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::Standard,
            'currency' => 'TND',
        ]);
        $productUnit = Unit::factory()->create(['decimal_places' => 3]);
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $productUnit->id,
            'sale_price' => '10.000',
        ]);
        $labor = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '20.000',
            'currency' => 'TND',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($part, '1.250')->create([
            'unit_id' => $this->unit->id,
        ]);
        ServiceBundleComponent::factory()->forBundle($bundle)->labor($labor, '0.50')->create([
            'unit_id' => $this->unit->id,
        ]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);
        $partLine = $lines->first(fn ($line): bool => $line->component_type === BundleComponentType::Part);
        $laborLine = $lines->first(fn ($line): bool => $line->component_type === BundleComponentType::Labor);

        $this->assertNotNull($partLine);
        $this->assertNotNull($laborLine);
        $this->assertSame(3, $partLine->quantity_decimals ?? null);
        $this->assertSame(2, $laborLine->quantity_decimals ?? null);
    }
}
