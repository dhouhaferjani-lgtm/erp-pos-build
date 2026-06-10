<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\ReceiptVoidService;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Unit tests for ReceiptVoidService
 *
 * Verifies:
 * - Receipt is marked as voided with metadata
 * - Stock movements are reversed (inventory returned)
 * - Cash drawer REFUND operation is created
 * - Already-voided receipt throws exception
 */
class ReceiptVoidServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private ReceiptVoidService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReceiptVoidService(
            $this->app->make(CashDrawerService::class),
            $this->mockCurrencyScale(3),
        );

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
    }

    public function test_voiding_marks_receipt_as_voided(): void
    {
        $receipt = $this->createReceipt();

        $result = $this->service->voidReceipt($receipt, $this->cashier, 'Customer request');

        $this->assertTrue($result->is_voided);
        $this->assertNotNull($result->voided_at);
        $this->assertEquals($this->cashier->id, $result->voided_by);
        $this->assertEquals('Customer request', $result->void_reason);
    }

    public function test_already_voided_receipt_throws_exception(): void
    {
        // A voided row must carry voided_at AND voided_by to satisfy the
        // pos_receipts_void_logic CHECK constraint (enforced by PostgreSQL).
        $receipt = $this->createReceipt([
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by' => $this->cashier->id,
            'fiscal_status' => FiscalStatus::Voided,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Receipt is already voided');

        $this->service->voidReceipt($receipt, $this->cashier, 'Double void');
    }

    public function test_voiding_reverses_stock_movements(): void
    {
        $receipt = $this->createReceipt();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create a receipt line
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.00',
            'unit_price' => '50.00',
            'line_total' => '100.00',
            'tax_rate' => '19.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
        ]);

        // Create a stock level for the product
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '8.00',
            'reserved' => '0.00',
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'Void test');

        // Verify stock was incremented back
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals('10.0000', $stockLevel->quantity);

        // Verify stock movement was created
        $movement = StockMovement::where('reference_type', 'pos_receipt_void')
            ->where('reference_id', $receipt->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals('2.0000', $movement->quantity);
        $this->assertEquals('receipt', $movement->movement_type->value);
        $this->assertEquals('customer_return', $movement->reason->value);
    }

    public function test_voiding_records_cash_drawer_refund(): void
    {
        $receipt = $this->createReceipt();
        $shift = $this->createOpenShift();

        // Create payment method first
        $paymentMethod = PaymentMethod::create([
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
            'fee_fixed' => '0.00',
            'fee_percent' => '0.00',
            'is_active' => true,
            'position' => 1,
        ]);

        // Create cash payment on receipt
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_type' => 'CASH',
            'amount' => '119.00',
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'Refund test');

        // Verify REFUND cash drawer operation was created
        $refundOp = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', 'REFUND')
            ->where('receipt_id', $receipt->id)
            ->first();

        $this->assertNotNull($refundOp);
        $this->assertEquals('119.000', $refundOp->amount);
    }

    public function test_voiding_cash_over_tender_refunds_net_drawer_cash_not_tendered_amount(): void
    {
        // Bug 2 follow-up — when the cashier over-tendered (€20 for a €10
        // receipt), only €10 actually entered the drawer (the other €10 was
        // returned as change). Voiding must refund €10, not €20, otherwise
        // the drawer goes phantom-short by the change-due amount.
        $receipt = $this->createReceipt([
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
            'change_due' => '10.000',
        ]);
        $shift = $this->createOpenShift();

        $paymentMethod = PaymentMethod::create([
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
            'fee_fixed' => '0.00',
            'fee_percent' => '0.00',
            'is_active' => true,
            'position' => 1,
        ]);

        // Post-Bug-2 contract: ReceiptPayment.amount == tendered (€20).
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_type' => 'CASH',
            'amount' => '20.000',
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'Over-tender void test');

        $refundOp = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', 'REFUND')
            ->where('receipt_id', $receipt->id)
            ->first();

        $this->assertNotNull($refundOp);
        // €10 net cash actually entered the drawer at sale time (€20 tendered
        // − €10 change returned). The refund must move that €10 back out.
        $this->assertEquals('10.000', $refundOp->amount);
    }

    public function test_voiding_tolerance_short_pay_refunds_tendered_cash_not_full_total(): void
    {
        // Codex r2 P2 closure — when the cashier pays short within the
        // configured cash tolerance, `cash.amount = tendered` (less than
        // `receipt.total`), `change_due = 0`, and the gap is recorded in
        // `tolerance_writeoff` and posted to GL 658. Voiding must refund
        // ONLY the cash actually tendered (which physically entered the
        // drawer), not the full receipt.total — otherwise the drawer
        // goes short by the tolerance amount.
        $receipt = $this->createReceipt([
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'change_due' => '0.000',
            'tolerance_writeoff' => '0.300',
        ]);
        $shift = $this->createOpenShift();

        $paymentMethod = PaymentMethod::create([
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
            'fee_fixed' => '0.00',
            'fee_percent' => '0.00',
            'is_active' => true,
            'position' => 1,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_type' => 'CASH',
            'amount' => '99.700',
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'Tolerance short-pay void');

        $refundOp = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', 'REFUND')
            ->where('receipt_id', $receipt->id)
            ->first();

        $this->assertNotNull($refundOp);
        // Tendered: 99.700 (the cash that physically entered the drawer).
        // Tolerance write-off (0.300) was posted to GL, NOT to the drawer.
        $this->assertEquals('99.700', $refundOp->amount);
    }

    public function test_voiding_pre_fix_legacy_over_tender_refunds_net_drawer_cash(): void
    {
        // Codex r1 P2 closure — pre-fix POS clients persisted
        // `pos_receipt_payments.amount = cart total` AND `change_due > 0`
        // (the cart-total amount was the Bug 2 bug; change_due was already
        // captured from the cashier's tendered input). Voiding such a
        // receipt must still refund the net cash that entered the drawer
        // at sale time = `receipt.total − Σ(non_cash_payments.amount)`.
        //
        // Without this fix, the void would compute `cash_sum − change_due
        // = total − change_due` and (for cart-total-shape over-tender)
        // skip the refund entirely, leaving the drawer phantom-high by
        // the change_due amount.
        $receipt = $this->createReceipt([
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
            'change_due' => '10.000',
        ]);
        $shift = $this->createOpenShift();

        $paymentMethod = PaymentMethod::create([
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
            'fee_fixed' => '0.00',
            'fee_percent' => '0.00',
            'is_active' => true,
            'position' => 1,
        ]);

        // Pre-fix legacy shape: ReceiptPayment.amount == cart total, NOT tendered.
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_type' => 'CASH',
            'amount' => '10.000',
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'Pre-fix legacy void');

        $refundOp = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', 'REFUND')
            ->where('receipt_id', $receipt->id)
            ->first();

        $this->assertNotNull($refundOp);
        // Net cash entering drawer at sale time = total − non_cash_sum = 10 − 0 = 10.
        $this->assertEquals('10.000', $refundOp->amount);
    }

    public function test_voiding_cash_with_null_change_due_refunds_full_payment_amount(): void
    {
        // Defensive: pre-Bug-2 receipts (and any future legacy row) may have
        // change_due = NULL. Treat NULL as 0; refund the full payment.amount.
        $receipt = $this->createReceipt([
            'change_due' => null,
        ]);
        $shift = $this->createOpenShift();

        $paymentMethod = PaymentMethod::create([
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
            'fee_fixed' => '0.00',
            'fee_percent' => '0.00',
            'is_active' => true,
            'position' => 1,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_type' => 'CASH',
            'amount' => '119.000',
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->service->voidReceipt($receipt, $this->cashier, 'NULL change_due void test');

        $refundOp = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', 'REFUND')
            ->where('receipt_id', $receipt->id)
            ->first();

        $this->assertNotNull($refundOp);
        $this->assertEquals('119.000', $refundOp->amount);
    }

    public function test_voiding_skips_cash_drawer_when_no_open_shift(): void
    {
        $receipt = $this->createReceipt();

        // No open shift — should not throw
        $result = $this->service->voidReceipt($receipt, $this->cashier, 'No shift void');

        $this->assertTrue($result->is_voided);
        $this->assertEquals(0, CashDrawerOperation::where('operation_type', 'REFUND')->count());
    }

    // =========================================================================
    // Variant-aware void reversal (F3 — variant retrofit audit 2026-06-10)
    // =========================================================================

    public function test_voiding_variant_line_restores_variant_stock_row(): void
    {
        $receipt = $this->createReceipt();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);
        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'variant_id' => $variantA->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.00',
            'unit_price' => '50.00',
            'line_total' => '100.00',
            'tax_rate' => '19.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
        ]);

        $stockA = $this->createStockLevel($product->id, $variantA->id, '8.0000');
        $stockB = $this->createStockLevel($product->id, $variantB->id, '5.0000');
        $stockNull = $this->createStockLevel($product->id, null, '50.0000');

        $this->service->voidReceipt($receipt, $this->cashier, 'Variant void test');

        // ONLY the variant-A row is restored.
        $this->assertEquals('10.0000', (string) $stockA->refresh()->quantity);
        $this->assertEquals('5.0000', (string) $stockB->refresh()->quantity);
        $this->assertEquals('50.0000', (string) $stockNull->refresh()->quantity);

        // The reversal StockMovement carries the variant.
        $movement = StockMovement::where('reference_type', 'pos_receipt_void')
            ->where('reference_id', $receipt->id)
            ->firstOrFail();
        $this->assertSame($variantA->id, $movement->variant_id);
    }

    public function test_voiding_null_variant_line_restores_null_row_when_variant_rows_exist(): void
    {
        // Projection-path symmetry: a NULL-variant line was decremented on
        // the variant_id IS NULL stock row — the void must restore that row.
        $receipt = $this->createReceipt();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'variant_id' => null,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.00',
            'unit_price' => '50.00',
            'line_total' => '100.00',
            'tax_rate' => '19.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
        ]);

        $stockVariant = $this->createStockLevel($product->id, $variant->id, '8.0000');
        $stockNull = $this->createStockLevel($product->id, null, '40.0000');

        $this->service->voidReceipt($receipt, $this->cashier, 'NULL-variant void test');

        $this->assertEquals('42.0000', (string) $stockNull->refresh()->quantity);
        $this->assertEquals('8.0000', (string) $stockVariant->refresh()->quantity);

        $movement = StockMovement::where('reference_type', 'pos_receipt_void')
            ->where('reference_id', $receipt->id)
            ->firstOrFail();
        $this->assertNull($movement->variant_id);
    }

    public function test_void_reversal_adds_stock_at_scale_four(): void
    {
        // Quantities are stored at scale 4 (canonical quantity scale). A
        // voided line of 0.3333 must restore exactly 0.3333 — not 0.33.
        $receipt = $this->createReceipt();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '0.3333',
            'unit_price' => '30.00',
            'line_total' => '10.00',
            'tax_rate' => '19.00',
            'tax_amount' => '1.90',
            'discount_amount' => '0.00',
        ]);

        $stock = $this->createStockLevel($product->id, null, '8.0000');

        $this->service->voidReceipt($receipt, $this->cashier, 'Scale-4 void test');

        $this->assertEquals('8.3333', (string) $stock->refresh()->quantity);
    }

    private function createStockLevel(string $productId, ?string $variantId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.00',
        ]);
    }

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createOpenShift(): Shift
    {
        return Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private int $receiptSequence = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        $this->receiptSequence++;

        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->receiptSequence),
            'chain_sequence' => $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "void-receipt-{$this->receiptSequence}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
            'total' => '119.00',
            'currency' => 'TND',
            'is_voided' => false,
        ];

        return Receipt::create(array_merge($defaults, $overrides));
    }
}
