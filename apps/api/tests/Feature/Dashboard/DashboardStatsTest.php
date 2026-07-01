<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_received_counts_completed_current_month_incoming_payments_by_payment_date(): void
    {
        $tenant = Tenant::create([
            'name' => 'PharmaBio Tunisie',
            'slug' => 'pharmabio-tunisie',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PharmaBio Tunisie',
            'legal_name' => 'PharmaBio Tunisie SARL',
            'tax_id' => 'TN1234567',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dashboard User',
            'email' => 'dashboard@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $partner = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Clinique El Manar',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $method = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK',
            'name' => 'Bank Transfer',
            'is_physical' => false,
            'is_active' => true,
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'amount' => '15600.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'amount' => '2000.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::SupplierPayment,
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'amount' => '4000.000',
            'currency' => 'TND',
            'payment_date' => now()->subMonth()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.received', '15600.000');
    }
}
