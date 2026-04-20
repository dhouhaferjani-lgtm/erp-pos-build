<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Infrastructure\Resolvers\EloquentProductResolver;
use App\Modules\Workshop\Bundle\Infrastructure\Resolvers\EloquentServiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against the resolver / bundle tax_rate scale drift bug.
 *
 * Before this test existed: ServiceBundle stored tax_rate at scale 3
 * (`19.000`) while both EloquentProductResolver and EloquentServiceResolver
 * emitted `bcformat(..., 2)` (`19.00`), so string equality comparisons in
 * BundleExpansionService::assertVatEquivalence could mis-detect a mixed-
 * VAT bundle and incorrectly throw MixedVatInFixedBundleException even
 * when the component rate matched the bundle rate exactly.
 *
 * Fix: both resolvers now emit tax_rate at scale 3 to match the bundle
 * column (see ServiceBundle::casts 'tax_rate' => 'decimal:3').
 */
final class TaxRateScaleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_resolver_emits_tax_rate_at_scale_3(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'TND']);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '10.000',
            'tax_rate' => '19.000',
        ]);

        $resolver = $this->app->make(EloquentProductResolver::class);
        $ref = $resolver->findForBundleComponent($product->id);

        $this->assertNotNull($ref);
        $this->assertSame('19.000', $ref->tax_rate);
    }

    public function test_service_resolver_emits_tax_rate_at_scale_3(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'TND']);

        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'currency' => 'TND',
            'base_price' => '25.000',
            'tax_rate' => '7.000',
        ]);

        $resolver = $this->app->make(EloquentServiceResolver::class);
        $ref = $resolver->findForBundleComponent($service->id);

        $this->assertNotNull($ref);
        $this->assertSame('7.000', $ref->tax_rate);
    }
}
