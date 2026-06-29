<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Enums\EquivalenceType;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductComplement;
use App\Modules\Product\Domain\ProductEquivalent;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRelationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Parapharmacy Relations',
            'slug' => 'test-parapharmacy-relations',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeProductWithMeta(): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $meta = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        return [$product, $meta];
    }

    // ------------------------------------------------------------------
    // product_equivalents
    // ------------------------------------------------------------------

    public function test_equivalent_products_relation_returns_pivot_equivalence_type(): void
    {
        [$productA, $metaA] = $this->makeProductWithMeta();
        [$productB] = $this->makeProductWithMeta();

        // Link A → B and B → A as generic equivalents (directional — add both rows)
        ProductEquivalent::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productA->id,
            'equivalent_product_id' => $productB->id,
            'equivalence_type' => 'generic',
        ]);
        ProductEquivalent::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productB->id,
            'equivalent_product_id' => $productA->id,
            'equivalence_type' => 'generic',
        ]);

        $equivalents = $metaA->equivalentProducts()->get();

        $this->assertCount(1, $equivalents);
        $this->assertTrue($equivalents->first()->is($productB));
        $this->assertSame(EquivalenceType::Generic, $equivalents->first()->pivot->equivalence_type);
    }

    public function test_creating_self_equivalent_throws_invalid_argument_exception(): void
    {
        [$productA] = $this->makeProductWithMeta();

        $this->expectException(\InvalidArgumentException::class);

        ProductEquivalent::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productA->id,
            'equivalent_product_id' => $productA->id,
            'equivalence_type' => 'generic',
        ]);
    }

    // ------------------------------------------------------------------
    // product_complements
    // ------------------------------------------------------------------

    public function test_complement_products_relation_returns_pivot_reason(): void
    {
        [$productA, $metaA] = $this->makeProductWithMeta();
        [$productB] = $this->makeProductWithMeta();

        ProductComplement::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productA->id,
            'complement_product_id' => $productB->id,
            'reason' => 'Boosts absorption',
        ]);

        $complements = $metaA->complementProducts()->get();

        $this->assertCount(1, $complements);
        $this->assertTrue($complements->first()->is($productB));
        $this->assertSame('Boosts absorption', $complements->first()->pivot->reason);
    }

    public function test_creating_self_complement_throws_invalid_argument_exception(): void
    {
        [$productA] = $this->makeProductWithMeta();

        $this->expectException(\InvalidArgumentException::class);

        ProductComplement::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productA->id,
            'complement_product_id' => $productA->id,
            'reason' => 'Should fail',
        ]);
    }
}
