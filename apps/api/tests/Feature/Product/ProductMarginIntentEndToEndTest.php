<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductMarginIntentEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant  = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id'    => $this->user->id,
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
        $this->actingAs($this->user, 'sanctum');
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * PATCH pricing_mode=auto + target_margin_override → controller applies the
     * intent seam, saves overrides, then calls MarginService::updateSalePrice()
     * which recomputes sale_price = cost × (1 + target_margin / 100).
     *
     * cost_price = 10.000000, target_margin_override = 50.00
     * → sale_price = 10 × 1.5 = 15.00  (stored) → decimal:3 cast → '15.000'
     */
    public function test_editing_margin_via_api_keeps_auto_and_recomputes_price(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'tenant_id'  => $this->tenant->id,
            'cost_price' => '10.000000',
        ]);

        $response = $this->patchJson("/api/v1/products/{$product->id}", [
            'pricing_mode'           => 'auto',
            'target_margin_override' => '50.00',
        ]);

        $response->assertOk();

        $fresh = $product->fresh();

        $this->assertSame(PricingMode::Auto, $fresh->pricing_mode);
        // decimal:3 cast normalises '50.00' → '50.000'
        $this->assertSame('50.000', (string) $fresh->target_margin_override);
        // 10 × 1.5 = 15.00 stored, decimal:3 cast → '15.000'
        $this->assertSame('15.000', (string) $fresh->sale_price);
    }

    /**
     * PATCH pricing_mode=manual + sale_price → controller routes through intent
     * seam which sets mode=Manual and persists the caller-supplied price without
     * any auto-reprice.
     */
    public function test_editing_price_via_api_sets_manual(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'tenant_id'  => $this->tenant->id,
            'cost_price' => '10.000000',
        ]);

        $response = $this->patchJson("/api/v1/products/{$product->id}", [
            'pricing_mode' => 'manual',
            'sale_price'   => '99.000',
        ]);

        $response->assertOk();

        $fresh = $product->fresh();

        $this->assertSame(PricingMode::Manual, $fresh->pricing_mode);
        $this->assertSame('99.000', (string) $fresh->sale_price);
    }

    /**
     * A PATCH that changes ONLY pricing fields (no base fields) must still
     * dispatch ProductUpdated — the event must not be gated solely on base-field
     * changes ($productModel->getChanges() after the base update is empty in this
     * case, but the pricing seam mutated the model).
     */
    public function test_pricing_only_update_emits_product_updated_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Modules\Product\Domain\Events\ProductUpdated::class]);

        $product = Product::factory()->for($this->company)->create([
            'tenant_id'  => $this->tenant->id,
            'cost_price' => '10.000000',
        ]);

        $this->patchJson("/api/v1/products/{$product->id}", [
            'pricing_mode'           => 'auto',
            'target_margin_override' => '50.00',
        ])->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Modules\Product\Domain\Events\ProductUpdated::class);
    }
}
