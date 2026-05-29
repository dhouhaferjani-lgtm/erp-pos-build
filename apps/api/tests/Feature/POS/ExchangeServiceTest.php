<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\DTOs\ExchangeRequestInput;
use App\Modules\POS\Application\DTOs\ExchangeResult;
use App\Modules\POS\Application\Services\ExchangeService;
use App\Modules\POS\Domain\Enums\ExchangeRequestStatus;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\ExchangeInProgressException;
use App\Modules\POS\Domain\ExchangeRequest;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase F integration tests — ExchangeService.
 *
 * Covers:
 *   Task 34: pos_exchange_requests table + ExchangeRequest model
 *   Task 35: ExchangeService idempotency triple persistence
 *   Task 36: exchange_group_id committed in v3 hash of both halves
 *   Task 37: stock locking through both halves (same-SKU produces TWO movements)
 *   Task 38: surplus → voucher (negative net + StoreVoucher)
 */
final class ExchangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixtures();
    }

    // =========================================================================
    // Task 34 — schema & model
    // =========================================================================

    public function test_exchange_request_table_exists_with_correct_schema(): void
    {
        $companyId = $this->company->id;
        $tenantId = $this->tenant->id;
        $exchangeRequestId = (string) Str::uuid();
        $exchangeGroupId = (string) Str::uuid();

        /** @var ExchangeRequest $row */
        $row = ExchangeRequest::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'exchange_request_id' => $exchangeRequestId,
            'exchange_group_id' => $exchangeGroupId,
            'status' => ExchangeRequestStatus::Pending,
        ]);

        $this->assertNotEmpty($row->id, 'ID should be auto-generated');
        $this->assertDatabaseHas('pos_exchange_requests', [
            'id' => $row->id,
            'company_id' => $companyId,
            'exchange_request_id' => $exchangeRequestId,
            'exchange_group_id' => $exchangeGroupId,
            'status' => 'pending',
        ]);
    }

    public function test_unique_constraint_on_company_and_exchange_request_id(): void
    {
        $exchangeRequestId = (string) Str::uuid();

        ExchangeRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'exchange_request_id' => $exchangeRequestId,
            'exchange_group_id' => (string) Str::uuid(),
            'status' => ExchangeRequestStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        ExchangeRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'exchange_request_id' => $exchangeRequestId, // duplicate
            'exchange_group_id' => (string) Str::uuid(),
            'status' => ExchangeRequestStatus::Pending,
        ]);
    }

    public function test_exchange_request_status_transitions(): void
    {
        /** @var ExchangeRequest $row */
        $row = ExchangeRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'exchange_request_id' => (string) Str::uuid(),
            'exchange_group_id' => (string) Str::uuid(),
            'status' => ExchangeRequestStatus::Pending,
        ]);

        $this->assertSame(ExchangeRequestStatus::Pending, $row->status);

        $row->status = ExchangeRequestStatus::Completed;
        $row->save();

        $this->assertSame(ExchangeRequestStatus::Completed, $row->fresh()->status);

        $row->status = ExchangeRequestStatus::Failed;
        $row->failure_reason = 'Test failure';
        $row->save();

        $this->assertSame(ExchangeRequestStatus::Failed, $row->fresh()->status);
        $this->assertSame('Test failure', $row->fresh()->failure_reason);
    }

    // =========================================================================
    // Task 35 — idempotency triple persistence
    // =========================================================================

    public function test_process_exchange_writes_idempotency_row_on_success(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Return all 3 items (≈60.000), new sale for 1 item at 10.000 → net ≈ −50 (surplus)
        $saleReceipt = $this->createSaleReceiptWithLine();
        $exchangeRequestId = (string) Str::uuid();

        $result = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',   // return all items (total ≈ 60)
            saleTotal: '10.000',  // new item cheaper (net negative = surplus)
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );

        // Idempotency row should exist and be completed
        $exchangeRow = ExchangeRequest::where('exchange_request_id', $exchangeRequestId)
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($exchangeRow);
        $this->assertSame(ExchangeRequestStatus::Completed, $exchangeRow->status);
        $this->assertSame($result->returnReceiptId, $exchangeRow->return_receipt_id);
        $this->assertSame($result->saleReceiptId, $exchangeRow->sale_receipt_id);
        $this->assertNotNull($exchangeRow->completed_at);
    }

    public function test_process_exchange_returns_existing_triple_on_replay_with_same_request_id(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Net negative scenario (surplus) so no payment tenders needed
        $saleReceipt = $this->createSaleReceiptWithLine();
        $exchangeRequestId = (string) Str::uuid();

        $first = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );

        $receiptCountAfterFirst = Receipt::count();

        // Second call with same exchange_request_id — should return the same triple
        $second = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );

        // Same return + sale receipt IDs
        $this->assertSame($first->returnReceiptId, $second->returnReceiptId);
        $this->assertSame($first->saleReceiptId, $second->saleReceiptId);

        // No new receipts were created on replay
        $this->assertSame($receiptCountAfterFirst, Receipt::count());
    }

    public function test_process_exchange_throws_409_on_concurrent_pending(): void
    {
        $exchangeRequestId = (string) Str::uuid();

        // Manually insert a pending row (simulates a concurrent in-progress exchange)
        ExchangeRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'exchange_request_id' => $exchangeRequestId,
            'exchange_group_id' => (string) Str::uuid(),
            'status' => ExchangeRequestStatus::Pending,
        ]);

        $saleReceipt = $this->createSaleReceiptWithLine();

        $this->expectException(ExchangeInProgressException::class);

        $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '1',
            saleTotal: '40.000',
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );
    }

    public function test_process_exchange_replays_failed_state(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $exchangeRequestId = (string) Str::uuid();

        // Simulate a previously failed row
        $failedRow = ExchangeRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'exchange_request_id' => $exchangeRequestId,
            'exchange_group_id' => (string) Str::uuid(),
            'status' => ExchangeRequestStatus::Failed,
            'failure_reason' => 'Previous GL account missing',
        ]);

        $saleReceipt = $this->createSaleReceiptWithLine();

        // Should not throw — re-attempts the failed exchange (net negative = surplus)
        $result = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );

        $this->assertNotNull($result->returnReceiptId);
        $this->assertNotNull($result->saleReceiptId);

        // Row should be completed now (same row reused)
        $freshRow = ExchangeRequest::find($failedRow->id);
        $this->assertSame(ExchangeRequestStatus::Completed, $freshRow->status);
    }

    // =========================================================================
    // Task 36 — exchange_group_id in both halves' v3 hash
    // =========================================================================

    public function test_both_halves_share_the_same_exchange_group_id(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $saleReceipt = $this->createSaleReceiptWithLine();
        $exchangeRequestId = (string) Str::uuid();

        // Net negative (return > sale) so no tenders needed
        $result = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: $exchangeRequestId,
            surplusDestination: RefundDestination::Cash,
        );

        $returnReceipt = Receipt::findOrFail($result->returnReceiptId);
        $saleRcpt = Receipt::findOrFail($result->saleReceiptId);

        $this->assertNotNull($returnReceipt->exchange_group_id, 'Return receipt must have exchange_group_id');
        $this->assertNotNull($saleRcpt->exchange_group_id, 'Sale receipt must have exchange_group_id');
        $this->assertSame(
            $returnReceipt->exchange_group_id,
            $saleRcpt->exchange_group_id,
            'Both halves must share the same exchange_group_id'
        );
    }

    public function test_both_halves_are_fiscalized(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $saleReceipt = $this->createSaleReceiptWithLine();

        // Net negative so no tenders needed
        $result = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: (string) Str::uuid(),
            surplusDestination: RefundDestination::Cash,
        );

        $returnReceipt = Receipt::findOrFail($result->returnReceiptId);
        $saleRcpt = Receipt::findOrFail($result->saleReceiptId);

        $this->assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status, 'Return half must be fiscalized');
        $this->assertSame(FiscalStatus::Fiscalized, $saleRcpt->fiscal_status, 'Sale half must be fiscalized');
        $this->assertNotNull($returnReceipt->fiscal_hash);
        $this->assertNotNull($saleRcpt->fiscal_hash);
    }

    public function test_sale_receipt_chains_after_return_receipt(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $saleReceipt = $this->createSaleReceiptWithLine();
        $initialTerminalSeq = $this->terminal->current_sequence;

        // Net negative so no tenders needed
        $result = $this->callProcessExchange(
            saleReceipt: $saleReceipt,
            returnQty: '3',
            saleTotal: '10.000',
            exchangeRequestId: (string) Str::uuid(),
            surplusDestination: RefundDestination::Cash,
        );

        $returnReceipt = Receipt::findOrFail($result->returnReceiptId);
        $saleRcpt = Receipt::findOrFail($result->saleReceiptId);

        // Sale's previous_hash is the return's fiscal_hash (contiguous chain)
        $this->assertSame(
            $returnReceipt->fiscal_hash,
            $saleRcpt->previous_hash,
            'Sale receipt previous_hash must equal return receipt fiscal_hash'
        );

        // Terminal advanced by 3:
        //   +1 from finalize(return half) in processReturn
        //   +1 from createReceipt(sale) advancing receipt-number sequence
        //   +1 from finalize(sale half) advancing chain sequence
        $this->terminal->refresh();
        $this->assertSame($initialTerminalSeq + 3, $this->terminal->current_sequence);
    }

    // =========================================================================
    // Task 37 — stock locking through both halves
    // =========================================================================

    public function test_same_sku_return_plus_sale_produces_two_movements(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Create a product with stock
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '10.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        // Create a sale receipt for that product
        $originalSaleReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'total' => '10.000',
            'subtotal' => '8.403',
            'tax_amount' => '1.597',
            'currency' => 'EUR',
        ]);

        $originalLine = ReceiptLine::create([
            'receipt_id' => $originalSaleReceipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku ?? 'SKU-001',
            'product_name' => $product->name,
            'quantity' => '1.000',
            'unit' => 'pc',
            'unit_price' => '10.000',
            'line_total' => '10.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.597',
            'discount_amount' => '0.000',
        ]);

        $movementCountBefore = StockMovement::count();
        $stockBefore = '10.0000';

        // Return the same SKU qty 1, then sell the same SKU qty 1
        $result = $this->callProcessExchangeWithProduct(
            saleReceipt: $originalSaleReceipt,
            originalLine: $originalLine,
            product: $product,
        );

        // Two movements should be created:
        // 1. Return half: +1 (MovementType::Receipt, reason=POSReturn)
        // 2. Sale half: -1 (MovementType::Issue, reason=POSSale)
        $newMovements = StockMovement::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->get();

        $this->assertCount(
            $movementCountBefore + 2,
            $newMovements,
            'Exactly 2 stock movements should be created (one return, one sale)'
        );

        $returnMovement = $newMovements->firstWhere('reason', MovementReason::POSReturn);
        $saleMovement = $newMovements->firstWhere('reason', MovementReason::POSSale);

        $this->assertNotNull($returnMovement, 'Return stock movement must exist');
        $this->assertNotNull($saleMovement, 'Sale stock movement must exist');

        // Return movement: +1 (quantity = positive)
        $this->assertSame(MovementType::Receipt, $returnMovement->movement_type);
        $this->assertSame(
            '1',
            (string) (int) $returnMovement->quantity,
            'Return movement quantity should be 1'
        );

        // Sale movement: -1 (quantity = positive, but it's a deduction)
        $this->assertSame(MovementType::Issue, $saleMovement->movement_type);
        $this->assertSame(
            '1',
            (string) (int) $saleMovement->quantity,
            'Sale movement quantity should be 1'
        );

        // Net stock change = 0 (returned 1, sold 1)
        $finalStock = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');

        $this->assertSame(
            $stockBefore,
            (string) $finalStock,
            'Net stock must be unchanged after returning and re-selling same qty'
        );
    }

    // =========================================================================
    // Task 38 — surplus → voucher
    // =========================================================================

    public function test_process_exchange_with_negative_net_and_voucher_destination_issues_voucher(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->setCompanyPolicy([
            'allowed_refund_destinations' => ['store_voucher', 'cash', 'exchange_deferred'],
            'voucher_default_expiry_days' => 365,
            'customer_return_expiry_days' => 90,
            'manager_override_threshold_amount' => '99999.00',
        ]);

        // Return €60 worth of goods, buy €40 worth → net surplus €20 → voucher
        $saleReceipt = $this->createSaleReceiptWith(total: '60.000', subtotal: '50.420', taxAmount: '9.580');
        $line = $this->createLineFor($saleReceipt, qty: '3.000', lineTotal: '60.000');

        $result = $this->callProcessExchangeWithLines(
            saleReceipt: $saleReceipt,
            returnLine: $line,
            returnQty: '3',
            newSaleUnitPrice: '13.334', // ~€40 for 3 items
            surplusDestination: RefundDestination::StoreVoucher,
        );

        // Voucher must be issued
        $this->assertNotNull($result->voucherId, 'voucherId should be set when surplus destination is voucher');

        $voucher = Voucher::findOrFail($result->voucherId);
        $this->assertSame(VoucherSource::ExchangeSurplus, $voucher->source);

        // ExchangeResult has negative net (return > sale)
        $this->assertLessThan(0, (float) $result->netAmount, 'Net must be negative for return > sale');
    }

    public function test_process_exchange_with_negative_net_and_cash_destination_records_cash_drawer_refund(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Return €60, buy €40 → net surplus €20 → cash
        $saleReceipt = $this->createSaleReceiptWith(total: '60.000', subtotal: '50.420', taxAmount: '9.580');
        $line = $this->createLineFor($saleReceipt, qty: '3.000', lineTotal: '60.000');

        $result = $this->callProcessExchangeWithLines(
            saleReceipt: $saleReceipt,
            returnLine: $line,
            returnQty: '3',
            newSaleUnitPrice: '13.334',
            surplusDestination: RefundDestination::Cash,
        );

        // No voucher for cash path
        $this->assertNull($result->voucherId);

        // A cash drawer REFUND operation should exist for the return receipt
        $this->assertDatabaseHas('pos_cash_drawer_operations', [
            'shift_id' => $this->shift->id,
            'operation_type' => 'REFUND',
            'receipt_id' => $result->returnReceiptId,
        ]);
    }

    public function test_process_exchange_with_positive_net_and_no_tenders_throws(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Return 1 of 3 items (≈ 20.000), new sale 1 item at 60.000 → net ≈ +40 → customer pays
        // No netPaymentTenders provided → ExchangeService must throw
        $saleReceipt = $this->createSaleReceiptWith(total: '60.000', subtotal: '50.420', taxAmount: '9.580');
        $line = $this->createLineFor($saleReceipt, qty: '3.000', lineTotal: '60.000');

        // ExchangeService should throw when net > 0 and no netPaymentTenders supplied
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/netPaymentTenders/');

        $this->callProcessExchangeWithLines(
            saleReceipt: $saleReceipt,
            returnLine: $line,
            returnQty: '1',       // return 1 of 3 items → return total ≈ -20.000
            newSaleUnitPrice: '60.000',  // new item more expensive (net positive ≈ +40)
            surplusDestination: RefundDestination::Cash,
        );
    }

    // =========================================================================
    // Test helpers
    // =========================================================================

    private function setUpFixtures(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.process_returns', 'sanctum');
        Permission::findOrCreate('pos.refund_above_threshold', 'sanctum');
        Permission::findOrCreate('pos.refund_extend_daily_cap', 'sanctum');
        $this->cashier->givePermissionTo('pos.process_returns');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setCompanyPolicy(array $overrides): void
    {
        $defaults = (new ReservationSettings)->toArray();
        $merged = array_merge($defaults, $overrides);
        $this->company->reservation_settings = $merged;
        $this->company->save();
    }

    private function createSaleReceiptWith(
        string $total = '60.000',
        string $subtotal = '50.420',
        string $taxAmount = '9.580',
    ): Receipt {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
    }

    private function createLineFor(
        Receipt $receipt,
        string $qty = '3.000',
        string $lineTotal = '60.000',
    ): ReceiptLine {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget X',
            'quantity' => $qty,
            'unit' => 'pcs',
            'unit_price' => bcdiv($lineTotal, $qty, 3),
            'line_total' => $lineTotal,
            'tax_rate' => '19.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
    }

    /**
     * Create a sale receipt with a product-tracked line (for stock tests).
     *
     * @return array{0: Receipt, 1: ReceiptLine}
     */
    private function createSaleReceiptWithLine(): array
    {
        $receipt = $this->createSaleReceiptWith();
        $line = $this->createLineFor($receipt);

        return [$receipt, $line];
    }

    private function bindCompanyContext(): void
    {
        $company = $this->company;
        $this->app->bind(CompanyContext::class, function () use ($company) {
            $context = $this->createMock(CompanyContext::class);
            $context->method('requireCompanyId')->willReturn($company->id);

            return $context;
        });
    }

    /**
     * Helper: call processExchange with a receipt+line tuple (from createSaleReceiptWithLine).
     *
     * @param  array{0: Receipt, 1: ReceiptLine}  $saleReceipt  Tuple of [Receipt, ReceiptLine]
     */
    private function callProcessExchange(
        array $saleReceipt,
        string $returnQty,
        string $saleTotal,
        string $exchangeRequestId,
        RefundDestination $surplusDestination,
    ): ExchangeResult {
        [$receipt, $line] = $saleReceipt;

        return $this->callProcessExchangeWithLines(
            saleReceipt: $receipt,
            returnLine: $line,
            returnQty: $returnQty,
            newSaleUnitPrice: bcdiv($saleTotal, '1', 3),
            surplusDestination: $surplusDestination,
            exchangeRequestId: $exchangeRequestId,
        );
    }

    /**
     * Helper: call processExchange with explicit receipt/line parameters.
     *
     * Creates a product for the new sale items so ReceiptCreationService can look it up.
     */
    private function callProcessExchangeWithLines(
        Receipt $saleReceipt,
        ReceiptLine $returnLine,
        string $returnQty,
        string $newSaleUnitPrice,
        RefundDestination $surplusDestination,
        ?string $exchangeRequestId = null,
    ): ExchangeResult {
        $this->bindCompanyContext();

        // Create a product for the sale half
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => $newSaleUnitPrice,
            'tax_rate' => '19.00',
        ]);

        /** @var Terminal $terminal */
        $terminal = $this->terminal->fresh() ?? $this->terminal;

        $input = new ExchangeRequestInput(
            original: $saleReceipt,
            returnLines: [['line_id' => $returnLine->id, 'quantity' => $returnQty]],
            returnReason: ReturnReason::CustomerChangedMind,
            newSaleItems: [
                [
                    'product_id' => $product->id,
                    'quantity' => '1',
                    'unit_price' => $newSaleUnitPrice,
                ],
            ],
            cashier: $this->cashier,
            terminal: $terminal,
            exchangeRequestId: $exchangeRequestId ?? (string) Str::uuid(),
            surplusDestination: $surplusDestination,
        );

        /** @var ExchangeService $service */
        $service = $this->app->make(ExchangeService::class);

        return $service->processExchange($input);
    }

    /**
     * Helper: call processExchange with a product-tracked line (for Task 37 stock tests).
     */
    private function callProcessExchangeWithProduct(
        Receipt $saleReceipt,
        ReceiptLine $originalLine,
        Product $product,
    ): ExchangeResult {
        $this->bindCompanyContext();

        /** @var Terminal $terminal */
        $terminal = $this->terminal->fresh() ?? $this->terminal;

        $input = new ExchangeRequestInput(
            original: $saleReceipt,
            returnLines: [['line_id' => $originalLine->id, 'quantity' => '1']],
            returnReason: ReturnReason::CustomerChangedMind,
            newSaleItems: [
                [
                    'product_id' => $product->id,
                    'quantity' => '1',
                    'unit_price' => (string) $product->sale_price,
                ],
            ],
            cashier: $this->cashier,
            terminal: $terminal,
            exchangeRequestId: (string) Str::uuid(),
            surplusDestination: RefundDestination::Cash,
        );

        /** @var ExchangeService $service */
        $service = $this->app->make(ExchangeService::class);

        return $service->processExchange($input);
    }
}
