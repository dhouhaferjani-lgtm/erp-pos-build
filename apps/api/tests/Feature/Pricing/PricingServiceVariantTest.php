<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Pricing\Domain\Services\PricingService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 17 — PricingService variant-aware resolution.
 *
 * Resolution order (spec §5.3 / §9.2 — LOCKED):
 *   1. Variant price_override
 *   2. Partner price list, variant-specific
 *   3. Partner price list, variant-agnostic
 *   4. Default price list, variant-specific
 *   5. Default price list, variant-agnostic
 *   6. Product sale_price fallback
 */
final class PricingServiceVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    /** @var string UUID of the product variant */
    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Variant Pricing',
            'slug' => 'tenant-variant-pricing',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company Variant',
            'legal_name' => 'Company Variant LLC',
            'tax_id' => 'TAX-VAR-PRICING',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->product = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SKU-VAR-T17',
            'name' => 'Product Variant T17',
            'type' => 'part',
            'cost_price' => '50.00',
            'sale_price' => '100.00',
            'is_active' => true,
        ]);

        // Seed a product variant via direct DB insert (no factory for this model yet).
        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'RED-L',
            'sku' => 'SKU-VAR-T17-RED-L',
            'name_suffix' => 'Red / L',
            'is_default' => false,
            'is_active' => true,
            'display_order' => 1,
            'price_override' => null, // overridden per-test
            'cost_override' => null,
            'image_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pin the company context for every service call in this test class.
        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    // ──────────────────────────────────────────────────────────────────
    // Test 1: variant price_override beats everything else
    // ──────────────────────────────────────────────────────────────────

    public function test_variant_override_wins_over_price_list(): void
    {
        // Set price_override on the variant.
        DB::table('product_variants')
            ->where('id', $this->variantId)
            ->update(['price_override' => '75.0000']);

        // Create a partner + partner price list with a variant-agnostic row (90).
        $partnerId = Str::uuid()->toString();
        $priceListId = Str::uuid()->toString();
        $this->seedPartner($partnerId);
        $this->seedPriceList($priceListId, 'EUR', false, false);
        $this->seedPartnerPriceList($partnerId, $priceListId);
        // Variant-agnostic row at 90
        $this->seedPriceListItem($priceListId, $this->product->id, null, '90.000', '1');

        /** @var PricingService $svc */
        $svc = app(PricingService::class);

        $result = $svc->getPrice(
            productId: $this->product->id,
            partnerId: $partnerId,
            quantity: '1.00',
            currency: 'EUR',
            variantId: $this->variantId,
        );

        $this->assertSame('variant_override', $result['source'], 'variant price_override must be chosen first.');
        // SQLite returns the decimal column as an unpadded string (e.g. '75');
        // PostgreSQL returns '75.0000'. Assert numerically so both drivers pass.
        /** @var numeric-string $priceStr */
        $priceStr = (string) $result['price'];
        $this->assertTrue(
            bccomp($priceStr, '75', 4) === 0,
            'price must equal the variant price_override (75). Got: '.$result['price'],
        );
        $this->assertNull($result['price_list_id'], 'price_list_id must be null for variant_override source.');
    }

    // ──────────────────────────────────────────────────────────────────
    // Test 2: variant-specific price-list row used when override is null
    // ──────────────────────────────────────────────────────────────────

    public function test_variant_price_list_item_used_when_no_override(): void
    {
        // Variant has no price_override (null — default from setUp).
        $partnerId = Str::uuid()->toString();
        $priceListId = Str::uuid()->toString();
        $this->seedPartner($partnerId);
        $this->seedPriceList($priceListId, 'EUR', false, true); // is_active=true required
        $this->seedPartnerPriceList($partnerId, $priceListId);

        // Variant-agnostic row at 90 (min_qty=1); variant-specific row at 80 (min_qty=2).
        // Query with qty=2 so both rows match (2>=1 and 2>=2). The variant-specific
        // row must win. Using different min_quantity values also avoids the legacy
        // SQLite unique index on (price_list_id, product_id, min_quantity) that
        // exists before the PostgreSQL-only conditional unique migration takes effect.
        $this->seedPriceListItem($priceListId, $this->product->id, null, '90.000', '1');
        $this->seedPriceListItem($priceListId, $this->product->id, $this->variantId, '80.000', '2');

        /** @var PricingService $svc */
        $svc = app(PricingService::class);

        $result = $svc->getPrice(
            productId: $this->product->id,
            partnerId: $partnerId,
            quantity: '2.00',
            currency: 'EUR',
            variantId: $this->variantId,
        );

        $this->assertSame('partner_price_list', $result['source']);
        $this->assertSame('80.000', $result['price'], 'variant-specific row (80) must beat variant-agnostic row (90).');
        $this->assertSame($priceListId, $result['price_list_id']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Test 3: falls back through variant-agnostic default price list row
    // ──────────────────────────────────────────────────────────────────

    public function test_falls_back_to_variant_agnostic_then_product(): void
    {
        // No partner; no price_override on variant.
        $priceListId = Str::uuid()->toString();
        $this->seedPriceList($priceListId, 'EUR', true, true);
        // Only a variant-agnostic row (95); no variant-specific row.
        $this->seedPriceListItem($priceListId, $this->product->id, null, '95.000', '1');

        /** @var PricingService $svc */
        $svc = app(PricingService::class);

        $result = $svc->getPrice(
            productId: $this->product->id,
            partnerId: null,
            quantity: '1.00',
            currency: 'EUR',
            variantId: $this->variantId,
        );

        $this->assertSame('default_price_list', $result['source']);
        $this->assertSame('95.000', $result['price'], 'variant-agnostic default price list row must be used.');
        $this->assertSame($priceListId, $result['price_list_id']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Test 4: existing non-variant callers are completely unchanged
    // ──────────────────────────────────────────────────────────────────

    public function test_existing_non_variant_callers_unchanged(): void
    {
        // No price lists, no partner — falls straight through to product sale_price.
        /** @var PricingService $svc */
        $svc = app(PricingService::class);

        $result = $svc->getPrice(
            productId: $this->product->id,
            // variantId intentionally omitted (null default)
        );

        $this->assertSame('base_price', $result['source'], 'Without variantId the existing fallback must be used.');
        // sale_price column is un-cast decimal; compare as a numeric string.
        /** @var numeric-string $priceStr */
        $priceStr = (string) $result['price'];
        $this->assertTrue(
            bccomp($priceStr, '100', 2) === 0,
            'price must equal product sale_price (100). Got: '.$result['price'],
        );
        $this->assertNull($result['price_list_id']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function seedPartner(string $partnerId): void
    {
        DB::table('partners')->insert([
            'id' => $partnerId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Partner T17',
            'type' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPriceList(
        string $priceListId,
        string $currency,
        bool $isDefault,
        bool $isActive,
    ): void {
        DB::table('price_lists')->insert([
            'id' => $priceListId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'PL-T17-'.$priceListId,
            'name' => 'Price List T17',
            'currency' => $currency,
            'is_default' => $isDefault,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPartnerPriceList(string $partnerId, string $priceListId): void
    {
        DB::table('partner_price_lists')->insert([
            'id' => Str::uuid()->toString(),
            'partner_id' => $partnerId,
            'price_list_id' => $priceListId,
            'is_active' => true,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPriceListItem(
        string $priceListId,
        string $productId,
        ?string $variantId,
        string $price,
        string $minQuantity,
    ): void {
        DB::table('price_list_items')->insert([
            'id' => Str::uuid()->toString(),
            'price_list_id' => $priceListId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'price' => $price,
            'min_quantity' => $minQuantity,
            'max_quantity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
