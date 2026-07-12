<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartnerBankAccountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private string $bankId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Bank Account Tenant',
            'slug' => 'partner-bank-account-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bank Account Company',
            'legal_name' => 'Bank Account Company SARL',
            'tax_id' => 'BANK-ACCOUNT-TAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bank Account User',
            'email' => 'partner-banks@example.test',
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

        $this->bankId = (string) Str::uuid();
        DB::table('banks')->insert([
            'id' => $this->bankId,
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'name' => 'Amen Bank',
            'short_name' => 'AB',
            'bic' => 'CFCTTNTT',
            'rib_bank_code' => '07',
            'is_active' => true,
            'is_custom' => false,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_creates_partner_accounts_and_records_warn_mode_validity(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/partners', [
            'name' => 'Partner With Banks',
            'type' => 'supplier',
            'customer_category' => 'business',
            'bank_accounts' => [
                [
                    'label' => 'Main TND',
                    'bank_id' => $this->bankId,
                    'bank_name' => 'Amen Bank',
                    'rib' => '07040005810111129653',
                    'iban' => '',
                    'bic' => 'CFCTTNTT',
                    'currency' => 'TND',
                    'is_primary' => true,
                ],
                [
                    'label' => 'Legacy account',
                    'bank_id' => null,
                    'bank_name' => 'Legacy Bank',
                    'rib' => 'not-a-rib',
                    'iban' => 'not-an-iban',
                    'bic' => 'bad',
                    'currency' => 'EUR',
                    'is_primary' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data.bank_accounts')
            ->assertJsonPath('data.bank_accounts.0.iban', 'TN5907040005810111129653')
            ->assertJsonPath('data.bank_accounts.0.rib_validation.valid', true)
            ->assertJsonPath('data.bank_accounts.0.iban_validation.valid', true)
            ->assertJsonPath('data.bank_accounts.0.bic_valid', true)
            ->assertJsonPath('data.bank_accounts.0.is_primary', true)
            ->assertJsonPath('data.bank_accounts.1.rib_validation.valid', false)
            ->assertJsonPath('data.bank_accounts.1.iban_validation.valid', false)
            ->assertJsonPath('data.bank_accounts.1.bic_valid', false)
            ->assertJsonPath('data.bank_accounts.1.is_primary', false)
            ->assertJsonPath('meta.bank_account_validation.1.rib.valid', false);

        $partnerId = (string) $response->json('data.id');
        $this->assertDatabaseCount('partner_bank_accounts', 2);
        $this->assertSame(1, DB::table('partner_bank_accounts')
            ->where('partner_id', $partnerId)
            ->where('is_primary', true)
            ->count());
        $this->assertDatabaseHas('partner_bank_accounts', [
            'partner_id' => $partnerId,
            'bank_id' => $this->bankId,
            'created_by' => $this->user->id,
            'iban' => 'TN5907040005810111129653',
        ]);
    }

    public function test_invalid_identifiers_never_reject_partner_save(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/partners', [
            'name' => 'Legacy Supplier',
            'type' => 'supplier',
            'bank_accounts' => [[
                'label' => null,
                'bank_id' => null,
                'bank_name' => 'Unlisted',
                'rib' => '123',
                'iban' => 'TN00BROKEN',
                'bic' => 'X',
                'currency' => 'TND',
                'is_primary' => false,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.bank_accounts.0.rib_validation.valid', false)
            ->assertJsonPath('data.bank_accounts.0.iban_validation.valid', false)
            ->assertJsonPath('data.bank_accounts.0.bic_valid', false)
            ->assertJsonPath('data.bank_accounts.0.is_primary', true);
    }

    public function test_updates_accounts_as_a_nested_collection_and_keeps_one_primary(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Existing Supplier',
            'type' => 'supplier',
        ]);
        $keepId = (string) Str::uuid();
        $removeId = (string) Str::uuid();
        DB::table('partner_bank_accounts')->insert([
            [
                'id' => $keepId,
                'tenant_id' => $this->tenant->id,
                'partner_id' => $partner->id,
                'label' => 'Old main',
                'bank_name' => 'Old Bank',
                'currency' => 'TND',
                'is_primary' => true,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $removeId,
                'tenant_id' => $this->tenant->id,
                'partner_id' => $partner->id,
                'label' => 'Remove me',
                'bank_name' => 'Old Bank',
                'currency' => 'TND',
                'is_primary' => false,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/partners/{$partner->id}", [
            'bank_accounts' => [
                [
                    'id' => $keepId,
                    'label' => 'Updated secondary',
                    'bank_name' => 'Amen Bank',
                    'bank_id' => $this->bankId,
                    'rib' => '07040005810111129653',
                    'iban' => 'TN5907040005810111129653',
                    'bic' => 'CFCTTNTT',
                    'currency' => 'TND',
                    'is_primary' => false,
                ],
                [
                    'label' => 'New primary',
                    'bank_name' => 'Unlisted',
                    'bank_id' => null,
                    'rib' => null,
                    'iban' => null,
                    'bic' => null,
                    'currency' => 'EUR',
                    'is_primary' => true,
                ],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data.bank_accounts')
            ->assertJsonPath('data.bank_accounts.0.is_primary', true)
            ->assertJsonPath('data.bank_accounts.1.id', $keepId)
            ->assertJsonPath('data.bank_accounts.1.is_primary', false);

        $this->assertDatabaseMissing('partner_bank_accounts', ['id' => $removeId]);
        $this->assertDatabaseHas('partner_bank_accounts', ['id' => $keepId, 'label' => 'Updated secondary']);
        $this->assertSame(1, DB::table('partner_bank_accounts')
            ->where('partner_id', $partner->id)
            ->where('is_primary', true)
            ->count());
    }

    public function test_partner_relation_exposes_bank_accounts(): void
    {
        self::assertSame('partner_bank_accounts', (new Partner)->bankAccounts()->getRelated()->getTable());
    }

    public function test_tenant_migration_does_not_reference_the_central_tenants_table(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/tenant/2026_07_12_120000_create_partner_bank_accounts_table.php'
        ));

        self::assertIsString($migration);
        self::assertStringNotContainsString("->on('tenants')", $migration);
    }
}
