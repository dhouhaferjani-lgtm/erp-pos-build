<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PosCustomerSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->cashier->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($this->cashier);
    }

    public function test_pos_customer_sync_returns_only_current_tenant_company_customers(): void
    {
        $customer = $this->createCustomer([
            'name' => 'Current Company Customer',
            'type' => PartnerType::Customer,
        ]);
        $both = $this->createCustomer([
            'name' => 'Current Company Both',
            'type' => PartnerType::Both,
        ]);

        $this->createCustomer([
            'name' => 'Current Company Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->createCustomer([
            'name' => 'Other Company Customer',
            'company_id' => $otherCompany->id,
            'type' => PartnerType::Customer,
        ]);

        $otherTenant = Tenant::factory()->create();
        $otherTenantCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $this->createCustomer([
            'name' => 'Other Tenant Customer',
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherTenantCompany->id,
            'type' => PartnerType::Customer,
        ]);

        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customers/sync');

        $response->assertOk();
        $rows = $response->json('data.customers');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$customer->id, $both->id],
            array_column($rows, 'id'),
        );
        foreach ($rows as $row) {
            $this->assertSame($this->tenant->id, $row['tenant_id']);
            $this->assertSame($this->company->id, $row['company_id']);
        }
    }

    public function test_pos_customer_sync_filters_by_updated_since_cursor(): void
    {
        $old = $this->createCustomer(['name' => 'Old Customer']);
        Partner::query()->whereKey($old->id)->update([
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        $fresh = $this->createCustomer(['name' => 'Fresh Customer']);
        Partner::query()->whereKey($fresh->id)->update([
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        $cursor = urlencode(Carbon::now()->subDays(2)->toIso8601String());
        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/pos/customers/sync?updated_since={$cursor}");

        $response->assertOk();
        $rows = $response->json('data.customers');

        $this->assertCount(1, $rows);
        $this->assertSame($fresh->id, $rows[0]['id']);
    }

    public function test_pos_customer_sync_includes_balance_snapshot_fields(): void
    {
        $balanceAt = Carbon::parse('2026-05-21T08:30:00Z');
        $customer = $this->createCustomer([
            'name' => 'Balance Customer',
            'phone' => '+216 20 111 222',
            'email' => 'balance@example.test',
            'vat_number' => 'TN1234567A',
            'customer_category' => CustomerCategory::Individual,
            'receivable_balance' => '125.5000',
            'credit_balance' => '25.2500',
            'balance_updated_at' => $balanceAt,
            'is_active' => false,
        ]);

        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customers/sync');

        $response->assertOk();
        $row = $response->json('data.customers.0');

        $this->assertSame($customer->id, $row['id']);
        $this->assertSame('Balance Customer', $row['name']);
        $this->assertSame('+216 20 111 222', $row['phone']);
        $this->assertSame('balance@example.test', $row['email']);
        $this->assertSame('TN1234567A', $row['tax_number']);
        $this->assertSame('individual', $row['customer_category']);
        $this->assertSame('125.5000', $row['receivable_balance']);
        $this->assertSame('25.2500', $row['credit_balance']);
        $this->assertSame($balanceAt->toISOString(), $row['balance_updated_at']);
        $this->assertSame(0, $row['is_active']);
        $this->assertArrayHasKey('sync_version', $row);
        $this->assertArrayHasKey('updated_at', $row);
        $this->assertArrayHasKey('synced_at', $row);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCustomer(array $overrides = []): Partner
    {
        return Partner::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'is_active' => true,
            'receivable_balance' => '0.0000',
            'credit_balance' => '0.0000',
        ], $overrides));
    }
}
