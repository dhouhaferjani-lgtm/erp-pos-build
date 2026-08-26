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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // M1 (2026-08-03 gate): several tests freeze the clock to pin a specific
        // month-boundary window — never leak a frozen "now" into later tests.
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPayment(Tenant $tenant, Company $company, Partner $partner, PaymentMethod $method, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
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
     * AMENDED ruling 3 (2026-08-03 gate H1): pending = SUM(balance_due) over the
     * Posted∪Paid base — NOT paymentsReceived-derived (that subtracted a
     * month-scoped paymentsReceived from an all-time invoice base, which is
     * arithmetically invalid and roughly doubled the figure). A still-open
     * Posted invoice contributes its full balance_due; a fully-settled Paid
     * invoice (balance_due already driven to 0 by treasury allocations)
     * contributes nothing, even though its `total` is still in the revenue
     * base.
     */
    public function test_payments_pending_is_sum_of_balance_due_over_posted_and_paid_base(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
            'balance_due' => '100.000',
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '50.000',
            'balance_due' => '0.000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.pending', '100.000');
    }

    /**
     * H1: a fully-settled invoice from a PRIOR month contributes exactly 0 to
     * pending, regardless of when it was created — pending is deliberately
     * all-time (not month-scoped), unlike revenue. This is the case that
     * would have silently regressed under the old
     * "all-time invoiced - this-month-receipts" formula (the settled
     * invoice's lifetime total would have inflated the minuend with nothing
     * to offset it).
     */
    public function test_payments_pending_excludes_settled_prior_month_paid_invoice(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '250.000',
            'balance_due' => '0.000',
            'document_date' => now()->subMonthNoOverflow(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.pending', '0.000');
    }

    /**
     * Ruling 3 (precision rule 19): revenue.current/previous travel as
     * bc-based decimal strings, never PHP floats/ints on the wire. With no
     * previous-month invoice, previous is the string "0.000" and — per the
     * AMENDED M2 ruling below — change is null, not a string.
     */
    public function test_revenue_current_and_previous_are_scale_3_strings_not_floats(): void
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
        $this->assertSame('100.000', $response->json('data.revenue.current'));
        $this->assertSame('0.000', $response->json('data.revenue.previous'));
        $this->assertNull($response->json('data.revenue.change'));
    }

    /**
     * Ruling 3: change stays a percentage, represented as a 2dp string when
     * there IS a meaningful previous-period baseline. Clock frozen mid-month
     * (M1, 2026-08-03 gate) so this test is not date-dependent — a plain
     * `now()->subMonth()` around a short-month boundary would otherwise
     * collapse the previous-month window onto the current month.
     */
    public function test_revenue_change_is_a_two_decimal_percent_string(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '125.000',
            'document_date' => Carbon::now(),
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '100.000',
            'document_date' => Carbon::now()->subMonthNoOverflow(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.change', '25.00');
    }

    /**
     * M1 (2026-08-03 gate) — CI landmine / real month-end KPI defect: on a day
     * whose day-of-month doesn't exist in the previous month (May 31 -> "Apr
     * 31"), a plain subMonth() overflows and the previous-month window
     * collapses onto the current month, silently zeroing revenue.previous
     * and collapsing revenue.change to "0.00" even though a real
     * previous-month invoice exists.
     */
    public function test_revenue_previous_month_window_does_not_overflow_at_month_end_boundary(): void
    {
        Carbon::setTestNow('2026-05-31 12:00:00');

        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '125.000',
            'document_date' => Carbon::now(),
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Paid,
            'total' => '100.000',
            'document_date' => Carbon::parse('2026-04-15'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.current', '125.000')
            ->assertJsonPath('data.revenue.previous', '100.000')
            ->assertJsonPath('data.revenue.change', '25.00');
    }

    /**
     * AMENDED ruling (2026-08-03 gate M2): revenue.change is null — not
     * "0.00" — when there is no previous-period invoice at all, mirroring
     * ExpenseAnalyticsService::generate()'s `!== 0` guard/null convention so
     * the web tile can suppress a meaningless "0%" badge (e.g. a brand-new
     * tenant's first month).
     */
    public function test_revenue_change_is_null_when_previous_revenue_is_zero(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
            'document_date' => now(),
        ]);
        // No previous-month invoice at all.

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.change', null);
    }

    /**
     * AMENDED ruling (2026-08-03 gate M2): a negative previous-period revenue
     * (data anomaly / rebate scenario) also yields null — the old `> 0` guard
     * silently reported "0.00" (no change) instead, which is a wrong answer,
     * not merely an absent one.
     */
    public function test_revenue_change_is_null_when_previous_revenue_is_negative(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
            'document_date' => now(),
        ]);
        $this->createInvoice($tenant, $company, $partner, [
            'status' => DocumentStatus::Posted,
            'total' => '-50.000',
            'balance_due' => '-50.000',
            'document_date' => now()->subMonthNoOverflow(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.revenue.change', null);
    }

    /**
     * AMENDED ruling (2026-08-03 gate N1 — supersedes the original L4 test,
     * which never linked the refund to an original payment and therefore
     * never exercised the double-netting defect at all): a PARTIAL refund
     * leaves the original payment `Completed`
     * (PaymentRefundService::partialRefund() only touches `notes`), so the
     * negative refund row IS the only expression of the refund and must net.
     */
    public function test_payments_received_nets_completed_partial_refund_whose_original_stays_completed(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $method = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK',
            'name' => 'Bank Transfer',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $original = $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '200.000',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '-80.000',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Refund,
            'original_payment_id' => $original->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.received', '120.000');
    }

    /**
     * N1 (HIGH, 2026-08-03 gate) — the defect this round fixes: a FULL
     * refund flips the ORIGINAL payment to `PaymentStatus::Reversed`
     * (PaymentRefundService::refundPayment()), which the status=Completed
     * filter already excludes from `received`. The negative refund row must
     * ALSO be excluded (its original is no longer Completed) — otherwise the
     * reversal is subtracted a second time, silently understating `received`
     * by the full refunded amount even though the original never contributed
     * anything in the first place.
     */
    public function test_payments_received_excludes_full_refund_whose_original_was_reversed(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $method = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK',
            'name' => 'Bank Transfer',
            'is_physical' => false,
            'is_active' => true,
        ]);

        // Unrelated, untouched incoming payment this month.
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '300.000',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        // Original payment that was FULLY refunded -> reversed by
        // PaymentRefundService::refundPayment(). Already excluded by the
        // status=Completed filter, same as before this ticket's changes.
        $original = $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '200.000',
            'status' => PaymentStatus::Reversed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        // The refund row itself: Completed, negative amount, linked via
        // original_payment_id to the now-Reversed original.
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '-200.000',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Refund,
            'original_payment_id' => $original->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        // The full-refund pair contributes exactly 0 — NOT -200.000 (double-netted)
        // and NOT +200.000 (as if the refund never happened).
        $response->assertOk()
            ->assertJsonPath('data.payments.received', '300.000');
    }

    /**
     * N1: combined shape mirroring the gate re-check's live-tenant arithmetic
     * (gross completed incoming - partial refunds [net] - full refunds [0,
     * not double-counted] = correct received). Both refund shapes present in
     * the same month, asserted together so a regression in either direction
     * (double-netting full refunds again, or failing to net partials) shows
     * up as a single wrong total.
     */
    public function test_payments_received_nets_partial_refunds_but_not_full_refunds_together(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $method = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK',
            'name' => 'Bank Transfer',
            'is_physical' => false,
            'is_active' => true,
        ]);

        // Gross completed incoming this month, untouched by any refund.
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '1000.000',
        ]);

        // Partial refund: original stays Completed, refund row nets.
        $partialOriginal = $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '500.000',
        ]);
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '-150.000',
            'payment_type' => PaymentType::Refund,
            'original_payment_id' => $partialOriginal->id,
        ]);

        // Full refund: original reversed, refund row must contribute 0 (not -300.000).
        $fullOriginal = $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '300.000',
            'status' => PaymentStatus::Reversed,
        ]);
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '-300.000',
            'payment_type' => PaymentType::Refund,
            'original_payment_id' => $fullOriginal->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        // 1000.000 (gross) + 500.000 (partial's original, still Completed)
        // - 150.000 (partial refund, nets) = 1350.000. The Reversed original
        // (300.000) and its refund (-300.000) are BOTH excluded — net 0, not
        // a further -300.000.
        $response->assertOk()
            ->assertJsonPath('data.payments.received', '1350.000');
    }

    /**
     * W4R2-2 — the campaign tenant's exact tile, reproduced.
     *
     * `GET /dashboard/stats` returned `payments.received = "1580.400"` for a
     * tenant whose only inbound money was one 452.000 POS sale:
     *
     *     452.000  POS sale                  (genuinely received)
     *   +  42.800  POS refund                money OUT of the drawer
     *   +  85.600  POS refund                money OUT of the drawer
     *   + 500.000  supplier payment          money OUT of the bank
     *   + 200.000  supplier payment          money OUT of the bank
     *   + 300.000  supplier payment          money OUT of the bank
     *   =1580.400
     *
     * The READER was never wrong: it filters on `PaymentType::isIncoming()`,
     * which answers `false` for both `SupplierPayment` and `POSRefund`. The
     * WRITERS were — supplier payments were stamped `DocumentPayment` and POS
     * refund legs `POS`, both of which are incoming. This test pins the reader
     * against correctly-typed rows so a future writer regression is caught here
     * as well as at the writer.
     *
     * GROSS, not net, is the assertion: the tile is labelled "Payments Received"
     * (`common.json → dashboard.paymentsReceived`, `Paiements reçus`,
     * `المدفوعات المستلمة`) — money that came in during the period, which is what
     * 452.000 is. Outgoing rows are EXCLUDED, not subtracted. The separate
     * partial-refund NETTING arm (the 2026-08-03 N1 ruling, pinned by
     * `test_payments_received_nets_completed_partial_refund_whose_original_stays_completed`)
     * is untouched: it nets an AR `Refund` row against a still-Completed original
     * via `original_payment_id`, and a POS refund leg has no original to net
     * against.
     */
    public function test_payments_received_excludes_supplier_payments_and_pos_refunds(): void
    {
        [$tenant, $company, $user, $partner] = $this->bootstrapTenantCompanyUserPartner();

        $method = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        // The one genuine inflow.
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '452.000',
            'payment_type' => PaymentType::POS,
        ]);

        // Two POS refunds — POSITIVE amounts, exactly as the bridge writes them.
        // Under the old `PaymentType::POS` stamping these were counted as inflow.
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '42.800',
            'payment_type' => PaymentType::POSRefund,
        ]);
        $this->createPayment($tenant, $company, $partner, $method, [
            'amount' => '85.600',
            'payment_type' => PaymentType::POSRefund,
        ]);

        // Three supplier payments. Under the old `DocumentPayment` stamping
        // these were counted as inflow too.
        foreach (['500.000', '200.000', '300.000'] as $supplierAmount) {
            $this->createPayment($tenant, $company, $partner, $method, [
                'amount' => $supplierAmount,
                'payment_type' => PaymentType::SupplierPayment,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.payments.received', '452.000');
    }

    /**
     * W4R2-2 companion — the enum contract the tile depends on, stated directly.
     *
     * `DashboardController` builds its whitelist by filtering `PaymentType::cases()`
     * on `isIncoming()`. Pinning the whitelist itself means a future case added
     * without an explicit incoming/outgoing ruling cannot silently join it.
     */
    public function test_only_genuinely_incoming_payment_types_are_whitelisted_by_the_tile(): void
    {
        $incoming = array_values(array_map(
            static fn (PaymentType $type): string => $type->value,
            array_filter(
                PaymentType::cases(),
                static fn (PaymentType $type): bool => $type->isIncoming(),
            ),
        ));

        $this->assertSame(['document_payment', 'advance', 'pos'], $incoming);

        $this->assertFalse(PaymentType::SupplierPayment->isIncoming());
        $this->assertFalse(PaymentType::POSRefund->isIncoming());
        $this->assertTrue(PaymentType::SupplierPayment->isOutgoing());
        $this->assertTrue(PaymentType::POSRefund->isOutgoing());
    }
}
