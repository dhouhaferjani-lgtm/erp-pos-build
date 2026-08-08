<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Presentation\Requests\StoreStockAdjustmentRequest;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Ingress precision regression coverage for the stock-quantity write surface.
 *
 * Phase 4.3 — validates that the decimal-ceiling regex blocks inputs whose scale
 * exceeds decimal(15,4) while allowing up to 4 dp through.
 *
 * RELOCATED by DPA V7 / T11. The four raw `POST /stock-movements/*` endpoints
 * this file used to cover are deleted, so the same boundary is now asserted on
 * the surviving ingresses:
 *
 *   receive / issue / adjust -> POST /stock-adjustments (delta_quantity AND
 *                               observed_before, both SIGNED)
 *   supplier-sourced receive -> POST /goods-receipts/standalone (lines.*.qty)
 *
 * The relocation ADDS coverage the old file could not have: every replaced
 * regex was UNSIGNED, so the negative half of the new contract — which is the
 * half a copy-paste silently drops — had no test at all.
 *
 * NOT relocated onto `POST /stock-transfers`: `StoreStockTransferRequest`'s
 * `lines.*.quantity` rule is `['required','numeric','min:0.0001']` with NO
 * regex ceiling, so that ingress accepts 5 dp today. That is a PRE-EXISTING
 * rule-19 gap in the transfers lane, discovered by this relocation and recorded
 * in the V7 task report — deliberately not fixed here, because silently
 * tightening a shipped contract is outside this lane.
 */
