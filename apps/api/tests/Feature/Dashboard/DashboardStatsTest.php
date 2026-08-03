<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Company, 2: User, 3: Partner}
     */
    private function bootstrapTenantCompanyUserPartner(): array
    {
        $tenant = Tenant::create([
            'name' => 'PharmaBio Tunisie',
            'slug' => 'pharmabio-tunisie-'.Str::random(8),
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
            'email' => 'dashboard-'.Str::random(8).'@example.test',
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

        return [$tenant, $company, $user, $partner];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInvoice(Tenant $tenant, Company $company, Partner $partner, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(10),
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'balance_due' => '100.00',
            'currency' => 'TND',
        ], $overrides));
    }

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

    /**
     * Ruling 1: revenue = whereIn(status, [Posted, Paid]) — a paid invoice is
     * still revenue, not just a posted-and-unpaid one.
     */
    public function test_revenue_current_month_includes_both_posted_and_paid_invoices(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '50.000',
        ]);
        // Non-revenue statuses must not leak in.
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Draft,
            'total' => '999.000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.current', '150.000');
    }

    /**
     * Ruling 1: a refund's Paid -> Posted revert must NOT move revenue — both
     * statuses are already in the revenue base, so the same invoice total
     * counts identically before and after the revert.
     */
    public function test_refund_reverting_paid_to_posted_does_not_change_revenue(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $invoice = $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '100.000',
        ]);

        $beforeRefund = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');
        $beforeRefund->assertOk()
            ->assertJsonPath('data.revenue.current', '100.000');

        // Simulate the treasury refund chain reverting Paid -> Posted.
        $invoice->update(['status' => DocumentStatus::Posted]);

        $afterRefund = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');
        $afterRefund->assertOk()
            ->assertJsonPath('data.revenue.current', '100.000');
    }

    /**
     * Ruling refinement: overdue stays Posted-only. A PAID invoice past its
     * due date is settled, not overdue, so it must never be counted.
     */
    public function test_overdue_counts_only_posted_invoices_not_paid(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'due_date' => now()->subDays(5),
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'due_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.invoices.overdue', 1);
    }

    /**
     * Ruling refinement: a refund making Paid -> Posted CAN legitimately
     * re-enter the overdue bucket if the invoice is past its due date — this
     * is correct business reality (the invoice really is unpaid and overdue
     * again), asserted deliberately rather than treated as a regression.
     */
    public function test_refund_revert_can_reenter_overdue_bucket(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $invoice = $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'due_date' => now()->subDays(5),
        ]);

        $beforeRefund = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');
        $beforeRefund->assertOk()
            ->assertJsonPath('data.invoices.overdue', 0);

        $invoice->update(['status' => DocumentStatus::Posted]);

        $afterRefund = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');
        $afterRefund->assertOk()
            ->assertJsonPath('data.invoices.overdue', 1);
    }

    /**
     * Ruling 2: paymentsPending is derived from the same Posted∪Paid base as
     * revenue, minus paymentsReceived — consistent with ruling 1.
     */
    public function test_payments_pending_derived_from_posted_and_paid_invoice_base(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '50.000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.pending', '150.000');
    }

    /**
     * Ruling 3 (precision rule 19): revenue.current/previous/change travel as
     * bc-based decimal strings, never PHP floats/ints on the wire.
     */
    public function test_revenue_values_are_scale_3_strings_not_floats(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk();

        $this->assertIsString($response->json('data.revenue.current'));
        $this->assertIsString($response->json('data.revenue.previous'));
        $this->assertIsString($response->json('data.revenue.change'));
        $this->assertSame('100.000', $response->json('data.revenue.current'));
        $this->assertSame('0.000', $response->json('data.revenue.previous'));
    }

    /**
     * Ruling 3: change stays a percentage, represented as a 2dp string.
     */
    public function test_revenue_change_is_a_two_decimal_percent_string(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '125.000',
            'document_date' => now(),
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '100.000',
            'document_date' => now()->subMonth(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.change', '25.00');
    }
}
