<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\DTOs\Reports\PaymentMethodBreakdownData;
use App\Modules\Accounting\Application\Services\Reports\SalesReportService;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Treasury\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

/**
 * SalesReportService::paymentMethodBreakdown must report each tender group's
 * NET cash contribution and count TRANSACTIONS, not payment rows.
 *
 * Defects covered (first-tenant launch audit, Lane A; hardened per the
 * treasury-reviewer gate, round 1 — APPROVE-WITH-FIXES, one BLOCKING Critical):
 *  1. The query summed `pos_receipt_payments.amount` (tendered cash) with no
 *     `change_due` subtraction, overstating cash by the change given back on
 *     v3 receipts.
 *  2. `COUNT(*)` counted payment ROWS, not receipts — a split-tender receipt
 *     with two cash legs inflated `transaction_count`.
 *  3. [CRITICAL, caught at review] The v3 `payment_type` snapshot is the
 *     payment METHOD CODE (see PosCoreReceiptProjection::resolvePaymentTypeDisplayName,
 *     which snapshots `method_code`), not a display name. The default owner
 *     report scope is root company + all children (OwnerReportScope::companyIds).
 *     Two sibling companies can each hold their own CASH-coded payment method
 *     under a DIFFERENT `payment_methods.name` ("Cash" vs "Espèces"). The
 *     outer query groups on the PAIR (payment_type, payment_methods.name), so
 *     these land in two separate report rows — but a change-subtraction
 *     subquery keyed on `payment_type` ALONE collapses both companies' change
 *     into one bucket and subtracts the FULL combined total from EVERY row
 *     sharing that code, understating cash in both groups. The fix keys the
 *     change lookup on the byte-identical (payment_type, payment_methods.name)
 *     pair the outer query groups on.
 */
