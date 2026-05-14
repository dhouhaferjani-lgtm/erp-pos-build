<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\ReceiptVoidService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Privileged-action audit-log DISPATCH + PERSISTENCE coverage (T1, dev deferred backlog).
 *
 * The companion {@see PrivilegedAuditLogTest} only checks — via reflection —
 * that the event classes carry the right constructor fields. It never proves
 * the event is dispatched at the callsite, nor that a listener persists it.
 *
 * This suite closes that gap for the Treasury refund/reversal privileged
 * actions, which were dispatched by PaymentRefundService but NOT wired into
 * DomainEventSubscriber::subscribe() — meaning every production refund and
 * reversal fell into the void with no audit_events row.
 *
 * Two layers of coverage per action:
 *  1. Wiring: fire the domain event directly and assert DomainEventSubscriber
 *     persisted an audit_events row with tenant/company/actor/action/target.
 *  2. End-to-end: call the real service (which dispatches inside
 *     DB::afterCommit) with NO Event::fake, and assert the row lands. This
 *     also proves DB::afterCommit callbacks DO fire under RefreshDatabase in
 *     Laravel 12 (nested-transaction commit triggers them), so the afterCommit
 *     audit path needs no special test harness.
 *
 * The ReceiptVoidService case is included as a control: ReceiptVoided was
 * already wired, so its e2e test should be green from the start and confirms
 * the afterCommit→subscriber→persist chain for the receipt-void path.
 */
class PrivilegedAuditLogDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Audit Dispatch Tenant',
            'slug' => 'audit-dispatch-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Audit Dispatch Co',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Audit Actor',
            'email' => 'audit-actor-'.Str::random(8).'@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Audit Partner',
            'type' => 'customer',
            'is_active' => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'fee_fixed' => '0.000',
            'fee_percent' => '0.000',
            'is_active' => true,
            'position' => 1,
        ]);

        // Authenticated actor — DomainEventSubscriber stamps the audit row's
        // user_id from Auth::id() and resolves tenant_id from Auth::user().
        $this->actingAs($this->user, 'sanctum');
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // ---------------------------------------------------------------------
    // PaymentRefunded
    // ---------------------------------------------------------------------

    public function test_payment_refunded_event_is_persisted_by_subscriber(): void
    {
        $refundId = Str::uuid()->toString();
        $originalId = Str::uuid()->toString();

        event(new PaymentRefunded(
            paymentId: $refundId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            originalPaymentId: $originalId,
            amount: '-50.000',
            currency: 'TND',
            reason: 'Customer request',
            refundedAt: now()->toIso8601String(),
        ));

        $audit = AuditEvent::where('aggregate_id', $refundId)
            ->where('event_type', 'treasury.payment.refunded')
            ->first();

        $this->assertNotNull($audit, 'PaymentRefunded must be persisted to audit_events by DomainEventSubscriber.');
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('Payment', $audit->aggregate_type);
        $this->assertSame($refundId, $audit->aggregate_id);
        $this->assertTrue(
            $audit->occurred_at->greaterThan(now()->subMinute()),
            'Audit row must carry a fresh occurred_at timestamp.',
        );
        $this->assertSame($originalId, $audit->payload['original_payment_id']);
        $this->assertSame('-50.000', $audit->payload['amount']);
        $this->assertSame('TND', $audit->payload['currency']);
        $this->assertSame('Customer request', $audit->payload['reason']);
    }

    public function test_refund_payment_service_persists_audit_event_end_to_end(): void
    {
        $payment = $this->createCompletedPayment('120.000');

        $refund = app(PaymentRefundService::class)
            ->refundPayment($payment, 'Defective item', $this->user->id);

        $audit = AuditEvent::where('aggregate_id', $refund->id)
            ->where('event_type', 'treasury.payment.refunded')
            ->first();

        $this->assertNotNull(
            $audit,
            'PaymentRefundService::refundPayment must leave an audit_events row '
            .'(dispatch inside DB::afterCommit must reach the subscriber).',
        );
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame($payment->id, $audit->payload['original_payment_id']);
        $this->assertSame('Defective item', $audit->payload['reason']);
    }

    public function test_partial_refund_service_persists_audit_event_end_to_end(): void
    {
        $payment = $this->createCompletedPayment('120.000');

        $refund = app(PaymentRefundService::class)
            ->partialRefund($payment, '40.000', 'Partial goodwill', $this->user->id);

        $audit = AuditEvent::where('aggregate_id', $refund->id)
            ->where('event_type', 'treasury.payment.refunded')
            ->first();

        $this->assertNotNull(
            $audit,
            'PaymentRefundService::partialRefund must leave an audit_events row.',
        );
        $this->assertSame($payment->id, $audit->payload['original_payment_id']);
        $this->assertSame('Partial goodwill', $audit->payload['reason']);
    }

    // ---------------------------------------------------------------------
    // PaymentReversed
    // ---------------------------------------------------------------------

    public function test_payment_reversed_event_is_persisted_by_subscriber(): void
    {
        $paymentId = Str::uuid()->toString();

        event(new PaymentReversed(
            paymentId: $paymentId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: '75.000',
            currency: 'TND',
            reversedAt: now()->toIso8601String(),
        ));

        $audit = AuditEvent::where('aggregate_id', $paymentId)
            ->where('event_type', 'treasury.payment.reversed')
            ->first();

        $this->assertNotNull($audit, 'PaymentReversed must be persisted to audit_events by DomainEventSubscriber.');
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('Payment', $audit->aggregate_type);
        $this->assertSame($paymentId, $audit->aggregate_id);
        $this->assertSame('75.000', $audit->payload['amount']);
        $this->assertSame('TND', $audit->payload['currency']);
    }

    public function test_reverse_payment_service_persists_audit_event_end_to_end(): void
    {
        $payment = $this->createCompletedPayment('90.000');

        app(PaymentRefundService::class)
            ->reversePayment($payment, 'Posting error', $this->user->id);

        $audit = AuditEvent::where('aggregate_id', $payment->id)
            ->where('event_type', 'treasury.payment.reversed')
            ->first();

        $this->assertNotNull(
            $audit,
            'PaymentRefundService::reversePayment must leave an audit_events row.',
        );
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('90.000', $audit->payload['amount']);
    }

    // ---------------------------------------------------------------------
    // ReceiptVoided — control: already wired; proves the afterCommit chain
    // works for the receipt-void path the plan called out by name.
    // ---------------------------------------------------------------------

    public function test_receipt_void_service_persists_audit_event_end_to_end(): void
    {
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        $terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'code' => 'POS01',
            'name' => 'Audit Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);

        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'POS01-2026-00000001',
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'audit-void-receipt'),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Audit Actor',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
            'is_voided' => false,
        ]);

        app(ReceiptVoidService::class)->voidReceipt($receipt, $this->user, 'Audit void test');

        $audit = AuditEvent::where('aggregate_id', $receipt->id)
            ->where('event_type', 'receipt.voided')
            ->first();

        $this->assertNotNull(
            $audit,
            'ReceiptVoidService::voidReceipt must leave an audit_events row '
            .'(DB::afterCommit dispatch must reach the subscriber under RefreshDatabase).',
        );
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('Receipt', $audit->aggregate_type);
        $this->assertSame('Audit void test', $audit->payload['void_reason']);
        $this->assertSame($this->user->id, $audit->payload['voided_by']);
    }

    private function createCompletedPayment(string $amount): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PAY-'.Str::random(8),
        ]);
    }
}
