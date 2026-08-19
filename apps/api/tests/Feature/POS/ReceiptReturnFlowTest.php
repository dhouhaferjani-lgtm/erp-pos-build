<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Integration tests for receipt return flow.
 *
 * Tests returned quantity tracking on receipt detail, receipt_type filter,
 * and the POST /pos/receipts/{id}/return endpoint.
 */
final class ReceiptReturnFlowTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    // ---------------------------------------------------------------
    // Existing display/filter tests
    // ---------------------------------------------------------------

    public function test_show_receipt_includes_returned_quantity_per_line(): void
    {
        // Arrange: Create a sale receipt with 2 lines
        $saleReceipt = $this->createSaleReceipt();
        $line1 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $line2 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 2,
            'product_id' => null,
            'product_code' => 'PROD-002',
            'product_name' => 'Widget B',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '20.000',
            'line_total' => '60.000',
            'tax_rate' => '19.00',
            'tax_amount' => '11.400',
            'discount_amount' => '0.000',
        ]);

        // Create a return receipt that returned 2 of Widget A
        $returnReceipt = $this->createReturnReceipt($saleReceipt);
        ReceiptLine::create([
            'receipt_id' => $returnReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '-2.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '-20.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-3.800',
            'discount_amount' => '0.000',
        ]);

        // Act: GET receipt detail
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert
        $response->assertStatus(200);
        $lines = $response->json('data.lines');

        $this->assertCount(2, $lines);

        // Widget A: 2 returned out of 5
        $widgetA = collect($lines)->firstWhere('product_code', 'PROD-001');
        $this->assertEquals('2.0000', $widgetA['returned_quantity']);

        // Widget B: 0 returned out of 3
        $widgetB = collect($lines)->firstWhere('product_code', 'PROD-002');
        $this->assertEquals('0.0000', $widgetB['returned_quantity']);
    }

    public function test_show_receipt_returns_zero_returned_quantity_when_no_returns(): void
    {
        // Arrange: Create a sale receipt with no returns
        $saleReceipt = $this->createSaleReceipt();
        ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Test Product',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.700',
            'discount_amount' => '0.000',
        ]);

        // Act
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert
        $response->assertStatus(200);
        $lines = $response->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertEquals('0.0000', $lines[0]['returned_quantity']);
    }

    public function test_show_receipt_excludes_voided_returns_from_returned_quantity(): void
    {
        // Arrange: Sale receipt with one line
        $saleReceipt = $this->createSaleReceipt();
        ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        // Create a voided return receipt (should not count)
        $voidedReturn = $this->createReturnReceipt($saleReceipt, isVoided: true);
        ReceiptLine::create([
            'receipt_id' => $voidedReturn->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '-3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '-30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-5.700',
            'discount_amount' => '0.000',
        ]);

        // Act
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert: voided return should not count
        $response->assertStatus(200);
        $lines = $response->json('data.lines');
        $this->assertEquals('0.0000', $lines[0]['returned_quantity']);
    }

    public function test_receipt_type_filter_returns_only_matching_type(): void
    {
        // Arrange: Create a sale receipt and a return receipt
        $saleReceipt = $this->createSaleReceipt();
        $returnReceipt = $this->createReturnReceipt($saleReceipt);

        // Act: Filter by return type
        $response = $this->getJson('/api/v1/pos/receipts?receipt_type=return');

        // Assert: Only return receipt in results
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('return', $data[0]['receipt_type']);

        // Act: Filter by sale type
        $response = $this->getJson('/api/v1/pos/receipts?receipt_type=sale');

        // Assert: Only sale receipt in results
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('sale', $data[0]['receipt_type']);
    }

    public function test_returned_quantity_correct_with_duplicate_product_lines(): void
    {
        // Arrange: Create a sale receipt with 2 lines of the SAME product (different prices)
        $saleReceipt = $this->createSaleReceipt();

        $line1 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-DUP',
            'product_name' => 'Duplicate Widget',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.700',
            'discount_amount' => '0.000',
        ]);

        $line2 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 2,
            'product_id' => null,
            'product_code' => 'PROD-DUP',
            'product_name' => 'Duplicate Widget',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '12.000',
            'line_total' => '60.000',
            'tax_rate' => '19.00',
            'tax_amount' => '11.400',
            'discount_amount' => '0.000',
        ]);

        // Create a return receipt that returns 2 units from LINE 2 only (using original_line_id)
        $returnReceipt = $this->createReturnReceipt($saleReceipt);
        ReceiptLine::create([
            'receipt_id' => $returnReceipt->id,
            'original_line_id' => $line2->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-DUP',
            'product_name' => 'Duplicate Widget',
            'quantity' => '-2.000',
            'unit' => 'pcs',
            'unit_price' => '12.000',
            'line_total' => '-24.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-4.560',
            'discount_amount' => '0.000',
        ]);

        // Act: GET receipt detail
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert
        $response->assertStatus(200);
        $lines = $response->json('data.lines');

        $this->assertCount(2, $lines);

        // Line 1 (same product code): 0 returned -- the return was from line 2
        $lineOne = collect($lines)->firstWhere('id', $line1->id);
        $this->assertNotNull($lineOne, 'Line 1 should be present in response');
        $this->assertEquals('0.0000', $lineOne['returned_quantity']);

        // Line 2 (same product code): 2 returned
        $lineTwo = collect($lines)->firstWhere('id', $line2->id);
        $this->assertNotNull($lineTwo, 'Line 2 should be present in response');
        $this->assertEquals('2.0000', $lineTwo['returned_quantity']);
    }

    // ---------------------------------------------------------------
    // POST /pos/receipts/{id}/return endpoint integration tests
    // ---------------------------------------------------------------

    public function test_process_return_creates_return_receipt(): void
    {
        // Arrange: Create a completed sale receipt with lines
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
            'notes' => 'Customer reported defect',
        ];

        // Act: POST to return endpoint
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        // Assert: 201 with correct return receipt shape
        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertEquals('return', $data['receipt_type']);
        $this->assertEquals($saleReceipt->id, $data['original_receipt_id']);
        $this->assertEquals(ReturnReason::Defective->value, $data['return_reason']);
        $this->assertNotNull($data['receipt_number']);
        $this->assertNotNull($data['posted_at']);

        // Return totals should be negative
        /** @var numeric-string $total */
        $total = $data['total'];
        /** @var numeric-string $subtotal */
        $subtotal = $data['subtotal'];
        $this->assertTrue(
            bccomp($total, '0', 3) < 0,
            'Return receipt total should be negative',
        );
        $this->assertTrue(
            bccomp($subtotal, '0', 3) < 0,
            'Return receipt subtotal should be negative',
        );

        // Lines should have negative quantities
        $this->assertCount(1, $data['lines']);
        $returnLine = $data['lines'][0];
        $this->assertEquals('Widget A', $returnLine['product_name']);
        /** @var numeric-string $lineQty */
        $lineQty = (string) $returnLine['quantity'];
        /** @var numeric-string $lineTotal */
        $lineTotal = (string) $returnLine['line_total'];
        $this->assertTrue(
            bccomp($lineQty, '0', 3) < 0,
            'Return line quantity should be negative',
        );
        $this->assertTrue(
            bccomp($lineTotal, '0', 3) < 0,
            'Return line total should be negative',
        );

        // Assert: Return receipt persisted in DB
        $this->assertDatabaseHas('pos_receipts', [
            'id' => $data['id'],
            'receipt_type' => ReceiptType::Return->value,
            'original_receipt_id' => $saleReceipt->id,
            'return_reason' => ReturnReason::Defective->value,
        ]);

        // Assert: Phase H Block 2 — printer-wiring fields are present.
        // qr_token may be null if the test environment has no active receipt_qr
        // signing key for this tenant; the contract is that the KEY EXISTS
        // (so the frontend can rely on it) and the value is null-or-string.
        $this->assertArrayHasKey('qr_token', $data, 'response should expose qr_token for printer footer');
        $this->assertTrue(
            $data['qr_token'] === null || is_string($data['qr_token']),
            'qr_token must be null or a string',
        );
        // Cash refund (no destination requested) → no voucher issued.
        $this->assertArrayHasKey('issued_voucher', $data, 'response should expose issued_voucher key');
        $this->assertNull(
            $data['issued_voucher'],
            'No voucher should be issued for a cash refund',
        );
    }

    /**
     * Phase H Block 2 — printer-wiring backend hook.
     *
     * When the company policy forces a store-voucher refund (e.g. out-of-window
     * with `out_of_window_policy=voucher_only`), the controller response MUST
     * surface the issued voucher so the POS printer can render the dedicated
     * voucher ticket without a second round trip.
     */
    public function test_process_return_forced_voucher_includes_issued_voucher_payload(): void
    {
        // Seed GL accounts required for VoucherIssuanceService.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Force voucher-only refunds for out-of-window receipts.
        /** @var array<string, mixed> $policy */
        $policy = [
            'customer_return_expiry_days' => 1,
            'out_of_window_policy' => 'voucher_only',
            'allowed_refund_destinations' => ['cash', 'store_voucher'],
            'voucher_default_expiry_days' => 365,
            'manager_override_threshold_amount' => '99999.000',
        ];
        $this->company->reservation_settings = $policy;
        $this->company->save();

        $saleReceipt = $this->createSaleReceipt();
        // Push it out of window so the resolver forces StoreVoucher.
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($isPgsql) {
            DB::statement('ALTER TABLE pos_receipts DISABLE TRIGGER enforce_receipt_immutability');
        }
        try {
            $saleReceipt->posted_at = Carbon::now()->subDays(2);
            $saleReceipt->save();
        } finally {
            if ($isPgsql) {
                DB::statement('ALTER TABLE pos_receipts ENABLE TRIGGER enforce_receipt_immutability');
            }
        }

        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-VCH',
            'product_name' => 'Voucher-eligible Item',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.700',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        $response->assertStatus(201);
        $data = $response->json('data');

        // qr_token must always be present in processReturn responses (issuance is unconditional)
        $this->assertArrayHasKey('qr_token', $data, 'response should expose qr_token on forced-voucher path');
        $this->assertTrue(
            $data['qr_token'] === null || is_string($data['qr_token']),
            'qr_token must be null or a string',
        );

        // Voucher payload shape used by the POS to print the voucher ticket.
        $this->assertNotNull(
            $data['issued_voucher'],
            'Forced-voucher refund must surface the issued_voucher payload',
        );
        $voucher = $data['issued_voucher'];
        $this->assertArrayHasKey('id', $voucher);
        $this->assertArrayHasKey('code', $voucher);
        $this->assertArrayHasKey('initial_balance', $voucher);
        $this->assertArrayHasKey('currency', $voucher);
        $this->assertArrayHasKey('expires_at', $voucher);
        $this->assertArrayHasKey('redemption_mode', $voucher);
        $this->assertArrayHasKey('partner_id', $voucher);
        // Refund amount on a 1-of-3 return of the line (line_total=30.000, EUR scale=2):
        //   ratio = 1/3.000; bcmul truncates → 9.99; VAT back-calculation → gross 9.98.
        // initial_balance stored as decimal(20,5) → Eloquent decimal:5 cast returns 5-decimal string.
        $this->assertSame('9.98000', $voucher['initial_balance']);
        $this->assertSame('EUR', $voucher['currency']);
        $this->assertIsString($voucher['code']);
        $this->assertNotEmpty($voucher['code']);
        $this->assertContains($voucher['redemption_mode'], ['bearer', 'customer_bound']);
    }

    public function test_process_return_rejects_over_return(): void
    {
        // Arrange: Create a sale receipt with qty 3
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.700',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::CustomerChangedMind->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '5', // More than the original 3
                ],
            ],
        ];

        // Act
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        // Assert: 400 error for invalid return data (over-return)
        $response->assertStatus(400);
        $this->assertEquals('INVALID_RETURN_DATA', $response->json('error.code'));
        $this->assertStringContainsString('Maximum returnable', $response->json('error.message'));
    }

    public function test_process_return_rejects_voided_receipt(): void
    {
        // Arrange: Create a voided sale receipt
        $voidedReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'EUR',
            'is_voided' => true,
            'voided_at' => now(),
            // voided rows need voided_by (pos_receipts_void_logic, PostgreSQL).
            'voided_by' => $this->user->id,
            'fiscal_status' => FiscalStatus::Voided,
            'void_reason' => 'Error',
        ]);

        $line = ReceiptLine::create([
            'receipt_id' => $voidedReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1',
                ],
            ],
        ];

        // Act
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$voidedReceipt->id}/return",
            $this->withVoidReturnApproval($voidedReceipt, $requestData),
        );

        // Assert: 422 error (RuntimeException path in controller)
        $response->assertStatus(422);
        $this->assertEquals('RETURN_FAILED', $response->json('error.code'));
        $this->assertStringContainsString('voided', $response->json('error.message'));
    }

    public function test_process_return_rejects_return_of_return(): void
    {
        // Arrange: Create a sale receipt, then a return receipt
        $saleReceipt = $this->createSaleReceipt();
        ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $returnReceipt = $this->createReturnReceipt($saleReceipt);
        $returnLine = ReceiptLine::create([
            'receipt_id' => $returnReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '-2.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '-20.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-3.800',
            'discount_amount' => '0.000',
        ]);

        // Try to return the return receipt
        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Other->value,
            'lines' => [
                [
                    'line_id' => $returnLine->id,
                    'quantity' => '1',
                ],
            ],
        ];

        // Act
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$returnReceipt->id}/return",
            $this->withVoidReturnApproval($returnReceipt, $requestData),
        );

        // Assert: 422 error -- cannot return a return receipt
        $response->assertStatus(422);
        $this->assertEquals('RETURN_FAILED', $response->json('error.code'));
        $this->assertStringContainsString('return receipt', $response->json('error.message'));
    }

    // DPA V9 (owner ruling D3 — SUNSET): `test_void_rejects_return_receipt`
    // was removed here. It pinned the Phase-6 `CANNOT_VOID_RETURN_RECEIPT`
    // 422 in `ReceiptController::void()`, which no longer exists — the whole
    // void endpoint is now a 410 `LEGACY_VOID_RETIRED` tombstone, so NO
    // receipt (sale or return) can be voided through it. The stronger
    // successor pin is
    // NewSaleServerAuthoringDispositionTest::test_void_route_is_retired,
    // which also asserts the target receipt is left unmutated.

    public function test_process_return_cumulative_quantities(): void
    {
        // Arrange: Sale receipt with qty 5
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $returnPayload = fn (string $qty): array => [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::CustomerChangedMind->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => $qty,
                ],
            ],
        ];

        // Act 1: Partial return of 2 -- should succeed
        $response1 = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $returnPayload('2')),
        );
        $response1->assertStatus(201);

        // Act 2: Another partial return of 2 -- should succeed (total returned = 4, remaining = 1)
        $response2 = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $returnPayload('2')),
        );
        $response2->assertStatus(201);

        // Act 3: Try to return 2 more -- should fail (only 1 remaining)
        $response3 = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $returnPayload('2')),
        );
        $response3->assertStatus(400);
        $this->assertEquals('INVALID_RETURN_DATA', $response3->json('error.code'));
        $this->assertStringContainsString('Maximum returnable', $response3->json('error.message'));

        // Act 4: Return the last 1 -- should succeed (total returned = 5, fully returned)
        $response4 = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $returnPayload('1')),
        );
        $response4->assertStatus(201);

        // Verify: Total of 3 non-voided return receipts created
        $returnCount = Receipt::where('original_receipt_id', $saleReceipt->id)
            ->where('receipt_type', ReceiptType::Return)
            ->where('is_voided', false)
            ->count();
        $this->assertEquals(3, $returnCount);
    }

    public function test_process_return_rejects_four_decimal_over_return(): void
    {
        // Codex r1 B1 — the return-quantity cap must compare at the canonical
        // quantity scale (4), not 3. At scale 3 both '1.0009' and '1.0000'
        // truncate to '1.000', so the cap passed and computeReturnTotals /
        // restoreStock then used the full 1.0009 → over-refund + over-restock.
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '1.0000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '10.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.900',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::CustomerChangedMind->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '1.0009', // 4-decimal over-return of the 1.0000 original
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        $response->assertStatus(400);
        $this->assertEquals('INVALID_RETURN_DATA', $response->json('error.code'));
        $this->assertStringContainsString('Maximum returnable', $response->json('error.message'));

        // No return receipt may exist — the over-return must be rejected whole.
        $this->assertSame(
            0,
            Receipt::where('original_receipt_id', $saleReceipt->id)
                ->where('receipt_type', ReceiptType::Return)
                ->count(),
        );
    }

    public function test_process_return_four_decimal_partials_sum_exactly_to_original(): void
    {
        // Codex r1 B1 — repeated 4-decimal partial returns must accumulate at
        // scale 4: 0.3333 × 3 + 0.0001 = 1.0000 exactly; the next 0.0001 must
        // be rejected (nothing remains).
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '1.0000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '10.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.900',
            'discount_amount' => '0.000',
        ]);

        $returnPayload = fn (string $qty): array => [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::CustomerChangedMind->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => $qty,
                ],
            ],
        ];

        foreach (['0.3333', '0.3333', '0.3333', '0.0001'] as $qty) {
            $response = $this->postJson(
                "/api/v1/pos/receipts/{$saleReceipt->id}/return",
                $this->withVoidReturnApproval($saleReceipt, $returnPayload($qty)),
            );
            $response->assertStatus(201);
        }

        // 1.0000 is fully returned — one more 0.0001 must be rejected.
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $returnPayload('0.0001')),
        );
        $response->assertStatus(400);
        $this->assertEquals('INVALID_RETURN_DATA', $response->json('error.code'));
        $this->assertStringContainsString('Maximum returnable', $response->json('error.message'));

        $this->assertSame(
            4,
            Receipt::where('original_receipt_id', $saleReceipt->id)
                ->where('receipt_type', ReceiptType::Return)
                ->where('is_voided', false)
                ->count(),
        );
    }

    public function test_process_return_restores_stock(): void
    {
        // Arrange: Create a product with stock
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $initialQuantity = '10.00';
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $initialQuantity,
            'reserved' => '0.00',
        ]);

        // Create a sale receipt with product_id set (so stock restoration triggers)
        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '3.000',
            'unit' => 'piece',
            'unit_price' => (string) $product->sale_price,
            'line_total' => number_format((float) $product->sale_price * 3, 3, '.', ''),
            'tax_rate' => '19.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);

        $requestData = [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::WrongItem->value,
            'lines' => [
                [
                    'line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ];

        // Act: Process return
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        // Assert: Return succeeded
        $response->assertStatus(201);
        $returnReceiptId = $response->json('data.id');

        // Assert: Stock level increased by 2
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertNotNull($stockLevel, 'Stock level should exist');
        $expectedQuantity = bcadd($initialQuantity, '2', 4);
        $this->assertEquals($expectedQuantity, $stockLevel->quantity);

        // Assert: StockMovement created for the return
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'movement_type' => MovementType::Receipt->value,
            'reason' => MovementReason::POSReturn->value,
            'quantity' => '2.00',
            'quantity_before' => $initialQuantity,
            'quantity_after' => $expectedQuantity,
            'reference_type' => 'pos_receipt_return',
            'reference_id' => $returnReceiptId,
        ]);
    }

    public function test_process_return_uses_the_original_sale_movement_cost_without_receipt_line_rounding(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '9.000000',
        ]);
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $saleReceipt = $this->createSaleReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.0000',
            'unit' => 'piece',
            'unit_price' => '10.000',
            'unit_cost' => '1.234568',
            'line_total' => '20.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
        self::assertSame('1.2346', (string) $line->fresh()->unit_cost,
            'option (b) loses the original six-decimal movement basis at the receipt-line boundary');

        StockMovement::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => '2.0000',
            'quantity_before' => '12.0000',
            'quantity_after' => '10.0000',
            'unit_cost' => '1.234568',
            'total_cost' => '2.469136',
            'reference' => 'Original POS sale',
            'reference_type' => 'pos_receipt',
            'reference_id' => $saleReceipt->id,
            'user_id' => $this->user->id,
            'is_historical' => false,
            'occurred_at' => now(),
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::WrongItem->value,
                'lines' => [[
                    'line_id' => $line->id,
                    'quantity' => '2.0000',
                ]],
            ]),
        );
        $response->assertCreated();

        $returnMovement = StockMovement::query()
            ->where('reference_id', $response->json('data.id'))
            ->where('reason', MovementReason::POSReturn)
            ->sole();
        self::assertSame('1.234568', (string) $returnMovement->unit_cost);
        self::assertSame('2.469136', (string) $returnMovement->total_cost);
    }

    // ---------------------------------------------------------------
    // Task 2a — refund_request_id + refund_destination HTTP contract
    // ---------------------------------------------------------------

    public function test_process_return_requires_refund_request_id(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                ['line_id' => $line->id, 'quantity' => '1'],
            ],
        ]);
        unset($payload['refund_request_id']);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $payload,
        );

        $this->assertApiValidationErrors($response, ['refund_request_id']);
    }

    public function test_process_return_rejects_exchange_deferred_destination(): void
    {
        // exchange_deferred is reserved for ExchangeService (service-layer only)
        // and must never be reachable over HTTP.
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::Defective->value,
                'lines' => [
                    ['line_id' => $line->id, 'quantity' => '1'],
                ],
                'refund_destination' => 'exchange_deferred',
            ]),
        );

        $this->assertApiValidationErrors($response, ['refund_destination']);
    }

    public function test_process_return_idempotent_replay_returns_same_receipt_without_second_payout(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $refundRequestId = Str::uuid()->toString();
        $payload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                ['line_id' => $line->id, 'quantity' => '2'],
            ],
            'refund_request_id' => $refundRequestId,
        ]);

        $response1 = $this->postJson("/api/v1/pos/receipts/{$saleReceipt->id}/return", $payload);
        $response1->assertStatus(201);

        // Replay the exact same request (e.g. POS retry after network blip).
        $response2 = $this->postJson("/api/v1/pos/receipts/{$saleReceipt->id}/return", $payload);
        $response2->assertStatus(201);

        // Same return receipt returned both times.
        $this->assertSame($response1->json('data.id'), $response2->json('data.id'));

        // Only ONE return receipt was created.
        $this->assertSame(1, Receipt::where('original_receipt_id', $saleReceipt->id)
            ->where('receipt_type', ReceiptType::Return)
            ->count());
        $this->assertSame(1, Receipt::where('refund_request_id', $refundRequestId)->count());

        // Only ONE cash-drawer payout was recorded (no second payout on replay).
        $this->assertSame(1, (int) DB::table('pos_cash_drawer_operations')
            ->where('operation_type', 'REFUND')
            ->count());
    }

    public function test_process_return_replays_when_concurrent_request_lands_after_unlocked_idempotency_check(): void
    {
        // Race shape: two requests carry the same refund_request_id. Both pass
        // the cheap UNLOCKED idempotency check; the loser then blocks on the
        // original-receipt lockForUpdate while the winner commits. The
        // AUTHORITATIVE post-lock re-check must catch the winner's committed
        // return receipt and replay it — never insert a duplicate (which would
        // hit the unique partial index → uncaught QueryException → 500 and,
        // on SQLite where that index does not exist, a SECOND payout).
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        // The "winning" concurrent request, committed normally under its own key.
        $response1 = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::Defective->value,
                'lines' => [
                    ['line_id' => $line->id, 'quantity' => '2'],
                ],
            ]),
        );
        $response1->assertStatus(201);
        /** @var string $winnerReturnId */
        $winnerReturnId = $response1->json('data.id');

        $replayKey = Str::uuid()->toString();

        // Build the loser's payload BEFORE registering the race hook so the
        // approval-evidence seeding cannot trip it.
        $loserPayload = $this->withVoidReturnApproval($saleReceipt, [
            'terminal_id' => $this->terminal->id,
            'return_reason' => ReturnReason::Defective->value,
            'lines' => [
                ['line_id' => $line->id, 'quantity' => '2'],
            ],
            'refund_request_id' => $replayKey,
        ]);

        // Deterministic race injection: flip the winner's refund_request_id to
        // the loser's key at the exact moment the loser retrieves the original
        // receipt INSIDE the service transaction (DB::transactionLevel above
        // the test's base level distinguishes the service's locked load from
        // the controller's unlocked pre-load). This makes the conflicting row
        // visible only AFTER the Step-1 unlocked check has already missed it.
        $baseLevel = DB::transactionLevel();
        $injected = false;
        Receipt::retrieved(
            function (Receipt $receipt) use (&$injected, $saleReceipt, $winnerReturnId, $replayKey, $baseLevel): void {
                if ($injected || $receipt->id !== $saleReceipt->id) {
                    return;
                }
                if (DB::transactionLevel() <= $baseLevel) {
                    return;
                }
                $injected = true;
                $isPgsql = DB::connection()->getDriverName() === 'pgsql';
                if ($isPgsql) {
                    DB::statement('ALTER TABLE pos_receipts DISABLE TRIGGER enforce_receipt_immutability');
                }
                try {
                    DB::table('pos_receipts')
                        ->where('id', $winnerReturnId)
                        ->update(['refund_request_id' => $replayKey]);
                } finally {
                    if ($isPgsql) {
                        DB::statement('ALTER TABLE pos_receipts ENABLE TRIGGER enforce_receipt_immutability');
                    }
                }
            },
        );

        $response2 = $this->postJson("/api/v1/pos/receipts/{$saleReceipt->id}/return", $loserPayload);

        $this->assertTrue($injected, 'Race hook must have fired inside the service transaction');
        $response2->assertStatus(201);

        // The loser replays the winner's receipt — same id, no duplicate row.
        $this->assertSame($winnerReturnId, $response2->json('data.id'));
        $this->assertSame(1, Receipt::where('original_receipt_id', $saleReceipt->id)
            ->where('receipt_type', ReceiptType::Return)
            ->count());
        $this->assertSame(1, Receipt::where('refund_request_id', $replayKey)->count());

        // Exactly ONE cash payout — the race must never pay out twice.
        $this->assertSame(1, (int) DB::table('pos_cash_drawer_operations')
            ->where('operation_type', 'REFUND')
            ->count());
    }

    public function test_process_return_cashier_selected_store_voucher_issues_voucher(): void
    {
        // Seed GL accounts required for VoucherIssuanceService.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        // No policy forcing — receipt is in-window and the default policy allows
        // all cashier-facing destinations. The voucher must come from the
        // cashier's explicit refund_destination choice.
        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::CustomerChangedMind->value,
                'lines' => [
                    ['line_id' => $line->id, 'quantity' => '1'],
                ],
                'refund_destination' => 'store_voucher',
            ]),
        );

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertNotNull(
            $data['issued_voucher'],
            'Cashier-selected store_voucher must surface the issued_voucher payload',
        );
        $this->assertIsString($data['issued_voucher']['code']);

        // No cash payout for a voucher refund.
        $this->assertSame(0, (int) DB::table('pos_cash_drawer_operations')
            ->where('operation_type', 'REFUND')
            ->count());
    }

    public function test_process_return_cashier_selected_original_payment_routes_treasury_proration(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        // Link a completed Treasury payment to the original receipt so the
        // proration path has something to refund against.
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Returning Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
        $cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $originalPayment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $cashMethod->id,
            'amount' => '119.000',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'reference' => 'PMT-'.Str::random(6),
        ]);
        ReceiptPayment::create([
            'receipt_id' => $saleReceipt->id,
            'payment_method_id' => $cashMethod->id,
            'payment_type' => 'cash',
            'amount' => '119.000',
            'treasury_payment_id' => $originalPayment->id,
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::CustomerChangedMind->value,
                'lines' => [
                    ['line_id' => $line->id, 'quantity' => '1'],
                ],
                'refund_destination' => 'original_payment',
            ]),
        );

        $response->assertStatus(201);

        // Treasury proration created a negative refund Payment row.
        $refundRows = Payment::where('company_id', $this->company->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->get();
        $this->assertCount(1, $refundRows);
        $this->assertTrue(
            bccomp((string) $refundRows->first()?->amount, '0', 3) < 0,
            'Refund payment amount must be negative',
        );

        // No cash-drawer payout when refunding to original payment.
        $this->assertSame(0, (int) DB::table('pos_cash_drawer_operations')
            ->where('operation_type', 'REFUND')
            ->count());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createReceiptLine(Receipt $receipt): ReceiptLine
    {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function withVoidReturnApproval(Receipt $receipt, array $requestData): array
    {
        $lineIds = collect($requestData['lines'] ?? [])
            ->pluck('line_id')
            ->map(static fn (mixed $lineId): string => (string) $lineId)
            ->sort()
            ->values()
            ->all();
        $reason = (string) ($requestData['notes'] ?? '');
        $target = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'line_ids' => $lineIds,
            'reason' => $reason,
        ];

        $approvalId = Str::uuid()->toString();
        $approvalEventId = Str::uuid()->toString();
        $overrideEventId = Str::uuid()->toString();

        $approvalPayload = [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->user->id,
            'supervisor_user_snapshot' => ['name' => $this->user->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, $approvalPayload);

        $overridePayload = [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'POS_RECEIPT_RETURN',
                'target_reference_id' => $receipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'supervisor_user_id' => $this->user->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent(
            $overrideEventId,
            FiscalEventType::OVERRIDE_VOID_OR_RETURN,
            $overridePayload,
            $approvalEventId,
        );

        return $requestData + [
            // Task 2a — refund_request_id is a required idempotency key on the
            // /return contract. Tests that need a stable id pass their own.
            'refund_request_id' => Str::uuid()->toString(),
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->user->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->user->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeFiscalEvent(
        string $id,
        FiscalEventType $eventType,
        array $payload,
        ?string $referenceEventId = null,
    ): void {
        FiscalEvent::query()->create([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'reference_event_id' => $referenceEventId,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => $payload,
            'payload_parse_status' => 'parsed',
        ]);
    }

    // ---------------------------------------------------------------
    // Setup helpers
    // ---------------------------------------------------------------

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        Permission::findOrCreate('pos.process_returns', 'sanctum');
        Permission::findOrCreate('pos.void_receipts', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');
        $this->user->givePermissionTo('pos.process_returns');
        $this->user->givePermissionTo('pos.void_receipts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        // Create an open shift on the terminal (required for return processing)
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private function createSaleReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'EUR',
        ]);
    }

    private function createReturnReceipt(Receipt $originalReceipt, bool $isVoided = false): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $originalReceipt->id,
            'return_reason' => ReturnReason::Defective,
            'subtotal' => '-20.000',
            'tax_amount' => '-3.800',
            'total' => '-23.800',
            'currency' => 'EUR',
            'is_voided' => $isVoided,
            'voided_at' => $isVoided ? now() : null,
            // voided rows need voided_by (pos_receipts_void_logic, PostgreSQL).
            'voided_by' => $isVoided ? $this->user->id : null,
            'fiscal_status' => $isVoided ? FiscalStatus::Voided : FiscalStatus::Fiscalized,
        ]);
    }
}
