<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

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
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PaymentRefundSecurityTest - Verify tenant isolation on refund endpoints
 *
 * Ensures that users from one tenant cannot refund, reverse, or inspect
 * payments belonging to a different tenant.
 */
class PaymentRefundSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Company $companyA;

    private User $userA;

    private PaymentMethod $cashMethodA;

    private Partner $customerA;

    private Document $invoiceA;

    private Payment $paymentA;

    private Tenant $tenantB;

    private Company $companyB;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // --- Tenant A setup ---
        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAXA123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Ensure refund/reverse permissions exist (not in base seeder)
        foreach (['payments.refund', 'payments.reverse'] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->userA->givePermissionTo([
            'payments.view', 'payments.create', 'payments.allocate',
            'payments.refund', 'payments.reverse',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->cashMethodA = PaymentMethod::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Customer A',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->invoiceA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-A-0001',
            'partner_id' => $this->customerA->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'currency' => 'EUR',
        ]);

        // Create a payment in Tenant A
        $this->paymentA = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'payment_method_id' => $this->cashMethodA->id,
            'amount' => '500.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-A-001',
            'created_by' => $this->userA->id,
        ]);

        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $this->paymentA->id,
            'document_id' => $this->invoiceA->id,
            'amount' => '500.00',
        ]);

        // --- Tenant B setup ---
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAXB456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->userB->givePermissionTo([
            'payments.view', 'payments.create', 'payments.allocate',
            'payments.refund', 'payments.reverse',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);
    }

    /**
     * Switch CompanyContext to Tenant B (simulating a request from User B).
     */
    private function switchToTenantB(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyB->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
    }

    public function test_cross_tenant_refund_payment_returns_404(): void
    {
        $this->switchToTenantB();

        $response = $this->actingAs($this->userB)->postJson(
            "/api/v1/payments/{$this->paymentA->id}/refund",
            ['reason' => 'Cross-tenant refund attempt']
        );

        $response->assertStatus(404);
    }

    public function test_cross_tenant_partial_refund_returns_404(): void
    {
        $this->switchToTenantB();

        $response = $this->actingAs($this->userB)->postJson(
            "/api/v1/payments/{$this->paymentA->id}/partial-refund",
            ['amount' => '100.00', 'reason' => 'Cross-tenant partial refund attempt']
        );

        $response->assertStatus(404);
    }

    public function test_cross_tenant_reverse_payment_returns_404(): void
    {
        $this->switchToTenantB();

        $response = $this->actingAs($this->userB)->postJson(
            "/api/v1/payments/{$this->paymentA->id}/reverse",
            ['reason' => 'Cross-tenant reverse attempt']
        );

        $response->assertStatus(404);
    }

    public function test_cross_tenant_check_refundable_returns_404(): void
    {
        $this->switchToTenantB();

        $response = $this->actingAs($this->userB)->getJson(
            "/api/v1/payments/{$this->paymentA->id}/can-refund"
        );

        $response->assertStatus(404);
    }

    public function test_cross_tenant_refund_history_returns_404(): void
    {
        $this->switchToTenantB();

        $response = $this->actingAs($this->userB)->getJson(
            "/api/v1/payments/{$this->paymentA->id}/refund-history"
        );

        $response->assertStatus(404);
    }

    public function test_same_tenant_refund_payment_succeeds(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);

        $response = $this->actingAs($this->userA)->postJson(
            "/api/v1/payments/{$this->paymentA->id}/refund",
            ['reason' => 'Legitimate refund']
        );

        $response->assertStatus(201);
    }
}
