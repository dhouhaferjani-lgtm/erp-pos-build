<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Treasury\Domain\Services\VendorRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 22 — Spec v7 §13 `Payment.origin` writer-inventory completeness test.
 *
 * The spec §13 writer-inventory table is the canonical list of every
 * Treasury `Payment` writer that must stamp `payments.origin`. This test
 * pins **one assertion per row** so a future writer added without an
 * origin stamp (or with the wrong stamp) fails loudly here rather than
 * silently bypassing the §13 invariant.
 *
 * Inventory (one method = one test):
 *   1. `ReceiptPaymentService` (POS receipt payment lines)
 *      → `pos` (covered by `TreasuryReceiptBridgeTest` + the legacy-
 *      retention stamp on `ReceiptPaymentService::processReceiptPayments`).
 *   2. `PaymentController::store()` → `web_admin`
 *   3. `PaymentController::storeMultiple()` → `web_admin`
 *   4. `MultiPaymentService::createSplitPayment()` → `web_admin`
 *   5. `MultiPaymentService::recordDeposit()` → `web_admin`
 *   6. `MultiPaymentService::recordPaymentOnAccount()` → `web_admin`
 *   7. `PaymentRefundService::refundPayment()` → inherit original origin
 *   8. `PaymentRefundService::partialRefund()` → inherit original origin
 *   9. `PaymentRefundService` receipt-proration refund rows → inherit
 *      (covered by Task 19's `refundReceiptPayments`).
 *  10. `VendorRefundService::refundPrepayment()` → `web_admin`
 *
 * `App\Modules\Billing\Domain\Payment` is a separate model — explicitly
 * out of §13 scope. Non-fiscal web/admin payments leave `fiscal_event_id`
 * NULL (only the projector path stamps the FK).
 */
final class PaymentOriginWriterInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private PaymentRepository $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-'.Str::random(6),
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
            'email' => 'user-'.Str::random(6).'@example.com',
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

        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
        ]);

        $this->bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-01',
            'name' => 'Business Bank Account',
            'type' => RepositoryType::BankAccount,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // §13 row 2 — PaymentController::store()
    // -----------------------------------------------------------------

    public function test_payment_controller_store_stamps_web_admin(): void
    {
        $invoice = $this->seedInvoice('1190.00');

        $this->actingAs($this->user);
        $response = $this->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '500.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'reference' => 'PMT-store-test',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '500.00'],
            ],
        ]);

        $response->assertStatus(201);

        $payment = Payment::query()->where('reference', 'PMT-store-test')->firstOrFail();
        $this->assertSame(PaymentOrigin::WebAdmin, $payment->origin);
        // Non-fiscal admin payment — fiscal_event_id stays NULL.
        $this->assertNull($payment->fiscal_event_id);
    }

    // -----------------------------------------------------------------
    // §13 row 3 — PaymentController::storeMultiple()
    // -----------------------------------------------------------------

    public function test_payment_controller_store_multiple_stamps_web_admin(): void
    {
        $invoice = $this->seedInvoice('1000.00');

        $this->actingAs($this->user);
        $response = $this->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'document_id' => $invoice->id,
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->cashRegister->id,
                    'amount' => '600.00',
                ],
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->bankAccount->id,
                    'amount' => '400.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $payments = Payment::query()
            ->where('company_id', $this->company->id)
            ->orderByDesc('amount')
            ->get();
        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertSame(PaymentOrigin::WebAdmin, $payment->origin);
            $this->assertNull($payment->fiscal_event_id);
        }
    }

    // -----------------------------------------------------------------
    // §13 row 4 — MultiPaymentService::createSplitPayment()
    // -----------------------------------------------------------------

    public function test_multi_payment_create_split_stamps_web_admin(): void
    {
        $invoice = $this->seedInvoice('1190.00');

        $payments = $this->app->make(MultiPaymentService::class)->createSplitPayment(
            $invoice,
            [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '500.00', 'repository_id' => $this->cashRegister->id],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '690.00', 'repository_id' => $this->bankAccount->id],
            ],
            $this->user->id,
        );

        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            $this->assertSame(PaymentOrigin::WebAdmin, $payment->origin);
            $this->assertNull($payment->fiscal_event_id);
        }
    }

    // -----------------------------------------------------------------
    // §13 row 5 — MultiPaymentService::recordDeposit()
    // -----------------------------------------------------------------

    public function test_multi_payment_record_deposit_stamps_web_admin(): void
    {
        $payment = $this->app->make(MultiPaymentService::class)->recordDeposit(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            paymentMethodId: $this->cashMethod->id,
            amount: '250.00',
            currency: 'EUR',
            repositoryId: $this->cashRegister->id,
        );

        $this->assertSame(PaymentOrigin::WebAdmin, $payment->origin);
        $this->assertNull($payment->fiscal_event_id);
    }

    // -----------------------------------------------------------------
    // §13 row 6 — MultiPaymentService::recordPaymentOnAccount()
    // -----------------------------------------------------------------

    public function test_multi_payment_record_payment_on_account_stamps_web_admin(): void
    {
        $result = $this->app->make(MultiPaymentService::class)->recordPaymentOnAccount(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '300.00',
            currency: 'EUR',
        );

        /** @var Payment $payment */
        $payment = $result['payment'];
        $this->assertSame(PaymentOrigin::WebAdmin, $payment->origin);
        $this->assertNull($payment->fiscal_event_id);
    }

    // -----------------------------------------------------------------
    // §13 rows 7+8 — PaymentRefundService refundPayment / partialRefund
    // inherit original origin
    // -----------------------------------------------------------------

    public function test_refund_inherits_pos_origin_when_original_is_pos(): void
    {
        $original = $this->seedPosOriginPayment('100.00');

        $refund = $this->app->make(PaymentRefundService::class)->refundPayment(
            $original,
            reason: 'customer return',
            userId: $this->user->id,
        );

        // Inheritance is the §13 disposition — DO NOT default to web_admin
        // and DO NOT default to NULL. The refund row carries the same
        // origin as the row it reverses so the audit lineage stays
        // semantically truthful.
        $this->assertSame(PaymentOrigin::Pos, $refund->origin);
    }

    public function test_refund_inherits_web_admin_origin_when_original_is_web_admin(): void
    {
        $original = $this->seedWebAdminOriginPayment('150.00');

        $refund = $this->app->make(PaymentRefundService::class)->refundPayment(
            $original,
            reason: 'admin reversal',
            userId: $this->user->id,
        );

        $this->assertSame(PaymentOrigin::WebAdmin, $refund->origin);
    }

    public function test_partial_refund_inherits_pos_origin_when_original_is_pos(): void
    {
        $original = $this->seedPosOriginPayment('100.00');

        $refund = $this->app->make(PaymentRefundService::class)->partialRefund(
            $original,
            amount: '40.00',
            reason: 'partial customer return',
            userId: $this->user->id,
        );

        $this->assertSame(PaymentOrigin::Pos, $refund->origin);
    }

    public function test_partial_refund_inherits_web_admin_origin_when_original_is_web_admin(): void
    {
        $original = $this->seedWebAdminOriginPayment('200.00');

        $refund = $this->app->make(PaymentRefundService::class)->partialRefund(
            $original,
            amount: '60.00',
            reason: 'admin partial reversal',
            userId: $this->user->id,
        );

        $this->assertSame(PaymentOrigin::WebAdmin, $refund->origin);
    }

    // -----------------------------------------------------------------
    // §13 row 9 — receipt-proration refund rows inherit original origin
    // -----------------------------------------------------------------

    public function test_receipt_proration_refund_inherits_pos_origin(): void
    {
        // refundReceiptPayments walks pos_receipt_payments.treasury_payment_id
        // to gather originals. We seed a Receipt with a Payment linked
        // via treasury_payment_id; the proration writes a refund row with
        // origin inherited from the original (pos).
        $receipt = $this->seedReceiptWithLinkedPayment(amount: '50.00');

        $allocations = $this->app->make(PaymentRefundService::class)->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.000',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $this->assertCount(1, $allocations);
        $refundRow = Payment::query()->findOrFail($allocations[0]->paymentId);
        $this->assertSame(PaymentOrigin::Pos, $refundRow->origin);
    }

    // -----------------------------------------------------------------
    // §13 row 10 — VendorRefundService::refundPrepayment() → web_admin
    // -----------------------------------------------------------------

    public function test_vendor_refund_prepayment_stamps_web_admin(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Supplier',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-2026-0001',
            'partner_id' => $supplier->id,
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
            'currency' => 'EUR',
        ]);

        // Seed a prepayment allocation so totalAllocated >= refund amount.
        $prepayment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '200.00',
            'currency' => 'EUR',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
            'origin' => PaymentOrigin::WebAdmin,
        ]);
        PaymentAllocation::create([
            'payment_id' => $prepayment->id,
            'document_id' => $po->id,
            'amount' => '200.00',
        ]);

        $refund = $this->app->make(VendorRefundService::class)->refundPrepayment(
            $po,
            amount: '100.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'supplier credit',
            userId: $this->user->id,
        );

        // §13 row 10 — vendor refunds are admin-side authoring (no inherit-
        // from-original rule applies; the prepayment-refund concept models
        // the procurement-side cash flow, not a reversal of an upstream
        // customer-facing payment).
        $this->assertSame(PaymentOrigin::WebAdmin, $refund->origin);
        $this->assertNull($refund->fiscal_event_id);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function seedInvoice(string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);
    }

    private function seedPosOriginPayment(string $amount): Payment
    {
        return Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'origin' => PaymentOrigin::Pos,
        ]);
    }

    private function seedWebAdminOriginPayment(string $amount): Payment
    {
        return Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::WebAdmin,
        ]);
    }

    /**
     * Build a minimal Receipt + linked Treasury Payment + pos_receipt_payments
     * row so refundReceiptPayments() can walk the linkage. The PG-level
     * partial-unique-index on (company_id, original_payment_id, refund_request_id)
     * also needs to be respected — we don't pre-write a refund, just the
     * original.
     */
    private function seedReceiptWithLinkedPayment(string $amount): Receipt
    {
        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        /** @var numeric-string $amount */
        $receipt = Receipt::factory()
            ->withTotal($amount, '0.000')
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'location_id' => $location->id,
                'terminal_id' => $terminal->id,
                'cashier_id' => $this->user->id,
                'currency' => 'EUR',
            ]);

        $original = $this->seedPosOriginPayment($amount);

        // Link via pos_receipt_payments.treasury_payment_id — the proration
        // query reads this linkage to find originals.
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            'payment_method_code' => 'CASH',
            'amount' => $amount,
            'treasury_payment_id' => $original->id,
        ]);

        return $receipt;
    }
}
