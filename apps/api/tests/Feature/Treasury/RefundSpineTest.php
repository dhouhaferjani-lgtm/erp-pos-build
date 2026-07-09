<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\OverRefundException;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Treasury\Domain\Services\VendorRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 18 (Treasury spine, Wave D convergence): refunds converge onto the
 * movement write port + close the over-refund concurrency hole.
 *
 * (a) Vendor prepayment refund records a movement (source_type refund) in the
 *     SAME direction the pre-migration inline write used (OUT — it decremented
 *     the repository balance) plus a posted GL reversal; the balance moves once.
 * (b) Two sequential partial refunds whose sum exceeds the original are rejected
 *     under the original-payment lock (concurrent == sequential); no over-refund
 *     movement is written.
 * (c) A partial-refund retry with the SAME refund_request_id is idempotent: one
 *     refund payment, one movement, balance moved once.
 */
class RefundSpineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private Account $bankAccount;

    private Partner $customer;

    private Partner $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Refund Spine Tenant',
            'slug' => 'refund-spine-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Refund Spine Co',
            'legal_name' => 'Refund Spine Co LLC',
            'tax_id' => 'TAX-RS-1',
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
            'name' => 'Refund User',
            'email' => 'refund-spine@example.com',
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

        // GL accounts used by both refund reversals.
        $this->bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512',
            'name' => 'Bank',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Bank,
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

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        // Ledgered till, starting balance 5000. factory() is unguarded, so it
        // seeds the port-managed (non-fillable) `balance` on INSERT (Task 22).
        $this->cashRegister = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'balance' => '5000.00',
            'gl_account_id' => $this->bankAccount->id,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Vendor Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);
    }

    // (a) ---------------------------------------------------------------------

    public function test_vendor_refund_records_out_movement_and_gl_and_moves_balance_once(): void
    {
        $service = app(VendorRefundService::class);

        $po = $this->createConfirmedPurchaseOrder('1000.00');
        $this->allocatePrepaymentToPo($po, '1000.00');

        $refund = $service->refundPrepayment(
            po: $po,
            amount: '400.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'Partial cancellation',
            userId: $this->user->id,
        );

        // Exactly ONE movement on the till, direction OUT (matches the
        // pre-migration inline bcsub decrement), source_type refund.
        $movements = RepositoryMovement::query()
            ->where('payment_repository_id', $this->cashRegister->id)
            ->get();

        $this->assertCount(1, $movements, 'exactly one movement must be written');

        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame(MovementDirection::Out, $movement->direction);
        $this->assertSame(MovementSourceType::Refund, $movement->source_type);
        $this->assertSame($refund->id, $movement->source_id);
        $this->assertSame(0, bccomp($movement->amount, '400.00', 3));

        // Balance moved once: 5000 - 400 = 4600.
        $this->cashRegister->refresh();
        $this->assertSame(0, bccomp($this->cashRegister->balance, '4600.00', 3));

        // GL reversal posted and linked.
        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_advance_refund')
            ->where('source_id', $refund->id)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($entry->id, $refund->journal_entry_id);
        $this->assertSame($entry->id, $movement->journal_entry_id);
    }

    // (b) ---------------------------------------------------------------------

    public function test_cumulative_over_refund_is_rejected_under_lock(): void
    {
        $service = app(PaymentRefundService::class);
        $payment = $this->createCompletedCustomerPayment('100.00');

        // First partial 60 succeeds.
        $service->partialRefund($payment, '60.00', 'first', $this->user->id, Str::uuid()->toString());

        // Second partial 60 would total 120 > 100 → rejected.
        $threw = false;
        try {
            $service->partialRefund($payment, '60.00', 'second', $this->user->id, Str::uuid()->toString());
        } catch (OverRefundException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'second refund must throw OverRefundException');

        // Only the first refund exists; only one OUT movement was written.
        $refundCount = Payment::query()
            ->where('original_payment_id', $payment->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->count();
        $this->assertSame(1, $refundCount);

        $movementCount = RepositoryMovement::query()
            ->where('payment_repository_id', $this->cashRegister->id)
            ->where('source_type', MovementSourceType::Refund->value)
            ->count();
        $this->assertSame(1, $movementCount, 'no over-refund movement may be written');

        // Balance moved exactly once: 5000 - 60 = 4940.
        $this->cashRegister->refresh();
        $this->assertSame(0, bccomp($this->cashRegister->balance, '4940.00', 3));
    }

    // (c) ---------------------------------------------------------------------

    public function test_partial_refund_retry_with_same_request_id_is_idempotent(): void
    {
        $service = app(PaymentRefundService::class);
        $payment = $this->createCompletedCustomerPayment('100.00');

        $requestId = Str::uuid()->toString();

        $first = $service->partialRefund($payment, '40.00', 'retry', $this->user->id, $requestId);
        $second = $service->partialRefund($payment, '40.00', 'retry', $this->user->id, $requestId);

        // Same refund row returned — no second refund created.
        $this->assertSame($first->id, $second->id);

        $refundCount = Payment::query()
            ->where('original_payment_id', $payment->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->count();
        $this->assertSame(1, $refundCount, 'retry must not create a second refund');

        // One movement only.
        $movements = RepositoryMovement::query()
            ->where('payment_repository_id', $this->cashRegister->id)
            ->where('source_type', MovementSourceType::Refund->value)
            ->get();
        $this->assertCount(1, $movements);

        // Balance moved once: 5000 - 40 = 4960.
        $this->cashRegister->refresh();
        $this->assertSame(0, bccomp($this->cashRegister->balance, '4960.00', 3));

        // The movement idempotency key is refund:{original}:{request_id}.
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame("refund:{$payment->id}:{$requestId}", $movement->idempotency_key);
    }

    // (d) reconciliation-readiness Fix 3 -------------------------------------

    /**
     * A customer refund with NO resolvable actor (automated refund, userId null)
     * STILL posts the GL reversal and links it to the OUT movement — the spine
     * §9.2 invariant forbids a null-JE cash movement.
     */
    public function test_customer_refund_with_null_actor_posts_and_links_gl_reversal(): void
    {
        $service = app(PaymentRefundService::class);
        $payment = $this->createCompletedCustomerPayment('100.00');

        // Automated refund: no actor.
        $refund = $service->refundPayment($payment, 'automated refund', userId: null);

        // GL reversal POSTED (sealed) even with a null poster.
        $entry = JournalEntry::query()
            ->where('source_type', 'customer_payment_refund')
            ->where('source_id', $refund->id)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // The Refund movement carries the JE (never null on a cash-moving refund).
        $movement = RepositoryMovement::query()
            ->where('payment_repository_id', $this->cashRegister->id)
            ->where('source_type', MovementSourceType::Refund->value)
            ->firstOrFail();
        $this->assertNotNull($movement->journal_entry_id, 'refund movement must carry a JE');
        $this->assertSame($entry->id, $movement->journal_entry_id);
    }

    /**
     * A vendor prepayment refund with a null actor likewise seals + links its GL
     * reversal onto the OUT movement.
     */
    public function test_vendor_refund_with_null_actor_posts_and_links_gl_reversal(): void
    {
        $service = app(VendorRefundService::class);

        $po = $this->createConfirmedPurchaseOrder('1000.00');
        $this->allocatePrepaymentToPo($po, '1000.00');

        $refund = $service->refundPrepayment(
            po: $po,
            amount: '400.00',
            paymentMethodId: $this->cashMethod->id,
            repositoryId: $this->cashRegister->id,
            reason: 'automated cancellation',
            userId: null,
        );

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_advance_refund')
            ->where('source_id', $refund->id)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        $movement = RepositoryMovement::query()
            ->where('payment_repository_id', $this->cashRegister->id)
            ->where('source_type', MovementSourceType::Refund->value)
            ->firstOrFail();
        $this->assertNotNull($movement->journal_entry_id, 'vendor refund movement must carry a JE');
        $this->assertSame($entry->id, $movement->journal_entry_id);
        $this->assertSame($entry->id, $refund->journal_entry_id);
    }

    /**
     * A refund whose repository has NO gl_account_id (cash would move but there is
     * no GL account to post the reversal to) is rejected with a 422 DomainException
     * — it must NEVER record a null-JE cash movement.
     */
    public function test_refund_on_repository_without_gl_account_is_rejected_422(): void
    {
        // Till WITHOUT a gl_account_id.
        $unledgered = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-NOGL',
            'name' => 'Unledgered Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'balance' => '5000.00',
            'gl_account_id' => null,
        ]);

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $unledgered->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-NOGL',
            'created_by' => $this->user->id,
        ]);

        $threw = false;
        try {
            app(PaymentRefundService::class)
                ->refundPayment($payment, 'refund', $this->user->id, Str::uuid()->toString());
        } catch (\DomainException $e) {
            $threw = true;
            $this->assertStringContainsString('no gl_account_id', $e->getMessage());
        }
        $this->assertTrue($threw, 'refund on a repository without a gl_account_id must throw a DomainException (422)');

        // The whole refund transaction rolled back: no refund row, no null-JE movement.
        $refundCount = Payment::query()
            ->where('original_payment_id', $payment->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->count();
        $this->assertSame(0, $refundCount, 'no refund row may be committed');

        $movementCount = RepositoryMovement::query()
            ->where('payment_repository_id', $unledgered->id)
            ->count();
        $this->assertSame(0, $movementCount, 'no null-JE movement may be written');
    }

    /**
     * Final-review fix wave (Fix 1, BLOCKER): VendorRefundService::refundPrepayment
     * must apply the SAME guard as PaymentRefundService — a repository with no
     * gl_account_id has no GL account to post the reversal to, so the vendor
     * refund must be rejected with a 422 DomainException rather than recording a
     * null-journal_entry_id cash movement (Refund is NOT exempt in
     * ReconcileTreasuryCommand::isJournalEntryExempt(), so an unguarded null-JE
     * movement here guarantees a repository freeze at the next
     * `treasury:reconcile`).
     */
    public function test_vendor_refund_on_repository_without_gl_account_is_rejected_422(): void
    {
        // Till WITHOUT a gl_account_id.
        $unledgered = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-NOGL-VENDOR',
            'name' => 'Unledgered Vendor Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'balance' => '5000.00',
            'gl_account_id' => null,
        ]);

        $po = $this->createConfirmedPurchaseOrder('1000.00');
        $this->allocatePrepaymentToPo($po, '1000.00');

        $threw = false;
        try {
            app(VendorRefundService::class)->refundPrepayment(
                po: $po,
                amount: '400.00',
                paymentMethodId: $this->cashMethod->id,
                repositoryId: $unledgered->id,
                reason: 'refund against unledgered till',
                userId: $this->user->id,
            );
        } catch (\DomainException $e) {
            $threw = true;
            $this->assertStringContainsString('no gl_account_id', $e->getMessage());
        }
        $this->assertTrue($threw, 'vendor refund on a repository without a gl_account_id must throw a DomainException (422)');

        // The whole refund transaction rolled back: no refund payment row, no
        // null-JE movement — the freeze-inducing outcome is impossible.
        $refundCount = Payment::query()
            ->where('partner_id', $this->vendor->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->count();
        $this->assertSame(0, $refundCount, 'no vendor refund row may be committed');

        $movementCount = RepositoryMovement::query()
            ->where('payment_repository_id', $unledgered->id)
            ->count();
        $this->assertSame(0, $movementCount, 'no null-JE movement may be written');

        // The PO balance_due was NOT touched by the rolled-back refund.
        $po->refresh();
        $this->assertSame(0, bccomp($po->balance_due, '0.00', 2));
    }

    // Helpers -----------------------------------------------------------------

    /**
     * @param  numeric-string  $total
     */
    private function createConfirmedPurchaseOrder(string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->vendor->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-RS-'.substr((string) Str::uuid(), 0, 8),
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function allocatePrepaymentToPo(Document $po, string $amount): void
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

        // balance_due was just set to a numeric-string total on creation; fall
        // back to total (also numeric-string) defensively for the typed bcsub.
        $currentBalance = $po->balance_due ?? $po->total ?? '0';
        $po->balance_due = bcsub($currentBalance, $amount, 2);
        $po->save();
    }

    /**
     * @param  numeric-string  $amount
     */
    private function createCompletedCustomerPayment(string $amount): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-'.substr((string) Str::uuid(), 0, 8),
            'created_by' => $this->user->id,
        ]);
    }
}
