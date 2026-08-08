<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * F-7 — GET /api/v1/pos/products/{productId}/batches.
 *
 * Pins the rule-19 contract on the FEFO suggestion endpoint:
 *  - `quantity` is validated with a scale-4 regex ceiling (not bare `numeric`),
 *    so scale-5 input, scientific notation and non-numerics are refused;
 *  - every quantity in the response body is a canonical scale-4 numeric
 *    STRING, never a JSON number.
 *
 * Auth harness mirrors GetExpiredBatchesRouteTest (Pharmacy vertical + admin).
 * Validation errors use the custom `error.errors` envelope.
 */
class PosAvailableBatchesPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'FEFO Precision Tenant',
            'slug' => 'fefo-precision',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FEFO Precision Company',
            'legal_name' => 'FEFO Precision Company LLC',
            'tax_id' => 'TAXFEFOPRECISION',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FEFO Precision User',
            'email' => 'user@fefo-precision.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Validation — rule-19 regex ceiling on `quantity`
    // -------------------------------------------------------------------------

    public function test_accepts_scale_four_quantity(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '2.0000');

        $this->requestBatches('1.2505')->assertOk();
    }

    public function test_rejects_scale_five_quantity(): void
    {
        $this->requestBatches('1.25055')
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity', 'error.errors');
    }

    public function test_rejects_scientific_notation_quantity(): void
    {
        $this->requestBatches('1e2')
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity', 'error.errors');
    }

    public function test_rejects_non_numeric_quantity(): void
    {
        $this->requestBatches('abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity', 'error.errors');
    }

    public function test_rejects_negative_quantity(): void
    {
        $this->requestBatches('-1.0000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity', 'error.errors');
    }

    public function test_rejects_missing_quantity(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson(sprintf(
                '/api/v1/pos/products/%s/batches?location_id=%s',
                $this->product->id,
                $this->location->id,
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity', 'error.errors');
    }

    // -------------------------------------------------------------------------
    // Wire format — quantities are strings, values are exact
    // -------------------------------------------------------------------------

    public function test_response_emits_quantities_as_scale_four_strings(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '0.7000');
        $this->createBatchWithStock(expiryDays: 20, quantity: '0.4000');

        $response = $this->requestBatches('1.1000')->assertOk();

        // Exact decomposition — the float pipeline reported a 1.11e-16 shortfall here.
        $response->assertJsonPath('data.fully_fulfilled', true)
            ->assertJsonPath('data.shortfall', '0.0000')
            ->assertJsonPath('data.total_quantity_suggested', '1.1000')
            ->assertJsonPath('data.suggestions.0.quantity', '0.7000')
            ->assertJsonPath('data.suggestions.1.quantity', '0.4000');

        // Types on the wire, read from the raw body (assertJsonPath is type-strict
        // already, but this proves the JSON encoding itself, not the decode).
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('"shortfall":"0.0000"', $body);
        $this->assertStringContainsString('"total_quantity_suggested":"1.1000"', $body);
        $this->assertStringContainsString('"quantity":"0.7000"', $body);
    }

    public function test_shortfall_is_a_string_when_stock_is_insufficient(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '0.3000');

        $response = $this->requestBatches('1.2505')->assertOk();

        $response->assertJsonPath('data.fully_fulfilled', false)
            ->assertJsonPath('data.shortfall', '0.9505')
            ->assertJsonPath('data.total_quantity_suggested', '0.3000');
    }

    public function test_empty_result_emits_string_zero_total(): void
    {
        $response = $this->requestBatches('1.2500')->assertOk();

        $response->assertJsonPath('data.suggestions', [])
            ->assertJsonPath('data.fully_fulfilled', false)
            ->assertJsonPath('data.shortfall', '1.2500')
            ->assertJsonPath('data.total_quantity_suggested', '0.0000');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requestBatches(string $quantity): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson(sprintf(
                '/api/v1/pos/products/%s/batches?location_id=%s&quantity=%s',
                $this->product->id,
                $this->location->id,
                rawurlencode($quantity),
            ));
    }

    /** @param  numeric-string  $quantity */
    private function createBatchWithStock(int $expiryDays, string $quantity): Batch
    {
        static $counter = 0;
        $counter++;

        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'FEFO-PRECISION-'.$counter,
            'expiry_date' => now()->addDays($expiryDays),
            'is_active' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }
}
