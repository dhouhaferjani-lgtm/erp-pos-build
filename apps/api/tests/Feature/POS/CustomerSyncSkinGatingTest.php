<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\SkinType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CustomerSyncSkinGatingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Parapharmacy tenant: skin fields must appear in the sync response row.
     */
    public function test_skin_fields_are_included_for_parapharmacy_vertical(): void
    {
        [$company, $cashier] = $this->scaffoldTenantWithCashier(Vertical::Parapharmacy);

        Partner::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'is_active' => true,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'skin_type' => SkinType::Dry,
            'skin_advice_note' => 'Apply rich moisturiser twice daily.',
        ]);

        Sanctum::actingAs($cashier);

        $response = $this
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/pos/customers/sync');

        $response->assertOk();
        $row = $response->json('data.customers.0');

        $this->assertArrayHasKey('skin_type', $row, 'skin_type must be present for parapharmacy tenants');
        $this->assertArrayHasKey('skin_advice_note', $row, 'skin_advice_note must be present for parapharmacy tenants');
        $this->assertSame(SkinType::Dry->value, $row['skin_type']);
        $this->assertSame('Apply rich moisturiser twice daily.', $row['skin_advice_note']);
    }

    /**
     * Non-parapharmacy tenant: skin fields must be entirely absent (not null) from the sync response row.
     */
    public function test_skin_fields_are_absent_for_non_parapharmacy_vertical(): void
    {
        [$company, $cashier] = $this->scaffoldTenantWithCashier(Vertical::Retail);

        Partner::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'is_active' => true,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'skin_type' => SkinType::Oily,
            'skin_advice_note' => 'Should not be leaked to non-parapharmacy.',
        ]);

        Sanctum::actingAs($cashier);

        $response = $this
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/pos/customers/sync');

        $response->assertOk();
        $row = $response->json('data.customers.0');

        $this->assertArrayNotHasKey('skin_type', $row, 'skin_type must NOT appear for non-parapharmacy tenants');
        $this->assertArrayNotHasKey('skin_advice_note', $row, 'skin_advice_note must NOT appear for non-parapharmacy tenants');
    }

    /**
     * Parapharmacy tenant: skin fields are included even when they are null.
     */
    public function test_skin_fields_are_included_with_null_values_for_parapharmacy_when_unset(): void
    {
        [$company, $cashier] = $this->scaffoldTenantWithCashier(Vertical::Parapharmacy);

        Partner::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'is_active' => true,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'skin_type' => null,
            'skin_advice_note' => null,
        ]);

        Sanctum::actingAs($cashier);

        $response = $this
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/pos/customers/sync');

        $response->assertOk();
        $row = $response->json('data.customers.0');

        $this->assertArrayHasKey('skin_type', $row, 'skin_type key must be present for parapharmacy (even if null)');
        $this->assertArrayHasKey('skin_advice_note', $row, 'skin_advice_note key must be present for parapharmacy (even if null)');
        $this->assertNull($row['skin_type']);
        $this->assertNull($row['skin_advice_note']);
    }

    /**
     * Scaffold a tenant of the given vertical with a company and a cashier user.
     *
     * @return array{0: Company, 1: User}
     */
    private function scaffoldTenantWithCashier(Vertical $vertical): array
    {
        $tenant = Tenant::factory()->create(['vertical' => $vertical]);
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $cashier->givePermissionTo('pos.operate_terminal');

        return [$company, $cashier];
    }
}
