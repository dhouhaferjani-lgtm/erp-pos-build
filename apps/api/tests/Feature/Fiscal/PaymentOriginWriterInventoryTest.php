<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
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
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Opening balance laid down on every ledgered fixture till by
     * {@see self::fundRepository()}. Comfortably above the largest refund
     * these tests issue (150.00 EUR), so the W-5b balance-sufficiency guard
     * never participates in what this origin-inventory test asserts.
     */
    private const TILL_OPENING_BALANCE = '1000.00';

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private PaymentRepository $bankAccount;

    /**
     * Final-review fix wave 2: a GL-linked repository, lazily built by
     * `ledgeredCashRegister()` for tests that now require one (`store()` /
     * `storeMultiple()`'s ledgered-repository guard, and
     * `PaymentRefundService`'s GL-linked-repository guard). NOT eagerly
     * created in `setUp()` — `test_vendor_refund_prepayment_stamps_web_admin`
     * already creates its OWN Bank/SupplierAdvance accounts, and system
     * accounts are unique per (company_id, system_purpose); eagerly seeding
     * a second Bank account for every test would collide with it.
     * `$this->cashRegister` / `$this->bankAccount` stay deliberately
     * unledgered — other tests in this file (the MultiPaymentService rows
     * and the receipt-proration helper) exercise the unledgered path and
     * must not be disturbed.
     */
    private ?PaymentRepository $ledgeredCashRegister = null;

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

    /**
     * Final-review fix wave 2: lazily build a GL-linked cash register (Bank +
     * CustomerReceivable system accounts) for tests exercising a guard that
     * now requires one — `PaymentController::store()`/`storeMultiple()`'s
     * ledgered-repository guard, and `PaymentRefundService::postRefundGlAndMovement`
     * (requires gl_account_id) whose GL reversal (createPaymentRefundJournalEntry)
     * requires a CustomerReceivable account. Memoized per test (not eager in
     * setUp()) so it never collides with tests that seed their OWN
     * per-purpose accounts (e.g. test_vendor_refund_prepayment_stamps_web_admin) —
     * system accounts are unique per (company_id, system_purpose).
     */
    private function ledgeredCashRegister(): PaymentRepository
    {
        if ($this->ledgeredCashRegister !== null) {
            return $this->ledgeredCashRegister;
        }

        $bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512-REFUND',
            'name' => 'Bank (refund origin fixtures)',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Bank,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411-REFUND',
            'name' => 'Customer Receivable (refund origin fixtures)',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $register = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-REFUND',
            'name' => 'Ledgered Cash Register (refund origin fixtures)',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'gl_account_id' => $bankAccount->id,
        ]);

        // W-5b Option B: the refund tests below drive an OUTFLOW through the
        // movement port, and the originals they reverse are seeded as bare
        // Payment rows (Payment::factory()) that never moved cash into the
        // till. A cash_register derives allow_negative = false, so the till
        // must actually hold the money it refunds.
        $this->fundRepository($register, self::TILL_OPENING_BALANCE);

        return $this->ledgeredCashRegister = $register;
    }

    /**
     * Fund a repository through the movement port (mirrors
     * PaymentRepositorySeeder::recordOpeningBalance() and the sibling fixture
     * fixes in PaymentGlPostingTest / VendorPrepaymentRefundTest /
     * ExpenseVatPostingTest) — `balance` is port-managed and NOT fillable, so
     * a plain create() carrying a 'balance' key is silently dropped and the
     * repository is always minted at zero.
     *
     * @param  numeric-string  $amount
     */
    private function fundRepository(PaymentRepository $repository, string $amount): void
    {
        DB::transaction(fn () => $this->app->make(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repository->id,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Test fixture opening balance',
            allowWhileFrozen: false,
        )));

        $repository->refresh();
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
            // Final-review fix wave 2: PaymentController::store() now 422s a
            // customer/AR payment naming an unledgered repository
            // (PAYMENT_REQUIRES_LEDGERED_REPOSITORY guard) — use the
            // ledgered fixture, not the shared unledgered $this->cashRegister.
            'repository_id' => $this->ledgeredCashRegister()->id,
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
            // Final-review fix wave 2: storeMultiple()'s per-line cash-movement
            // guard (PaymentController.php:~1497) 422s any line whose
            // repository has no gl_account_id — both lines use the ledgered
            // fixture, not the shared unledgered $this->cashRegister /
            // $this->bankAccount.
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->ledgeredCashRegister()->id,
                    'amount' => '600.00',
                ],
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->ledgeredCashRegister()->id,
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
    // §13 rows 7+8 — NULL-origin legacy fallback (Codex T22-B2 BLOCKER)
    //
    // Pre-Task-12 payments have `origin = NULL`. Spec §13 mandates legacy
    // rows map to `unknown_legacy`; spec §17.6 mandates every §13 writer
    // stamps a non-NULL `origin`. Without the fallback every refund of a
    // legacy payment would itself silently land with NULL — defeating
    // the §13 invariant and the spec §17.6 rule.
    // -----------------------------------------------------------------

    public function test_refund_payment_falls_back_to_unknown_legacy_when_original_origin_is_null(): void
    {
        $original = $this->seedNullOriginPayment('100.00');

        $refund = $this->app->make(PaymentRefundService::class)->refundPayment(
            $original,
            reason: 'legacy refund',
            userId: $this->user->id,
        );

        // Spec §13 — legacy rows map to `unknown_legacy`. DO NOT silently
        // stamp NULL — `unknown_legacy` is the deliberate sentinel that
        // lets ops reason about pre-Task-12 data.
        $this->assertSame(PaymentOrigin::UnknownLegacy, $refund->origin);
    }

    public function test_partial_refund_falls_back_to_unknown_legacy_when_original_origin_is_null(): void
    {
        $original = $this->seedNullOriginPayment('200.00');

        $refund = $this->app->make(PaymentRefundService::class)->partialRefund(
            $original,
            amount: '50.00',
            reason: 'legacy partial refund',
            userId: $this->user->id,
        );

        $this->assertSame(PaymentOrigin::UnknownLegacy, $refund->origin);
    }

    public function test_proration_refund_falls_back_to_unknown_legacy_when_original_origin_is_null(): void
    {
        // Build a Receipt + linked POS-typed Payment with origin = NULL
        // (legacy pre-Task-12). The proration query filters payment_type=POS
        // (not origin), so a NULL-origin POS-typed payment IS reachable
        // here in steady state.
        $receipt = $this->seedReceiptWithLinkedPayment(amount: '50.00', linkedOrigin: null);

        $allocations = $this->app->make(PaymentRefundService::class)->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.000',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $this->assertCount(1, $allocations);
        $refundRow = Payment::query()->findOrFail($allocations[0]->paymentId);
        $this->assertSame(PaymentOrigin::UnknownLegacy, $refundRow->origin);
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

    public function test_proration_refund_inherits_web_admin_origin_when_original_is_web_admin(): void
    {
        // Task 22 round-2 (Opus F2 P1): the §13 row 9 contract is
        // "inherit the original payment's `origin`" — unqualified. The
        // proration query filters `payment_type = POS` (line :349), so
        // in steady state only POS-typed originals are reachable. But
        // `origin` is independent of `payment_type` per the enum + the
        // §13 disposition, so a POS-typed original CAN carry
        // `web_admin` origin (e.g. an admin-side manual payment recorded
        // against a POS-typed sale; or a future broadening of the
        // proration query). This test pins the universal-inheritance
        // contract so a future code change that drops the helper's
        // `originForRefund()` call won't slip past the §13 invariant.
        $receipt = $this->seedReceiptWithLinkedPayment(
            amount: '60.00',
            linkedOrigin: PaymentOrigin::WebAdmin,
        );

        $allocations = $this->app->make(PaymentRefundService::class)->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '60.000',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $this->assertCount(1, $allocations);
        $refundRow = Payment::query()->findOrFail($allocations[0]->paymentId);
        $this->assertSame(PaymentOrigin::WebAdmin, $refundRow->origin);
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

        // Final-review fix wave (Fix 1): VendorRefundService now requires a
        // GL-linked repository. Use a repository dedicated to this test (not
        // the shared $this->cashRegister, which other tests in this file
        // deliberately leave unledgered) so only this refund exercises the
        // GL-reversal path.
        $bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512-VR',
            'name' => 'Bank (vendor refund)',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Bank,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4091-VR',
            'name' => 'Supplier Advances (vendor refund)',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::SupplierAdvance,
            'is_active' => true,
        ]);
        $ledgeredRepository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-VR',
            'name' => 'Vendor Refund Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'gl_account_id' => $bankAccount->id,
        ]);

        // Same unfunded-till gap as ledgeredCashRegister(): the prepayment
        // below is a bare Payment row, so no cash ever entered this register
        // — fund it before refundPrepayment() drives its outflow.
        $this->fundRepository($ledgeredRepository, self::TILL_OPENING_BALANCE);

        // Seed a prepayment allocation so totalAllocated >= refund amount.
        $prepayment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $ledgeredRepository->id,
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
            repositoryId: $ledgeredRepository->id,
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
            // Final-review fix wave 2: ledgered — these originals get refunded
            // by PaymentRefundService, which now requires a GL-linked repository.
            'repository_id' => $this->ledgeredCashRegister()->id,
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
            // Final-review fix wave 2: ledgered — see seedPosOriginPayment note.
            'repository_id' => $this->ledgeredCashRegister()->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::WebAdmin,
        ]);
    }

    /**
     * Seed a pre-Task-12 legacy Payment row with `origin = NULL`. Task 22
     * round-2 (Codex T22-B2 BLOCKER): refund writers must fall back to
     * `unknown_legacy` when the original origin is NULL. Bypass the model's
     * `origin` enum cast by using raw DB::table insert — Eloquent factories
     * would coerce a `null` to NULL (which works), but a forceFill/raw insert
     * is the unambiguous way to produce a true NULL persisted row.
     */
    private function seedNullOriginPayment(string $amount): Payment
    {
        $id = Str::uuid()->toString();

        DB::table('payments')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            // Final-review fix wave 2: ledgered — see seedPosOriginPayment note.
            'repository_id' => $this->ledgeredCashRegister()->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed->value,
            'payment_type' => PaymentType::DocumentPayment->value,
            'origin' => null, // The thing this helper exists to express.
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($id);

        // Sanity — confirm the cast resolves to null, not a fallback enum.
        $this->assertNull(
            $payment->origin,
            'seedNullOriginPayment must produce a row with origin = NULL, '.
            'otherwise the NULL-origin refund-fallback tests are not exercising the gap.',
        );

        return $payment;
    }

    /**
     * Build a minimal Receipt + linked Treasury Payment + pos_receipt_payments
     * row so refundReceiptPayments() can walk the linkage. The PG-level
     * partial-unique-index on (company_id, original_payment_id, refund_request_id)
     * also needs to be respected — we don't pre-write a refund, just the
     * original.
     *
     * Task 22 round-2:
     *  - `linkedOrigin = null` produces a Payment whose `origin` column is
     *    NULL (legacy pre-Task-12 row), used by the Codex T22-B2 BLOCKER
     *    coverage.
     *  - `linkedOrigin = PaymentOrigin::WebAdmin` produces a POS-typed
     *    payment carrying web_admin origin (an unusual but valid combination
     *    per the §13 disposition: origin and payment_type are independent),
     *    used by the Opus F2 symmetric-inherit coverage.
     *  - default `PaymentOrigin::Pos` is the steady-state case.
     */
    private function seedReceiptWithLinkedPayment(
        string $amount,
        ?PaymentOrigin $linkedOrigin = PaymentOrigin::Pos,
    ): Receipt {
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

        // The original IS POS-typed (proration query filters on
        // payment_type = POS). The `linkedOrigin` parameter controls only
        // the `origin` column.
        if ($linkedOrigin === null) {
            $originalId = Str::uuid()->toString();
            DB::table('payments')->insert([
                'id' => $originalId,
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRegister->id,
                'amount' => $amount,
                'currency' => 'EUR',
                'payment_date' => now()->toDateString(),
                'status' => PaymentStatus::Completed->value,
                'payment_type' => PaymentType::POS->value,
                'origin' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            /** @var Payment $original */
            $original = Payment::query()->findOrFail($originalId);
        } else {
            $original = Payment::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRegister->id,
                'amount' => $amount,
                'currency' => 'EUR',
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::POS,
                'origin' => $linkedOrigin,
            ]);
        }

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
