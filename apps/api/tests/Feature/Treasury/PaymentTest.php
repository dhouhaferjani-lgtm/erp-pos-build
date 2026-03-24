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
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private Document $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.allocate']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0001',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'currency' => 'TND',
        ]);
    }

    public function test_can_list_payments(): void
    {
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'reference' => 'PMT-001',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payments');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_create_cash_payment(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'reference' => 'RCT-001',
            'notes' => 'Partial payment',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '500.000');
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_can_create_payment_with_allocation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1190.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '1190.000');
        $response->assertJsonCount(1, 'data.allocations');
    }

    public function test_payment_updates_invoice_balance(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '500.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->invoice->refresh();
        $this->assertEquals('690.000', $this->invoice->balance_due);
    }

    public function test_full_payment_marks_invoice_as_paid(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1190.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->invoice->refresh();
        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);
    }

    public function test_overpayment_caps_allocation_and_creates_excess(): void
    {
        // Invoice has balance_due of 1190 (1000 + 190 tax), but we request to allocate 2000
        // The system should cap the allocation at 1190 (invoice balance)
        // and allow the excess (810) to be treated as customer advance

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '2000.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '2000.00', // More than balance_due - will be capped
                ],
            ],
        ]);

        // Payment should succeed
        $response->assertStatus(201);

        // Allocation should be capped at invoice balance (1190)
        $this->assertDatabaseHas('payment_allocations', [
            'document_id' => $this->invoice->id,
            'amount' => '1190.0000', // Capped at invoice balance
        ]);

        // Invoice should be fully paid
        $this->invoice->refresh();
        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);
    }

    public function test_can_filter_payments_by_partner(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Corp',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherPartner->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '300.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payments?partner_id='.$this->customer->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_view_single_payment(): void
    {
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'reference' => 'PMT-001',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/payments/{$payment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.reference', 'PMT-001');
        $response->assertJsonPath('data.amount', '500.000');
    }

    public function test_unauthorized_user_cannot_create_payment(): void
    {
        $this->user->revokePermissionTo('payments.create');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_can_allocate_payment_to_multiple_invoices(): void
    {
        $invoice2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0002',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'balance_due' => '595.00',
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1785.00', // Sum of both invoices
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
                [
                    'document_id' => $invoice2->id,
                    'amount' => '595.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'data.allocations');

        $this->invoice->refresh();
        $invoice2->refresh();

        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals('0.000', $invoice2->balance_due);
    }

    public function test_allocation_amount_cannot_exceed_payment_amount(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1000.00', // More than payment amount
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ALLOCATION_EXCEEDS_PAYMENT');
    }
}
