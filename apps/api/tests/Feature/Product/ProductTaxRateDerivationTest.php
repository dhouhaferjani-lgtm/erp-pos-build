<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Campaign defect N-1 (P0) — a product's non-default VAT rate was silently
 * discarded.
 *
 * `products.tax_rate` is the DENORMALISED number every downstream consumer
 * reads: the POS reads it unconditionally (`ReceiptCreationService`) and seals
 * it into the fiscal hash chain, and `DocumentLineTaxResolver` falls back to
 * it. Before this lane, `ProductController::store()` derived that number from
 * `categories.default_tax_rate` / `companies.default_tax_rate` ONLY, never
 * from the `default_tax_configuration_id` the operator actually picked, and
 * `::update()` had no tax handling at all — so a Tunisian parapharmacy that
 * chose "TVA 7 %" stored `default_tax_configuration_id = TVA_7` alongside
 * `tax_rate = 19.00`, and every sale over-charged VAT.
 *
 * The contract pinned here: WHEN A CONFIGURATION IS GIVEN, IT WINS — over a
 * client-supplied `tax_rate`, over the category default, over the company
 * default. When no configuration is given, the previous fallback ladder is
 * unchanged.
 */
final class ProductTaxRateDerivationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        (new CountriesSeeder)->run();

        $this->tenant = Tenant::create([
            'name' => 'N1 VAT Derivation Tenant',
            'slug' => 'n1-vat-derivation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Tunisia: default VAT 19 %, with 13 % and 7 % reduced bands.
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'N1 User',
            'email' => 'n1-vat@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->seed(TunisiaTaxConfigurationSeeder::class);
    }

    // -------------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------------

    public function test_create_with_a_seven_percent_configuration_persists_tax_rate_seven(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Sérum physiologique 5ml x20',
                'sku' => 'SERU-PHY20',
                'sale_price' => '6.200',
                'default_tax_configuration_id' => $this->config('TVA_7')->id,
            ]);

        $response->assertCreated();

        $product = Product::query()->where('sku', 'SERU-PHY20')->firstOrFail();

        $this->assertSame(
            '7.00',
            (string) $product->tax_rate,
            'tax_rate must be derived from the chosen tax configuration, not the company default.',
        );
    }

    public function test_create_ignores_a_client_supplied_tax_rate_when_a_configuration_is_chosen(): void
    {
        // The backend is the source of truth (brief §1). A client that both
        // picks TVA 7 % and echoes a stale 19.00 must still get 7.00 stored —
        // the frontend's own product payload omits tax_rate for this reason.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Client echoes a stale rate',
                'sku' => 'N1-ECHO-001',
                'tax_rate' => '19.00',
                'default_tax_configuration_id' => $this->config('TVA_7')->id,
            ]);

        $response->assertCreated();

        $this->assertSame(
            '7.00',
            (string) Product::query()->where('sku', 'N1-ECHO-001')->firstOrFail()->tax_rate,
        );
    }

    public function test_create_without_a_configuration_still_falls_back_to_the_company_default(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'No configuration chosen',
                'sku' => 'N1-FALLBACK-001',
            ]);

        $response->assertCreated();

        $this->assertSame(
            '19.00',
            (string) Product::query()->where('sku', 'N1-FALLBACK-001')->firstOrFail()->tax_rate,
            'With no configuration the category/company fallback ladder must be untouched.',
        );
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    public function test_update_switching_to_a_thirteen_percent_configuration_rewrites_tax_rate(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '19.00',
            'default_tax_configuration_id' => $this->config('TVA_19')->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'default_tax_configuration_id' => $this->config('TVA_13')->id,
            ]);

        $response->assertOk();

        $this->assertSame('13.00', (string) $product->fresh()?->tax_rate);
    }

    public function test_update_heals_a_product_whose_tax_rate_drifted_from_its_configuration(): void
    {
        // The exact N-1 shape found on the campaign tenant: the configuration
        // is right, the denormalised rate is the company default. Re-saving
        // the same configuration must repair it rather than no-op.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '19.00',
            'default_tax_configuration_id' => $this->config('TVA_7')->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'default_tax_configuration_id' => $this->config('TVA_7')->id,
            ]);

        $response->assertOk();

        $this->assertSame('7.00', (string) $product->fresh()?->tax_rate);
    }

    public function test_update_with_no_tax_fields_leaves_tax_rate_unchanged(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '7.00',
            'default_tax_configuration_id' => $this->config('TVA_7')->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'name' => 'Renamed, nothing fiscal touched',
            ]);

        $response->assertOk();

        $this->assertSame('7.00', (string) $product->fresh()?->tax_rate);
    }

    public function test_update_clearing_the_configuration_without_a_rate_leaves_tax_rate_unchanged(): void
    {
        // Detaching the configuration is not a statement about the rate. The
        // last derived number stays until someone states a new one — inventing
        // a company default here would silently re-price the product.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '7.00',
            'default_tax_configuration_id' => $this->config('TVA_7')->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'default_tax_configuration_id' => null,
            ]);

        $response->assertOk();

        $fresh = $product->fresh();
        $this->assertNull($fresh?->default_tax_configuration_id);
        $this->assertSame('7.00', (string) $fresh?->tax_rate);
    }

    public function test_update_honours_an_explicit_tax_rate_when_no_configuration_is_attached(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '19.00',
            'default_tax_configuration_id' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'tax_rate' => '7.00',
            ]);

        $response->assertOk();

        $this->assertSame('7.00', (string) $product->fresh()?->tax_rate);
    }

    // -------------------------------------------------------------------------
    // the refusal (gate r1 finding 5)
    // -------------------------------------------------------------------------

    public function test_create_refuses_a_configuration_that_states_no_line_item_percentage(): void
    {
        // This is a real API CONTRACT CHANGE and it is pinned deliberately.
        // Before N-1 such a payload saved fine (the rate silently came from the
        // company default); now it is refused, because deriving a VAT rate from
        // a fixed-amount stamp duty is not possible and inventing one is how the
        // wrong number got sealed in the first place. Unreachable from the UI —
        // TaxConfigurationSelect filters out non-LINE_ITEMS configurations — but
        // reachable from the API, imports and integrations, which is precisely
        // why it needs a test rather than an accident.
        $stamp = TaxConfiguration::query()
            ->where('country_code', 'TN')
            ->where('applies_to', 'DOCUMENT_TOTAL')
            ->firstOrFail();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Attached to a stamp duty',
                'sku' => 'N1-STAMP-001',
                'sale_price' => '10.000',
                'default_tax_configuration_id' => $stamp->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.default_tax_configuration_id.0', fn (mixed $message): bool => is_string($message) && $message !== '');

        $this->assertNull(
            Product::query()->where('sku', 'N1-STAMP-001')->first(),
            'The refusal must be a refusal — no product written with an unjustified rate.',
        );
    }

    private function config(string $code): TaxConfiguration
    {
        return TaxConfiguration::query()
            ->where('country_code', 'TN')
            ->where('code', $code)
            ->firstOrFail();
    }
}
