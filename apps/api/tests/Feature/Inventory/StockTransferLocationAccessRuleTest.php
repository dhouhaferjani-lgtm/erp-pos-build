<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Presentation\Requests\StoreStockTransferRequest;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockTransferLocationAccessRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $source;

    private Location $destination;

    private Product $product;

    private User $restricted;

    private User $unrestricted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Transfer Rule Tenant',
            'slug' => 'transfer-rule-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer Rule Company',
            'legal_name' => 'Transfer Rule Company LLC',
            'tax_id' => 'TAX-RULE',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->source = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SRC-01',
            'name' => 'Source',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->destination = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DST-01',
            'name' => 'Destination',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->restricted = $this->makeUser('rule-restricted@e.com', [$this->destination->id]);
        $this->unrestricted = $this->makeUser('rule-unrestricted@e.com', null);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RULE-WIDGET',
            'name' => 'Rule Widget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        app(LocationContext::class)->clear();
    }

    public function test_restricted_caller_gets_validation_error_for_disallowed_source(): void
    {
        $this->actingAs($this->restricted);

        $request = new StoreStockTransferRequest(
            app(CompanyContext::class),
            app(LocationContext::class),
        );
        $payload = $this->payload($this->source, $this->destination);
        $validator = Validator::make($payload, $request->rules());

        self::assertTrue($validator->fails());
        self::assertSame(
            ['You do not have permission to access this location.'],
            $validator->errors()->get('source_location_id'),
        );

        $validationException = ValidationException::withMessages($validator->errors()->toArray());
        self::assertSame(422, $validationException->status);
    }

    public function test_restricted_inaccessible_source_with_missing_destination_stays_validation_error(): void
    {
        $payload = $this->payload($this->source, $this->destination);
        unset($payload['destination_location_id']);

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_null_membership_caller_can_create_from_any_source(): void
    {
        $this->seedStock($this->source);

        $this->actingAs($this->unrestricted)
            ->postJson('/api/v1/stock-transfers', $this->payload($this->source, $this->destination))
            ->assertStatus(201);
    }

    public function test_restricted_caller_can_send_to_destination_outside_subset(): void
    {
        $this->seedStock($this->destination);

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', $this->payload($this->destination, $this->source))
            ->assertStatus(201);
    }

    public function test_valid_location_access_rule_does_not_use_service_locator(): void
    {
        $source = file_get_contents(base_path('app/Rules/ValidLocationAccess.php'));

        self::assertIsString($source);
        self::assertStringNotContainsString('app(', $source);
    }

    /**
     * @return array{source_location_id: string, destination_location_id: string, lines: array<int, array{product_id: string, quantity: string}>}
     */
    private function payload(Location $source, Location $destination): array
    {
        return [
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '2'],
            ],
        ];
    }

    /**
     * @param  array<int, string>|null  $allowed
     */
    private function makeUser(string $email, ?array $allowed): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo([
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.complete',
            'inventory.transfers.cancel',
            'products.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => $allowed,
            'status' => 'active',
        ]);

        return $user;
    }

    private function seedStock(Location $location): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $location->id,
            quantity: '5',
            reference: 'RULE-SEED',
            userId: $this->unrestricted->id,
            expectedCompanyId: $this->company->id,
        );
    }
}
