<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Application\Services\BundleExpansionService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpansionFixedBundleModeTest extends TestCase
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

    public function test_fixed_bundle_emits_synthetic_header_plus_informational_lines(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::FixedBundle,
            'base_price' => '120.000',
            'currency' => 'TND',
            'tax_rate' => '19.000',
            'name' => 'Vidange 10k Diesel',
        ]);

        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '15.000',
            'tax_rate' => '19.000',
        ]);
        $filter = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.000',
            'tax_rate' => '19.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '4.000')->create(['unit_id' => $this->unit->id]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($filter, '1.000')->create(['unit_id' => $this->unit->id]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '1', null);

        $this->assertCount(3, $lines);

        $linesArr = $lines->values()->all();

        // Synthetic header line (first) — component_id null, unit_price = base_price.
        $header = $linesArr[0];
        $this->assertNull($header->component_id);
        $this->assertSame(BundleComponentType::NestedBundle, $header->component_type);
        $this->assertSame('120.000', $header->unit_price);
        $this->assertSame('120.000', $header->line_total);
        $this->assertFalse($header->is_from_fixed_bundle);
        $this->assertSame('Vidange 10k Diesel', $header->display_name);
        $this->assertSame(4, $header->quantity_decimals ?? null);

        // Informational component lines.
        foreach (array_slice($linesArr, 1) as $line) {
            $this->assertTrue($line->is_optional);
            $this->assertTrue($line->is_from_fixed_bundle);
            $this->assertSame('0.000', $line->line_total);
        }
    }

    public function test_fixed_bundle_header_scales_with_quantity(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'pricing_mode' => BundlePricingMode::FixedBundle,
            'base_price' => '100.000',
            'currency' => 'TND',
            'tax_rate' => '19.000',
        ]);
        $oil = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '10.000',
            'tax_rate' => '19.000',
        ]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($oil, '1.000')->create(['unit_id' => $this->unit->id]);

        $lines = $this->service->expandForWorkOrder($bundle->tenant_id, $bundle->company_id, $bundle->id, '2', null);
        $header = $lines->first();
        $this->assertNotNull($header);

        $this->assertSame('100.000', $header->unit_price);
        $this->assertSame('200.000', $header->line_total);
        $this->assertSame('2.000', $header->quantity);
    }
}