final class SalesReportServicePaymentBreakdownTest extends TestCase
{
    use InteractsWithOwnerReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnerReportingFixtures();
    }

    private function range(): DateRangeData
    {
        return new DateRangeData(CarbonImmutable::parse('2026-06-09'), CarbonImmutable::parse('2026-06-16'));
    }

    public function test_mixed_case_unflagged_method_does_not_have_change_subtracted_as_cash(): void
    {
        $unflaggedMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Legacy Cash Label',
            'code' => 'Cash',
            'is_cash_tender' => false,
        ]);
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 10:00:00',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
            'change_due' => '5.000',
            'training_flag' => false,
        ]);
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $unflaggedMethod->id,
            'payment_type' => 'Cash',
            'amount' => '15.000',
        ]);

        $rows = $this->app->make(SalesReportService::class)->paymentMethodBreakdown(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );
        $row = collect($rows)->first(fn ($candidate) => $candidate->payment_type === 'Cash');

        $this->assertNotNull($row);
        $this->assertTrue(is_numeric($row->amount));
        $this->assertSame(0, bccomp($row->amount, '15.000', 3));
    }

    public function test_payment_method_breakdown_nets_change_correctly_across_groups_and_companies(): void
    {
        // --- Parent company / locationA / cashMethod (code=CASH, name='Cash') ---

        // Cash receipt #1: split tender, TWO cash legs (20.000 + 10.000) in
        // the SAME report group, change_due 15.000. Net contribution: 15.000.
        $cashReceipt1 = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 10:00:00',
            'subtotal' => '15.000',
            'tax_amount' => '0.000',
            'total' => '15.000',
            'change_due' => '15.000',
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $cashReceipt1->id, 'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'CASH', 'amount' => '20.000']);
        ReceiptPayment::create(['receipt_id' => $cashReceipt1->id, 'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'CASH', 'amount' => '10.000']);

        // Cash receipt #2: SECOND receipt in the SAME group — proves
        // cross-receipt aggregation (change from multiple receipts sums
        // correctly, not just multiple rows within one receipt).
        $cashReceipt2 = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 11:00:00',
            'subtotal' => '8.000',
            'tax_amount' => '0.000',
            'total' => '8.000',
            'change_due' => '2.000',
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $cashReceipt2->id, 'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'CASH', 'amount' => '10.000']);

        // Cash receipt #3: change_due is NULL (legacy pre-column row) — must
        // be treated as zero, not error and not swallow the whole group.
        $cashReceiptNullChange = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 12:00:00',
            'subtotal' => '15.000',
            'tax_amount' => '0.000',
            'total' => '15.000',
            'change_due' => null,
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $cashReceiptNullChange->id, 'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'CASH', 'amount' => '15.000']);

        // Voided cash receipt with a LARGE change_due — must be excluded
        // entirely (filter parity): must not appear in any total and must
        // not leak its change_due into the valid cash group.
        $voidedCashReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 13:00:00',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'change_due' => '90.000',
            'is_voided' => true,
            'voided_at' => '2026-06-10 13:01:00',
            'voided_by' => $this->owner->id,
            'void_reason' => 'Test void',
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $voidedCashReceipt->id, 'payment_method_id' => $this->cashMethod->id, 'payment_type' => 'CASH', 'amount' => '100.000']);

        // Card receipt: DIFFERENT report group, untouched by cash netting.
        // Gross (42.000) sits BETWEEN the cash group's gross (55.000) and
        // its correctly-netted amount (38.000) — this proves the result
        // must be re-sorted by NET amount, not the SQL-level gross order.
        $cardMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Card',
            'code' => 'CARD',
        ]);
        $cardReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 14:00:00',
            'subtotal' => '42.000',
            'tax_amount' => '0.000',
            'total' => '42.000',
            'change_due' => '0.000',
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $cardReceipt->id, 'payment_method_id' => $cardMethod->id, 'payment_type' => 'CARD', 'amount' => '42.000']);

        // --- Sibling (child) company: OWN CASH-coded method, DIFFERENT
        // display name ("Espèces"). Default owner scope is root + children
        // (OwnerReportScope::companyIds), so this receipt is in-scope
        // alongside the parent's. It must land in its OWN report row and
        // net its OWN change_due — never absorb, nor be absorbed by, the
        // parent company's "CASH"/"Cash" group.
        $childLocation = Location::factory()->create(['company_id' => $this->childCompany->id, 'name' => 'Child Store']);
        $childTerminal = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->childCompany->id, 'location_id' => $childLocation->id]);
        $childCashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->childCompany->id,
            'name' => 'Espèces',
            'code' => 'CASH',
            'is_cash_tender' => true,
        ]);
        $childCashReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->childCompany->id,
            'location_id' => $childLocation->id,
            'terminal_id' => $childTerminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 10:30:00',
            'subtotal' => '12.000',
            'tax_amount' => '0.000',
            'total' => '12.000',
            'change_due' => '3.000',
            'training_flag' => false,
        ]);
        ReceiptPayment::create(['receipt_id' => $childCashReceipt->id, 'payment_method_id' => $childCashMethod->id, 'payment_type' => 'CASH', 'amount' => '15.000']);

        $rows = $this->app->make(SalesReportService::class)->paymentMethodBreakdown(
            $this->range(), [$this->company->id, $this->childCompany->id], [$this->locationA->id, $childLocation->id],
        );

        $rowsList = collect($rows)->values();
        $this->assertCount(3, $rowsList, 'Voided receipt must not create/inflate any group; exactly 3 groups expected (parent cash, card, child cash).');

        $parentCashRow = $rowsList->first(fn ($r) => $r->payment_type === 'CASH' && $r->payment_method_name === 'Cash');
        $childCashRow = $rowsList->first(fn ($r) => $r->payment_type === 'CASH' && $r->payment_method_name === 'Espèces');
        $cardRow = $rowsList->first(fn ($r) => $r->payment_type === 'CARD');

        $this->assertNotNull($parentCashRow, 'Parent cash group ("CASH"/"Cash") must be present.');
        $this->assertNotNull($childCashRow, 'Child cash group ("CASH"/"Espèces") must be its OWN row, distinct from the parent\'s.');
        $this->assertNotNull($cardRow, 'Card group must be present.');

        // (a)+(critical) Composite-key netting: parent group nets ONLY its
        // own three receipts' change (15 + 2 + 0 = 17) — NOT the child
        // company's 3.000. Tendered 20+10+10+15=55; net = 55-17 = 38.
        $this->assertSame(38.0, (float) $parentCashRow->amount, 'Parent cash group must net only its own change_due, not the sibling company\'s.');
        $this->assertSame(3, $parentCashRow->transaction_count, 'Parent cash group must count 3 receipts (the voided one is excluded).');

        // (critical) Child group nets ONLY its own change (3.000), not the
        // parent's combined 17.000. Tendered 15; net = 15-3 = 12.
        $this->assertSame(12.0, (float) $childCashRow->amount, 'Child cash group must net only its own change_due, not the parent company\'s.');
        $this->assertSame(1, $childCashRow->transaction_count);

        // Card group entirely unaffected by any cash netting.
        $this->assertSame(42.0, (float) $cardRow->amount, 'Card group must not receive any change_due subtraction.');
        $this->assertSame(1, $cardRow->transaction_count);

        // (4) Result order reflects NET amounts, not SQL-level gross order:
        // card (42.0) must sort ABOVE the cash group (38.0), even though the
        // cash group's GROSS (55.0) was higher before netting.
        $sortedPaymentTypes = $rowsList
            ->map(static fn (PaymentMethodBreakdownData $row): string => $row->payment_type)
            ->take(2)
            ->values()
            ->all();
        $this->assertSame(['CARD', 'CASH'], $sortedPaymentTypes, 'Rows must be re-sorted by net amount after PHP-side change netting.');
    }
}