final class IngressPrecisionTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Precision Inventory Tenant',
            'slug' => 'prec-inv-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Precision Inventory Co',
            'legal_name' => 'Precision Inventory Co LLC',
            'tax_id' => 'PRI123',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Precision Inventory User',
            'email' => 'prec-inv@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
            'goods-receipt.create-standalone',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-PREC',
            'name' => 'Precision Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PREC-001',
            'name' => 'Precision Part',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /stock-adjustments — delta_quantity is SIGNED and capped at 4 dp
    // -------------------------------------------------------------------------

    public function test_adjustment_accepts_a_delta_with_four_decimal_places(): void
    {
        $this->seedStock('50.0000');

        $response = $this->postAdjustment('adjustment_negative', '-3.1234', '50.0000');

        $response->assertStatus(201);
    }

    public function test_adjustment_accepts_an_integer_delta(): void
    {
        $this->seedStock('50.0000');

        $this->postAdjustment('adjustment_positive', '5', '50.0000')->assertStatus(201);
    }

    public function test_adjustment_accepts_a_negative_delta_with_four_decimal_places(): void
    {
        // The half the replaced UNSIGNED regexes could not express.
        $this->seedStock('50.0000');

        $this->postAdjustment('adjustment_negative', '-12.1234', '50.0000')->assertStatus(201);
    }

    public function test_adjustment_rejects_a_delta_with_five_decimal_places(): void
    {
        $this->seedStock('50.0000');

        $response = $this->postAdjustment('adjustment_positive', '12.12345', '50.0000');

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.delta_quantity']);
    }

    public function test_adjustment_rejects_a_delta_with_eight_decimal_places(): void
    {
        $this->seedStock('50.0000');

        $response = $this->postAdjustment('adjustment_positive', '1.00000001', '50.0000');

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.delta_quantity']);
    }

    public function test_adjustment_rejects_a_negative_delta_with_five_decimal_places(): void
    {
        $this->seedStock('50.0000');

        $response = $this->postAdjustment('adjustment_negative', '-3.12345', '50.0000');

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.delta_quantity']);
    }

    public function test_adjustment_rejects_an_observed_before_with_five_decimal_places(): void
    {
        $this->seedStock('50.0000');

        $response = $this->postAdjustment('adjustment_positive', '1.0000', '50.12345');

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.observed_before']);
    }

    public function test_adjustment_accepts_a_negative_observed_before(): void
    {
        // stock_levels.quantity has no non-negative CHECK and POS oversell paths
        // drive it below zero, so the authoring snapshot must be signed too.
        $this->seedStock('50.0000');

        $this->postAdjustment('adjustment_positive', '1.0000', '-4.0000')->assertStatus(201);
    }

    public function test_adjustment_rejects_a_zero_delta(): void
    {
        // Semantically DIFFERENT from the deleted `new_quantity: 0` case, and the
        // difference is asserted rather than glossed: an absolute of zero was a
        // valid full write-off, whereas a zero DELTA is not a correction at all
        // (`not_in:0` plus the stock_adjustment_lines_delta_nonzero CHECK).
        $this->seedStock('10.0000');

        $response = $this->postAdjustment('adjustment_positive', '0', '10.0000');

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.delta_quantity']);
    }

    public function test_a_full_write_off_is_expressed_as_a_delta_equal_to_the_whole_on_hand(): void
    {
        // The replacement for test_adjust_accepts_zero_new_quantity_for_full_writeoff:
        // the same physical act, expressed as a delta, leaving exactly zero.
        $this->seedStock('10.0000');

        $this->postAdjustment('write_off', '-10.0000', '10.0000', postImmediately: true)
            ->assertStatus(201);

        $this->assertSame('0.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
    }

    // -------------------------------------------------------------------------
    // POST /goods-receipts/standalone — the supplier-sourced receive fork
    // -------------------------------------------------------------------------

    public function test_standalone_receipt_rejects_a_quantity_with_five_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-receipts/standalone', [
                'supplier_id' => (string) Str::uuid(),
                'location_id' => $this->warehouse->id,
                'idempotency_key' => 'PREC-SR-1',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'qty' => '12.12345',
                    'unit_price' => '5.000',
                ]],
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.qty']);
    }

    public function test_standalone_receipt_rejects_a_unit_price_beyond_the_currency_scale(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-receipts/standalone', [
                'supplier_id' => (string) Str::uuid(),
                'location_id' => $this->warehouse->id,
                'idempotency_key' => 'PREC-SR-2',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'qty' => '1.0000',
                    'unit_price' => '5.0001',
                ]],
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.unit_price']);
    }

    // -------------------------------------------------------------------------
    // Fast Validator::make boundary checks.
    //
    // NOTE: these MIRROR (do NOT bind to) the rules in
    // StockAdjustmentLineRules::forCompany(). The production binding is provided
    // by the HTTP 422 tests above; these are extra boundary-case coverage.
    // -------------------------------------------------------------------------

    public function test_the_signed_quantity_regex_accepts_and_rejects_at_the_boundary(): void
    {
        $rules = [
            'delta_quantity' => [
                'required',
                'numeric',
                'not_in:0',
                'regex:'.StoreStockAdjustmentRequest::SIGNED_QUANTITY_REGEX,
            ],
        ];

        $valid = ['1', '1.0', '1.12', '1.123', '1.1234', '999.9999', '-1', '-1.1234', '-999.9999'];
        foreach ($valid as $val) {
            $v = Validator::make(['delta_quantity' => $val], $rules);
            $this->assertFalse($v->fails(), "Expected valid: {$val}");
        }

        $invalid = ['1.12345', '0.000001', '1.000001', '-1.12345', '0', '0.0000'];
        foreach ($invalid as $val) {
            $v = Validator::make(['delta_quantity' => $val], $rules);
            $this->assertTrue($v->fails(), "Expected invalid: {$val}");
        }
    }

    public function test_the_regex_is_bound_to_the_production_constant_not_a_copy(): void
    {
        // The one place a copy-paste from this module's UNSIGNED quantity
        // regexes silently drops half the contract.
        $this->assertSame('/^-?\d+(\.\d{1,4})?$/', StoreStockAdjustmentRequest::SIGNED_QUANTITY_REGEX);
    }

    // ------------------------------------------------------------- fixtures

    private function postAdjustment(
        string $reason,
        string $delta,
        string $observedBefore,
        bool $postImmediately = false,
    ): TestResponse {
        return $this->actingAs($this->user)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => $postImmediately,
            'lines' => [[
                'product_id' => $this->product->id,
                'reason_code' => $reason,
                'delta_quantity' => $delta,
                'observed_before' => $observedBefore,
            ]],
        ]);
    }

    private function seedStock(string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }
}
