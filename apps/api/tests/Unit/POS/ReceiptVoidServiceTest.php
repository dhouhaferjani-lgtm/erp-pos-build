<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Modules\POS\Application\Services\ReceiptVoidService;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        $receipt = $this->createReceipt(['is_voided' => true, 'voided_at' => now()]);

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

        $this->assertEquals('10.00', $stockLevel->quantity);

        // Verify stock movement was created
        $movement = StockMovement::where('reference_type', 'pos_receipt_void')
            ->where('reference_id', $receipt->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals('2.00', $movement->quantity);
        $this->assertEquals('receipt', $movement->movement_type->value);
        $this->assertEquals('customer_return', $movement->reason->value);
    }

    public function test_voiding_records_cash_drawer_refund(): void
    {
        $receipt = $this->createReceipt();
        $shift = $this->createOpenShift();

        // Create payment method first
        $paymentMethod = \App\Modules\Treasury\Domain\PaymentMethod::create([
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
        $this->assertEquals('119.00', $refundOp->amount);
    }

    public function test_voiding_skips_cash_drawer_when_no_open_shift(): void
    {
        $receipt = $this->createReceipt();

        // No open shift — should not throw
        $result = $this->service->voidReceipt($receipt, $this->cashier, 'No shift void');

        $this->assertTrue($result->is_voided);
        $this->assertEquals(0, CashDrawerOperation::where('operation_type', 'REFUND')->count());
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
