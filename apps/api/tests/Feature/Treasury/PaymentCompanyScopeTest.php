<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Company-scope contract for the payments list (go-live audit 2026-06-14,
 * Finding #4 — broken object-level authorization).
 *
 * PaymentController::index scoped only by tenant_id while show()/store()
 * scope by tenant_id AND company_id (api.treasury.075). In a multi-company
 * tenant that let a user bound to company A read company B's entire payment
 * ledger.
 */
final class PaymentCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_index_does_not_leak_other_company_payments(): void
    {
        $tenant = Tenant::create([
            'name' => 'Scope Tenant',
            'slug' => 'scope-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $companyA = $this->makeCompany($tenant, 'Company A', 'TAX-A');
        $companyB = $this->makeCompany($tenant, 'Company B', 'TAX-B');

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Company A User',
            'email' => 'a-user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $companyA->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $paymentA = $this->makePayment($tenant->id, $companyA->id);
        $paymentB = $this->makePayment($tenant->id, $companyB->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Company-Id' => $companyA->id])
            ->getJson('/api/v1/payments')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($paymentA->id, $ids, 'The user must see their own company A payment.');
        $this->assertNotContains(
            $paymentB->id,
            $ids,
            'A company-A user must NOT see company B payments in the list.',
        );
    }

    private function makeCompany(Tenant $tenant, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'legal_name' => $name.' SARL',
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makePayment(string $tenantId, string $companyId): Payment
    {
        return Payment::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'amount' => '100.000',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);
    }
}
