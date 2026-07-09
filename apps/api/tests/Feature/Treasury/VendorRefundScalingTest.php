<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Precision regression: VendorRefundService bc* scale literals.
 *
 * Before the fix:
 *   bccomp($amount, $totalAllocated, 2)        → truncates TND 3rd decimal
 *   bcadd($currentBalance, $amount, 2)         → truncates to 2 dp
 *   bcsub($repoBalance, $amount, 2)            → truncates to 2 dp
 *
 * After the fix each site uses $this->scaleResolver->getScale($currency) (= 3 for TND).
 *
 * Gold assertion (TND, scale 3):
 *   - refund amount = '50.123'
 *   - PO balance_due after refund = bcadd('0.000', '50.123', 3) = '50.123'
 *   - repo balance after refund   = bcsub('200.000', '50.123', 3) = '149.877'
 *
 * With hardcoded scale 2 the stored values would be '50.12' and '149.88'.
 */
final class VendorRefundScalingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $vendor;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private Document $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TND Vendor Refund Tenant',
            'slug' => 'tnd-vendor-refund-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TND company — scale 3
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Vendor Refund Company',
            'legal_name' => 'TND Vendor Refund LLC',
            'tax_id' => 'TND-VR-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Vendor TND',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'VIRE-TND',
            'name' => 'Virement TND',
            'is_active' => true,
        ]);

        // Final-review fix wave (Fix 1): VendorRefundService now REQUIRES a
        // GL-linked repository (an unledgered repository throws a
        // DomainException rather than recording a null-JE cash movement — see
        // RefundSpineTest::test_vendor_refund_on_repository_without_gl_account_is_rejected_422).
        // This precision-regression test exercises the refund's bc* scale
        // literals, which are independent of the GL path, so the repository
        // and company are given the GL accounts the reversal requires.
        $bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512-TND',
            'name' => 'Bank TND',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Bank,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4091-TND',
            'name' => 'Supplier Advances TND',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::SupplierAdvance,
            'is_active' => true,
        ]);

        // Repository with an initial balance of 200.000 TND, GL-linked so the
        // refund posts its reversal (required post-Fix-1).
        // factory() is unguarded, so it seeds the port-managed (non-fillable) `balance` (Task 22).
        $this->repository = PaymentRepository::factory()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'REPO-TND-01',
            'name' => 'TND Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '200.000',
            'is_active' => true,
            'gl_account_id' => $bankAccount->id,
        ]);

        // PO with balance_due = 0 (fully prepaid)
        $this->purchaseOrder = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-TND-'.Str::random(4),
            'document_date' => now()->toDateString(),
            'partner_id' => $this->vendor->id,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '0.000',
            'currency' => 'TND',
            'status' => DocumentStatus::Confirmed,
        ]);

        // Existing allocation of 100.000 TND to the PO (simulates full prepayment)
        $prepayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PREPAY-TND-001',
        ]);

        PaymentAllocation::create([
            'payment_id' => $prepayment->id,
            'document_id' => $this->purchaseOrder->id,
            'amount' => '100.000',
        ]);
    }

    /**
     * TND refund of 50.123 must preserve 3 decimal places in balance_due and repo balance.
     *
     * Old behaviour (hardcoded scale 2):
     *   balance_due  = '50.12'  (3rd decimal truncated)
     *   repo balance = '149.88' (3rd decimal truncated)
     *
     * New behaviour (scale 3 from resolver):
     *   balance_due  = '50.123'
     *   repo balance = '149.877'
     */
    public function test_tnd_refund_preserves_three_decimal_precision(): void
    {
        $service = app(VendorRefundService::class);

        $service->refundPrepayment(
            po: $this->purchaseOrder,
            amount: '50.123',
            paymentMethodId: $this->paymentMethod->id,
            repositoryId: $this->repository->id,
            reason: 'scale-3 precision regression test',
            userId: null,
        );

        $this->purchaseOrder->refresh();
        $this->repository->refresh();

        // balance_due must be '50.123', not '50.12' (scale-2 truncation)
        $this->assertSame(
            '50.123',
            $this->purchaseOrder->balance_due,
            'balance_due was truncated to scale 2; service must use scaleResolver->getScale(currency).'
        );

        // repository balance must be '149.877', not '149.88' (scale-2 truncation)
        $this->assertSame(
            '149.877',
            $this->repository->balance,
            'repository.balance was truncated to scale 2; service must use scaleResolver->getScale(currency).'
        );
    }
}
