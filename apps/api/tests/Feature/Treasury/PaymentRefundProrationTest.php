<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RefundAllocation;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Shared\Domain\CurrencyScale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PaymentRefundProrationTest
 *
 * Tests for Task 19:
 * - refundReceiptPayments() proration strategies (Proportional, LargestFirst, CashierChoice)
 * - DB-level idempotency via unique partial index
 * - Residual-to-last rounding for EUR (scale 2) and TND (scale 3)
 * - payment_type explicitly set to Refund on all refund rows
 * - Audit column propagation (original_payment_id, refund_request_id, authorized_by_user_id)
 * - Backfill migration upgrades legacy rows with wrong payment_type
 * - Deterministic ordering tiebreaker (amount DESC, id ASC)
 * - existing refundPayment / partialRefund methods now set payment_type = Refund
 */
class PaymentRefundProrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    private Partner $customer;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Proration Test Tenant',
            'slug' => 'proration-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Proration Test Company',
            'legal_name' => 'Proration Test Company LLC',
            'tax_id' => 'TAX-PRORATION',
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
            'name' => 'Proration User',
            'email' => 'proration@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_PRO',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->cardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD_PRO',
            'name' => 'Card',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Proration Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal fiscalized Receipt + link two Treasury Payment rows to it.
     *
     * Returns [Receipt, Payment $cardPayment, Payment $cashPayment].
     *
     * @return array{Receipt, Payment, Payment}
     */
    private function makeSaleReceiptWithTwoPayments(
        string $cardAmount,
        string $cashAmount,
        string $currency = 'EUR'
    ): array {
        // Build a terminal + location that the receipt needs
        $terminal = $this->makeTerminal($currency);

        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T001-'.Str::random(8),
            'chain_sequence' => rand(1, 99999),
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', Str::uuid()->toString()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', Str::uuid()->toString()),
            'payment_methods_hash' => hash('sha256', Str::uuid()->toString()),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => bcadd($cardAmount, $cashAmount, 3),
            'tax_amount' => '0.000',
            'total' => bcadd($cardAmount, $cashAmount, 3),
            'currency' => $currency,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ]);

        // Create treasury Payment rows
        $cardPayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cardMethod->id,
            'amount' => $cardAmount,
            'currency' => $currency,
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'reference' => 'PMT-CARD-'.Str::random(6),
        ]);

        $cashPayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $cashAmount,
            'currency' => $currency,
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'reference' => 'PMT-CASH-'.Str::random(6),
        ]);

        // Link via pos_receipt_payments (treasury_payment_id column)
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cardMethod->id,
            'payment_type' => 'card',
            'amount' => $cardAmount,
            'treasury_payment_id' => $cardPayment->id,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => $cashAmount,
            'treasury_payment_id' => $cashPayment->id,
        ]);

        return [$receipt, $cardPayment, $cashPayment];
    }

    /**
     * Build a minimal terminal with location, or return cached.
     */
    private function makeTerminal(string $currency = 'EUR'): Terminal
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Test Location '.$currency,
            'type' => 'shop',
            'is_active' => true,
        ]);

        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'name' => 'Terminal '.$currency,
            'code' => 'POS-'.Str::random(4).'-'.$currency,
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);
    }

    // -------------------------------------------------------------------------
    // Proportional proration
    // -------------------------------------------------------------------------

    public function test_proportional_proration_across_two_original_payments(): void
    {
        // €100 sale: €60 card + €40 cash. Refund €50 proportional.
        // Share card: 60/100 × 50 = 30.00
        // Share cash: 40/100 × 50 = 20.00
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid = Str::uuid()->toString();
        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        $this->assertCount(2, $allocations);

        $byOriginal = collect($allocations)->keyBy('originalPaymentId');

        $this->assertEquals('30.00', $byOriginal[$cardPayment->id]->amount);
        $this->assertEquals('20.00', $byOriginal[$cashPayment->id]->amount);

        // Verify DB rows have negative amounts
        $cardRefundRow = Payment::find($byOriginal[$cardPayment->id]->paymentId);
        $this->assertNotNull($cardRefundRow);
        $this->assertEquals('-30.00', CurrencyScale::bcformat($cardRefundRow->amount, 2));

        $cashRefundRow = Payment::find($byOriginal[$cashPayment->id]->paymentId);
        $this->assertNotNull($cashRefundRow);
        $this->assertEquals('-20.00', CurrencyScale::bcformat($cashRefundRow->amount, 2));
    }

    // -------------------------------------------------------------------------
    // LargestFirst proration
    // -------------------------------------------------------------------------

    public function test_largest_first_drains_largest_payment_first(): void
    {
        // €100 sale: €60 card + €40 cash.
        // Refund €50 LargestFirst: card is largest → -€50 from card only; cash untouched.
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid1 = Str::uuid()->toString();
        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::LargestFirst,
            refundRequestId: $rid1,
        );

        $byOriginal = collect($allocations)->keyBy('originalPaymentId');

        $this->assertArrayHasKey($cardPayment->id, $byOriginal->toArray());
        $this->assertEquals('50.00', $byOriginal[$cardPayment->id]->amount);
        // Cash untouched — should not appear
        $this->assertArrayNotHasKey($cashPayment->id, $byOriginal->toArray());

        // Refund another €30 LargestFirst (card already partially drained — only €10 left, then €20 from cash)
        $rid2 = Str::uuid()->toString();
        $allocations2 = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '30.00',
            strategy: ProrationStrategy::LargestFirst,
            refundRequestId: $rid2,
        );

        // After first refund, card has €60-€50=€10 left; cash has €40 left
        // LargestFirst on second call: card is still larger (€60 original) → drain card first
        // We drain €10 from card, then €20 from cash
        $byOriginal2 = collect($allocations2)->keyBy('originalPaymentId');

        $this->assertArrayHasKey($cardPayment->id, $byOriginal2->toArray());
        $this->assertArrayHasKey($cashPayment->id, $byOriginal2->toArray());
        $this->assertEquals('10.00', $byOriginal2[$cardPayment->id]->amount);
        $this->assertEquals('20.00', $byOriginal2[$cashPayment->id]->amount);
    }

    // -------------------------------------------------------------------------
    // CashierChoice proration
    // -------------------------------------------------------------------------

    public function test_cashier_choice_writes_explicit_allocations(): void
    {
        // €100 sale: €60 card + €40 cash. Refund card€20, cash€30.
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid = Str::uuid()->toString();
        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::CashierChoice,
            refundRequestId: $rid,
            cashierAllocations: [
                $cardPayment->id => '20.00',
                $cashPayment->id => '30.00',
            ],
        );

        $byOriginal = collect($allocations)->keyBy('originalPaymentId');

        $this->assertEquals('20.00', $byOriginal[$cardPayment->id]->amount);
        $this->assertEquals('30.00', $byOriginal[$cashPayment->id]->amount);
    }

    public function test_cashier_choice_throws_when_allocations_null(): void
    {
        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cashierAllocations must be provided for CashierChoice strategy');

        $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::CashierChoice,
            refundRequestId: Str::uuid()->toString(),
            cashierAllocations: null,
        );
    }

    // -------------------------------------------------------------------------
    // Residual-to-last rounding
    // -------------------------------------------------------------------------

    public function test_residual_to_last_for_eur(): void
    {
        // €0.01 refund split proportionally across 3 original payments at €10 each.
        // floor(0.01 / 3) = €0.00 for first two; €0.01 for the last (deterministic order).
        $terminal = $this->makeTerminal('EUR');
        $locationId = $terminal->location_id;

        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $locationId,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T-RESIDUAL-EUR',
            'chain_sequence' => rand(1, 99999),
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', Str::uuid()->toString()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', Str::uuid()->toString()),
            'payment_methods_hash' => hash('sha256', Str::uuid()->toString()),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ]);

        // Three equal payments of €10, inserted with increasing IDs (id ASC tiebreaker)
        $payments = [];
        for ($i = 0; $i < 3; $i++) {
            $p = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '10.00',
                'currency' => 'EUR',
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::POS,
                'reference' => "PMT-RESIDUAL-{$i}-".Str::random(4),
            ]);
            ReceiptPayment::create([
                'receipt_id' => $receipt->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'cash',
                'amount' => '10.00',
                'treasury_payment_id' => $p->id,
            ]);
            $payments[] = $p;
        }

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '0.01',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        // All 3 payments have same amount (€10) → sort by amount DESC then id ASC
        // Proportional: floor(10/30 × 0.01) = floor(0.003...) = €0.00 for each
        // Residual €0.01 → last payment in order (lowest id has highest rank due to ASC)
        // Only the last one in iteration order gets the residual
        $total = array_reduce(
            $allocations,
            fn (string $carry, RefundAllocation $a) => bcadd($carry, $a->amount, 2),
            '0.00'
        );

        $this->assertEquals('0.01', $total, 'Sum of allocations must equal totalToRefund');

        // Only one allocation has a non-zero amount (the residual one)
        $nonZero = array_filter($allocations, fn (RefundAllocation $a) => bccomp($a->amount, '0.00', 2) > 0);
        $this->assertCount(1, $nonZero, 'Only one allocation should carry the residual cent');
        $this->assertEquals('0.01', array_values($nonZero)[0]->amount);
    }

    public function test_residual_to_last_for_tnd(): void
    {
        // TND scale-3: 0.001 refund split across 3 equal payments of 10.000 TND each
        $terminal = $this->makeTerminal('TND');
        $tndLocationId = $terminal->location_id;

        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $tndLocationId,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T-RESIDUAL-TND',
            'chain_sequence' => rand(1, 99999),
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', Str::uuid()->toString()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', Str::uuid()->toString()),
            'payment_methods_hash' => hash('sha256', Str::uuid()->toString()),
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'TND',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $p = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '10.000',
                'currency' => 'TND',
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::POS,
                'reference' => "PMT-TND-{$i}-".Str::random(4),
            ]);
            ReceiptPayment::create([
                'receipt_id' => $receipt->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'cash',
                'amount' => '10.000',
                'treasury_payment_id' => $p->id,
            ]);
        }

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '0.001',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $total = array_reduce(
            $allocations,
            fn (string $carry, RefundAllocation $a) => bcadd($carry, $a->amount, 3),
            '0.000'
        );

        $this->assertEquals('0.001', $total, 'Sum of TND allocations must equal totalToRefund');

        $nonZero = array_filter($allocations, fn (RefundAllocation $a) => bccomp($a->amount, '0.000', 3) > 0);
        $this->assertCount(1, $nonZero);
        $this->assertEquals('0.001', array_values($nonZero)[0]->amount);
    }

    // -------------------------------------------------------------------------
    // payment_type = Refund explicitly
    // -------------------------------------------------------------------------

    public function test_payment_type_is_set_to_refund_explicitly(): void
    {
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        foreach ($allocations as $allocation) {
            $row = Payment::find($allocation->paymentId);
            $this->assertNotNull($row, 'Refund payment row must exist');
            $this->assertSame(
                PaymentType::Refund,
                $row->payment_type,
                'payment_type must be PaymentType::Refund, NOT the column default document_payment'
            );
        }
    }

    public function test_existing_refund_payment_method_also_sets_payment_type_to_refund(): void
    {
        // Verify the bug fix on the existing refundPayment() method (not just the new proration)
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-LEGACY-'.Str::random(6),
        ]);

        $refund = $this->refundService->refundPayment($payment, 'test', $this->user->id);

        $this->assertSame(PaymentType::Refund, $refund->payment_type);
    }

    public function test_partial_refund_sets_payment_type_to_refund(): void
    {
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-PARTIAL-'.Str::random(6),
        ]);

        $refund = $this->refundService->partialRefund($payment, '40.00', 'partial test', $this->user->id);

        $this->assertSame(PaymentType::Refund, $refund->payment_type);
    }

    // -------------------------------------------------------------------------
    // DB-level idempotency
    // -------------------------------------------------------------------------

    public function test_db_level_idempotency_same_request_id_returns_same_allocations(): void
    {
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid = Str::uuid()->toString();

        // First call — succeeds and writes rows
        $first = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        // Second call with the same refund_request_id — must not insert duplicates
        $second = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        // Total refund payment rows in DB for this request_id must be exactly 2 (not 4)
        $count = Payment::where('refund_request_id', $rid)
            ->where('payment_type', PaymentType::Refund->value)
            ->count();

        $this->assertEquals(2, $count, 'Exactly 2 refund rows must exist — idempotency prevents duplicates');

        // Both return values must carry the same payment IDs
        $firstIds = collect($first)->pluck('paymentId')->sort()->values()->all();
        $secondIds = collect($second)->pluck('paymentId')->sort()->values()->all();

        $this->assertEquals($firstIds, $secondIds, 'Idempotent call must return the same payment IDs');
    }

    public function test_different_request_ids_produce_separate_row_sets(): void
    {
        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid1 = Str::uuid()->toString();
        $rid2 = Str::uuid()->toString();

        $alloc1 = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '20.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid1,
        );

        $alloc2 = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '10.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid2,
        );

        // 4 distinct rows
        $ids1 = collect($alloc1)->pluck('paymentId')->all();
        $ids2 = collect($alloc2)->pluck('paymentId')->all();

        $this->assertEmpty(
            array_intersect($ids1, $ids2),
            'Two different refund_request_ids must produce distinct payment rows'
        );
    }

    // -------------------------------------------------------------------------
    // Audit column propagation
    // -------------------------------------------------------------------------

    public function test_authorized_by_user_id_propagated_to_refund_rows(): void
    {
        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('pass'),
            'status' => UserStatus::Active,
        ]);

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
            authorizedByUserId: $manager->id,
        );

        foreach ($allocations as $allocation) {
            $row = Payment::find($allocation->paymentId);
            $this->assertEquals($manager->id, $row->authorized_by_user_id);
        }
    }

    public function test_refund_request_id_propagated_to_refund_rows(): void
    {
        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $rid = Str::uuid()->toString();

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        foreach ($allocations as $allocation) {
            $row = Payment::find($allocation->paymentId);
            $this->assertEquals($rid, $row->refund_request_id);
        }
    }

    public function test_original_payment_id_links_each_refund_row_to_its_source(): void
    {
        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $validOriginalIds = [$cardPayment->id, $cashPayment->id];

        foreach ($allocations as $allocation) {
            $this->assertContains(
                $allocation->originalPaymentId,
                $validOriginalIds,
                'original_payment_id must reference an existing payment row'
            );
            $row = Payment::find($allocation->paymentId);
            $this->assertEquals($allocation->originalPaymentId, $row->original_payment_id);
        }
    }

    public function test_policy_trigger_propagated_to_refund_rows(): void
    {
        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
            policyTrigger: 'over_threshold',
        );

        foreach ($allocations as $allocation) {
            $row = Payment::find($allocation->paymentId);
            $this->assertEquals('over_threshold', $row->policy_trigger);
        }
    }

    // -------------------------------------------------------------------------
    // Deterministic ordering tiebreaker
    // -------------------------------------------------------------------------

    public function test_largest_first_uses_deterministic_ordering_on_amount_ties(): void
    {
        // Two original payments at €50 each. Refund €30 LargestFirst.
        // Tiebreaker is id ASC → lower-id payment is drained first.
        [$receipt, $payment1, $payment2] = $this->makeSaleReceiptWithTwoPayments('50.00', '50.00');

        // Ensure payment1 has a lower id than payment2 (UUID ordering)
        // We rely on makeSaleReceiptWithTwoPayments to create them in order (card first, cash second)
        $lowerIdPayment = strcmp($payment1->id, $payment2->id) < 0 ? $payment1 : $payment2;

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '30.00',
            strategy: ProrationStrategy::LargestFirst,
            refundRequestId: Str::uuid()->toString(),
        );

        $byOriginal = collect($allocations)->keyBy('originalPaymentId');

        // The payment with the lower UUID (ASC tiebreaker) must be drained first
        $this->assertArrayHasKey($lowerIdPayment->id, $byOriginal->toArray());
        $this->assertEquals('30.00', $byOriginal[$lowerIdPayment->id]->amount);
    }

    // -------------------------------------------------------------------------
    // Backfill migration
    // -------------------------------------------------------------------------

    public function test_existing_refund_payments_get_payment_type_via_backfill_migration(): void
    {
        // Pre-create a credit-note document and a linked Payment row with the wrong type
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-BACKFILL-0001',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '-100.000',
            'tax_amount' => '0.000',
            'total' => '-100.000',
            'balance_due' => '0.000',
            'currency' => 'EUR',
        ]);

        // Simulate a legacy negative payment with wrong payment_type = document_payment
        $legacyRefundPayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '-100.000',  // negative = refund
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,  // BUG: should have been Refund
            'reference' => 'PMT-LEGACY-REFUND-'.Str::random(6),
        ]);

        // Link it to the credit note via payment_allocations
        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $legacyRefundPayment->id,
            'document_id' => $creditNote->id,
            'amount' => '-100.000',
        ]);

        // Verify pre-condition: payment_type is the wrong default
        $this->assertEquals(
            PaymentType::DocumentPayment,
            $legacyRefundPayment->payment_type,
            'Pre-condition: legacy row must have the wrong type'
        );

        // Run the backfill migration SQL directly (equivalent to running the migration)
        DB::statement(
            <<<'SQL'
            UPDATE payments
            SET payment_type = 'refund'
            WHERE payment_type = 'document_payment'
              AND CAST(amount AS NUMERIC) < 0
              AND EXISTS (
                  SELECT 1
                  FROM payment_allocations pa
                  JOIN documents d ON d.id = pa.document_id
                  WHERE pa.payment_id = payments.id
                    AND d.type = 'credit_note'
              )
            SQL
        );

        // Assert the row was updated
        $legacyRefundPayment->refresh();
        $this->assertSame(
            PaymentType::Refund,
            $legacyRefundPayment->payment_type,
            'Backfill must re-type negative credit-note payments to Refund'
        );

        // Idempotency: running again must not fail or change anything
        DB::statement(
            <<<'SQL'
            UPDATE payments
            SET payment_type = 'refund'
            WHERE payment_type = 'document_payment'
              AND CAST(amount AS NUMERIC) < 0
              AND EXISTS (
                  SELECT 1
                  FROM payment_allocations pa
                  JOIN documents d ON d.id = pa.document_id
                  WHERE pa.payment_id = payments.id
                    AND d.type = 'credit_note'
              )
            SQL
        );

        $legacyRefundPayment->refresh();
        $this->assertSame(PaymentType::Refund, $legacyRefundPayment->payment_type, 'Idempotent run must keep the type');
    }

    // -------------------------------------------------------------------------
    // Audit-event dispatch (G3) — refundReceiptPayments() must leave an
    // audit_events row per refund allocation, like refundPayment/partialRefund.
    // -------------------------------------------------------------------------

    public function test_proration_refund_dispatches_one_audit_event_per_allocation(): void
    {
        // Authenticated actor — DomainEventSubscriber stamps the audit row's
        // user_id from Auth::id() and tenant_id from Auth::user().
        $this->actingAs($this->user, 'sanctum');

        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
        );

        $this->assertCount(2, $allocations);

        $refundPaymentIds = collect($allocations)->pluck('paymentId')->all();
        $auditRows = AuditEvent::whereIn('aggregate_id', $refundPaymentIds)
            ->where('event_type', 'treasury.payment.refunded')
            ->get();

        $this->assertCount(
            2,
            $auditRows,
            'refundReceiptPayments must leave one treasury.payment.refunded audit_events row per refund allocation.',
        );

        foreach ($auditRows as $audit) {
            $this->assertSame($this->tenant->id, $audit->tenant_id);
            $this->assertSame($this->company->id, $audit->company_id);
            $this->assertSame($this->user->id, $audit->user_id);
            $this->assertSame('Payment', $audit->aggregate_type);
        }
    }

    public function test_proration_refund_audit_event_carries_original_payment_amount_and_reason(): void
    {
        $this->actingAs($this->user, 'sanctum');

        [$receipt, $cardPayment, $cashPayment] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');

        $allocations = $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: Str::uuid()->toString(),
            policyTrigger: 'over_threshold',
        );

        $byOriginal = collect($allocations)->keyBy('originalPaymentId');

        $cardAudit = AuditEvent::where('aggregate_id', $byOriginal[$cardPayment->id]->paymentId)
            ->where('event_type', 'treasury.payment.refunded')
            ->first();

        $this->assertNotNull($cardAudit, 'Each refund allocation must have a matching audit_events row.');
        $this->assertSame($cardPayment->id, $cardAudit->payload['original_payment_id']);
        // Refund rows carry negative amounts; the €60 card share of a €50 refund is -30.00.
        $this->assertSame('-30.00', CurrencyScale::bcformat($cardAudit->payload['amount'], 2));
        $this->assertSame('EUR', $cardAudit->payload['currency']);
        // No $reason param on refundReceiptPayments — the policy trigger stands in.
        $this->assertSame('over_threshold', $cardAudit->payload['reason']);
        $this->assertArrayHasKey('refunded_at', $cardAudit->payload);
    }

    public function test_idempotent_proration_replay_does_not_redispatch_audit_events(): void
    {
        $this->actingAs($this->user, 'sanctum');

        [$receipt] = $this->makeSaleReceiptWithTwoPayments('60.00', '40.00');
        $rid = Str::uuid()->toString();

        $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        // Idempotent replay — same refund_request_id returns the existing rows
        // without creating new ones, so it must not re-dispatch audit events.
        $this->refundService->refundReceiptPayments(
            originalReceipt: $receipt,
            totalToRefund: '50.00',
            strategy: ProrationStrategy::Proportional,
            refundRequestId: $rid,
        );

        $refundPaymentIds = Payment::where('refund_request_id', $rid)
            ->where('payment_type', PaymentType::Refund->value)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $refundPaymentIds, 'Idempotency must keep exactly 2 refund rows.');
        $this->assertSame(
            2,
            AuditEvent::whereIn('aggregate_id', $refundPaymentIds)
                ->where('event_type', 'treasury.payment.refunded')
                ->count(),
            'An idempotent proration replay must not produce duplicate audit_events rows.',
        );
    }

    public function test_positive_amount_document_payment_is_not_backfilled(): void
    {
        // A positive document_payment (correct) should NOT be re-typed
        $normalPayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.000',  // positive
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-NORMAL-'.Str::random(6),
        ]);

        DB::statement(
            <<<'SQL'
            UPDATE payments
            SET payment_type = 'refund'
            WHERE payment_type = 'document_payment'
              AND CAST(amount AS NUMERIC) < 0
              AND EXISTS (
                  SELECT 1
                  FROM payment_allocations pa
                  JOIN documents d ON d.id = pa.document_id
                  WHERE pa.payment_id = payments.id
                    AND d.type = 'credit_note'
              )
            SQL
        );

        $normalPayment->refresh();
        $this->assertSame(
            PaymentType::DocumentPayment,
            $normalPayment->payment_type,
            'Positive document_payment rows must not be touched by the backfill'
        );
    }
}
