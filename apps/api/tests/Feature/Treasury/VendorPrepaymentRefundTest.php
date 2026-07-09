<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
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
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\VendorRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VendorPrepaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private Partner $vendor;

    private VendorRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-refund',
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
            'email' => 'user-refund@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.void']);

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
            'balance' => '5000.00',
        ]);

        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Vendor Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->refundService = app(VendorRefundService::class);

        // Final-review fix wave (Fix 1): VendorRefundService now requires a
        // GL-linked repository (a repository with no gl_account_id throws a
        // DomainException rather than recording a null-JE cash movement — see
        // RefundSpineTest::test_vendor_refund_on_repository_without_gl_account_is_rejected_422).
        // Every test in this file exercises refundPrepayment(), so the GL
        // accounts are seeded unconditionally here.
        $this->seedSupplierAdvanceRefundAccounts();
    }

    private function seedSupplierAdvanceRefundAccounts(): void
    {
        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512',
            'name' => 'Bank',
            'type' => 'asset',
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4091',
            'name' => 'Supplier Advances',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::SupplierAdvance,
            'is_active' => true,
        ]);

        $this->cashRegister->gl_account_id = $cashAccount->id;
        $this->cashRegister->save();
    }

    public function test_full_refund_restores_balance_due(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $refund = $this->refundService->refundPrepayment(
            po: $po,
            amount: '1000.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Order cancelled',
            userId: $this->user->id,
        );

        $this->assertEquals(PaymentType::Refund, $refund->payment_type);
        $this->assertEquals('1000.000', $refund->amount);

        $po->refresh();
        $this->assertEquals('1000.000', $po->balance_due);
        $this->assertEquals(DocumentStatus::Confirmed, $po->status);
    }

    public function test_partial_refund_adjusts_balance_due(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $refund = $this->refundService->refundPrepayment(
            po: $po,
            amount: '400.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Partial cancellation',
            userId: $this->user->id,
        );

        $this->assertEquals('400.000', $refund->amount);

        $po->refresh();
        $this->assertEquals('400.000', $po->balance_due);
        $this->assertEquals(DocumentStatus::Confirmed, $po->status);
    }

    public function test_refund_prepayment_posts_supplier_advance_reversal_entry(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $refund = $this->refundService->refundPrepayment(
            po: $po,
            amount: '400.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Partial cancellation',
            userId: $this->user->id,
        );

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_advance_refund')
            ->where('source_id', $refund->id)
            ->firstOrFail();

        $this->assertSame($entry->id, $refund->journal_entry_id);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);

        $cashLine = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('debit', '400.000')
            ->firstOrFail();

        $this->cashRegister->refresh();
        $this->assertSame($this->cashRegister->gl_account_id, $cashLine->account_id);
    }

    public function test_over_refund_is_rejected(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '500.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Refund amount (600.00) exceeds total allocated');

        $this->refundService->refundPrepayment(
            po: $po,
            amount: '600.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Too much',
            userId: $this->user->id,
        );
    }

    public function test_refund_rejected_for_invoice(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2026-0001',
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '833.33',
            'tax_amount' => '166.67',
            'total' => '1000.00',
            'balance_due' => '1000.00',
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Prepayment refund is only available for Purchase Orders');

        $this->refundService->refundPrepayment(
            po: $invoice,
            amount: '1000.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Test',
            userId: $this->user->id,
        );
    }

    public function test_refund_creates_negative_allocation(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $refund = $this->refundService->refundPrepayment(
            po: $po,
            amount: '1000.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Full refund',
            userId: $this->user->id,
        );

        $allocation = PaymentAllocation::where('payment_id', $refund->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals('-1000.000', $allocation->amount);
        $this->assertEquals($po->id, $allocation->document_id);
    }

    public function test_refund_status_preserved_as_confirmed(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $this->refundService->refundPrepayment(
            po: $po,
            amount: '500.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Partial',
            userId: $this->user->id,
        );

        $po->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $po->status);
    }

    public function test_api_endpoint_full_refund(): void
    {
        $po = $this->createPurchaseOrder('1000.00');
        $this->allocatePaymentToPO($po, '1000.00');

        $response = $this->actingAs($this->user)->postJson("/api/v1/documents/{$po->id}/refund-prepayment", [
            'amount' => 1000.00,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'reason' => 'Order cancelled via API',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Prepayment refunded successfully');

        $po->refresh();
        $this->assertEquals('1000.000', $po->balance_due);
        $this->assertEquals(DocumentStatus::Confirmed, $po->status);
    }

    public function test_refund_rejected_for_draft_purchase_order(): void
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-2026-DRAFT',
            'document_date' => now(),
            'status' => DocumentStatus::Draft,
            'subtotal' => '833.33',
            'tax_amount' => '166.67',
            'total' => '1000.00',
            'balance_due' => '1000.00',
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot refund prepayment on a PO with status 'draft'");

        $this->refundService->refundPrepayment(
            po: $po,
            amount: '500.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Should fail - draft',
            userId: $this->user->id,
        );
    }

    public function test_refund_rejected_for_cancelled_purchase_order(): void
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-2026-CANCEL',
            'document_date' => now(),
            'status' => DocumentStatus::Cancelled,
            'subtotal' => '833.33',
            'tax_amount' => '166.67',
            'total' => '1000.00',
            'balance_due' => '1000.00',
            'currency' => 'EUR',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot refund prepayment on a PO with status 'cancelled'");

        $this->refundService->refundPrepayment(
            po: $po,
            amount: '500.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Should fail - cancelled',
            userId: $this->user->id,
        );
    }

    public function test_api_endpoint_rejects_wrong_doc_type(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2026-0002',
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '833.33',
            'tax_amount' => '166.67',
            'total' => '1000.00',
            'balance_due' => '1000.00',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/documents/{$invoice->id}/refund-prepayment", [
            'amount' => 1000.00,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'reason' => 'Should fail',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'REFUND_FAILED');
    }

    /**
     * @param  numeric-string  $total
     */
    private function createPurchaseOrder(string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
            'subtotal' => bcmul($total, '0.833333', 2),
            'tax_amount' => bcmul($total, '0.166667', 2),
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function allocatePaymentToPO(Document $po, string $amount): Payment
    {
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::SupplierPayment,
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $po->id,
            'amount' => $amount,
        ]);

        $currentBalance = $po->balance_due;
        if ($currentBalance === null) {
            $currentBalance = $po->total;
        }

        if ($currentBalance === null) {
            throw new \LogicException('Purchase order balance is required for prepayment refund tests.');
        }

        $po->balance_due = bcsub($currentBalance, $amount, 2);
        $po->save();

        return $payment;
    }
}
