<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\SalesReportService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

/**
 * SalesReportService::paymentMethodBreakdown must report each tender group's
 * NET cash contribution and count TRANSACTIONS, not payment rows.
 *
 * Two pre-existing defects (first-tenant launch audit, Lane A):
 *  1. The query sums `pos_receipt_payments.amount` (tendered cash) with no
 *     `change_due` subtraction, overstating cash by the change given back on
 *     v3 receipts.
 *  2. `COUNT(*)` counts payment ROWS, not receipts — a split-tender receipt
 *     with two cash legs inflates `transaction_count`.
 *
 * This fixture pins BOTH defects at once with a receipt carrying TWO cash
 * payment rows in the SAME report group (same payment_type/payment_method
 * pairing) plus a card receipt in a different group, so we can also prove
 * the fix does not leak the cash change-due subtraction into the card group.
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

    public function test_cash_group_nets_change_due_once_and_counts_transactions_not_rows(): void
    {
        // Cash receipt: total 25.000, tendered 30.000 across TWO cash payment
        // rows (20.000 + 10.000), 5.000 change given back. Net cash
        // contribution to the till must be 25.000, and this is ONE
        // transaction even though it has two payment rows.
        $cashReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->locationA->company_id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-06-10 10:00:00',
            'subtotal' => '25.000',
            'tax_amount' => '0.000',
            'total' => '25.000',
            'change_due' => '5.000',
            'training_flag' => false,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $cashReceipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => '20.000',
        ]);

        ReceiptPayment::create([
            'receipt_id' => $cashReceipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => '10.000',
        ]);

        // Card receipt in a DIFFERENT report group: must be entirely
        // unaffected by the cash change-due subtraction.
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
            'posted_at' => '2026-06-11 10:00:00',
            'subtotal' => '40.000',
            'tax_amount' => '0.000',
            'total' => '40.000',
            'change_due' => '0.000',
            'training_flag' => false,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $cardReceipt->id,
            'payment_method_id' => $cardMethod->id,
            'payment_type' => 'card',
            'amount' => '40.000',
        ]);

        $rows = $this->app->make(SalesReportService::class)->paymentMethodBreakdown(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $cashRow = collect($rows)->firstWhere('payment_type', 'cash');
        $cardRow = collect($rows)->firstWhere('payment_type', 'card');

        $this->assertNotNull($cashRow, 'Cash group must be present.');
        $this->assertNotNull($cardRow, 'Card group must be present.');

        // (a) Change subtracted exactly ONCE per receipt: 20 + 10 - 5 = 25,
        // NOT 30 (bug: no subtraction) and NOT 20 (bug: subtracted once per
        // cash payment ROW instead of once per receipt).
        $this->assertSame(25.0, (float) $cashRow->amount, 'Cash group must net change_due exactly once per receipt.');

        // (b) Transaction count = 1 receipt, not 2 payment rows.
        $this->assertSame(1, $cashRow->transaction_count, 'Cash group must count DISTINCT receipts, not payment rows.');

        // (c) Card group is untouched by the cash change-due subtraction.
        $this->assertSame(40.0, (float) $cardRow->amount, 'Card group must not receive any change_due subtraction.');
        $this->assertSame(1, $cardRow->transaction_count);
    }
}
