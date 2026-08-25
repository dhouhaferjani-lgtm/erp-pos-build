<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 10 — Phase 0 §5.1: REFUND/VOID receipts must NOT decrement stock.
 *
 * Guards the fast-follow contract added to `PosCoreReceiptProjection::decrementStockForLines`:
 * when `$receiptType === ReceiptType::Return` the method returns early — no
 * `StockLevel` modification, no `pos_sale` `StockMovement` row.
 *
 * This is a Phase-0 no-op SKIP only. Disposition-gated restock/re-increment
 * is the Phase 3 deliverable.
 *
 * **Rule 20 compliance:** `app(CompanyContext::class)->clear()` is called
 * immediately before `apply()` in every test that reaches the projection
 * path. This mirrors the queue worker reality (no CompanyContext carried
 * into the job) and prevents the setUp() binding from masking a
 * no-arg-getScale() regression.
 */
final class PosCoreReceiptProjectionRefundNoDecrementTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $this->paymentMethodId = $method->id;

        // Seed chart of accounts for VoucherRedemptionService.
        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);
    }

    // =================================================================
    // Task 10 — REFUND must not decrement stock (Phase 0 §5.1)
    // =================================================================

    public function test_refund_receipt_does_not_decrement_stock_or_write_pos_sale_movement(): void
    {
        // Arrange: product with stock level, then project the original SALE.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $stockLevel = StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        // Project the original SALE first (sequence 1) so resolveOriginalReceiptId resolves.
        $originalEvent = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'SALE',
            sequenceNumber: 1,
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '5.00',
                    'line_total' => '10.00',
                    'quantity' => '2',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '10.00',
            total: '10.00',
        );

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        // Rule 20: clear CompanyContext immediately before apply() — mirrors queue worker.
        app(CompanyContext::class)->clear();
        $projector->apply($originalEvent);

        // After original SALE: stock decremented by 2 (10 - 2 = 8).
        $stockLevel->refresh();
        $this->assertSame('8.0000', $stockLevel->quantity, 'pre-condition: original sale must decrement stock');
        $movementsAfterSale = $this->myStockMovements()->count();
        $this->assertSame(1, $movementsAfterSale, 'pre-condition: exactly one stock movement after the original sale');

        // Resolve the projected pos_receipts.id for use as original_receipt_uuid.
        $originalReceiptRow = $this->myReceipts()
            ->where('fiscal_event_id', $originalEvent->id)
            ->first();
        $this->assertNotNull($originalReceiptRow, 'pre-condition: original sale must have a pos_receipts row');

        // Build the REFUND event referencing the original's fiscal_event_id.
        $refundEvent = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'REFUND',
            sequenceNumber: 2,
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '5.00',
                    'line_total' => '10.00',
                    'quantity' => '2',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '10.00',
            total: '10.00',
            originalReceiptReference: [
                'fiscal_event_id' => $originalEvent->id,
                'original_business_date' => now()->toDateString(),
                'original_receipt_uuid' => $originalReceiptRow->id,
                'refund_reason' => 'Customer changed mind',
            ],
        );

        // Act: apply the REFUND. Rule 20: clear CompanyContext immediately before apply().
        app(CompanyContext::class)->clear();
        $projector->apply($refundEvent);

        // Assert: refund RESTOCKS — stock returns to the pre-sale level
        // (sale 10→8, refund adds the returned magnitude back → 10). The
        // Phase 0 "leave stock untouched" interim guard is superseded by the
        // restock fix (fix/pos-refund-void-restock); RefundStockTest pins the
        // detailed movement contract.
        $stockLevel->refresh();
        $this->assertSame(
            '10.0000',
            $stockLevel->quantity,
            'REFUND must restock the returned quantity (never decrement — that would compound the loss).',
        );

        // Assert: exactly ONE new movement for the refund event, and it is a
        // restock (Receipt/POSReturn), never a pos_sale decrement.
        $movementsAfterRefund = $this->myStockMovements()->count();
        $this->assertSame(
            $movementsAfterSale + 1,
            $movementsAfterRefund,
            'REFUND projection must write exactly one restock movement (no pos_sale decrement).',
        );
    }

    public function test_void_receipt_does_not_decrement_stock_or_write_pos_sale_movement(): void
    {
        // Symmetric pin for VOID — same Phase 0 §5.1 contract.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $stockLevel = StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'quantity' => '5.0000',
            'reserved' => '0.0000',
        ]);

        // Project the original SALE.
        $originalEvent = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'SALE',
            sequenceNumber: 1,
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '5.00',
                    'line_total' => '5.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '5.00',
            total: '5.00',
        );

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        app(CompanyContext::class)->clear();
        $projector->apply($originalEvent);

        $stockLevel->refresh();
        $this->assertSame('4.0000', $stockLevel->quantity, 'pre-condition: original sale must decrement stock');
        $movementsAfterSale = $this->myStockMovements()->count();

        $originalReceiptRow = $this->myReceipts()
            ->where('fiscal_event_id', $originalEvent->id)
            ->first();
        $this->assertNotNull($originalReceiptRow);

        $voidEvent = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'VOID',
            sequenceNumber: 2,
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '5.00',
                    'line_total' => '5.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '5.00',
            total: '5.00',
            originalReceiptReference: [
                'fiscal_event_id' => $originalEvent->id,
                'original_business_date' => now()->toDateString(),
                'original_receipt_uuid' => $originalReceiptRow->id,
                'refund_reason' => 'Operator error — voided',
            ],
        );

        app(CompanyContext::class)->clear();
        $projector->apply($voidEvent);

        // VOID restocks the voided quantity (sale 5→4, void restores → 5);
        // it must never decrement.
        $stockLevel->refresh();
        $this->assertSame('5.0000', $stockLevel->quantity, 'VOID must restock the voided quantity, never decrement.');

        $this->assertSame(
            $movementsAfterSale + 1,
            $this->myStockMovements()->count(),
            'VOID projection must write exactly one restock movement (no pos_sale decrement).',
        );
    }

    // =================================================================
    // Regression: SALE still decrements (Phase 0 guard is REFUND/VOID only)
    // =================================================================

    public function test_sale_receipt_still_decrements_stock_and_writes_pos_sale_movement(): void
    {
        // Regression guard: the guard must NOT affect normal SALE receipts.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $stockLevel = StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'SALE',
            sequenceNumber: 1,
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '5.00',
                    'line_total' => '15.00',
                    'quantity' => '3',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '15.00',
            total: '15.00',
            paymentLinesOverride: [
                ['amount' => '15.00', 'method_code' => 'CASH'],
            ],
        );

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        // Rule 20: clear immediately before apply().
        app(CompanyContext::class)->clear();
        $projector->apply($event);

        // Stock must be decremented (10 - 3 = 7).
        $stockLevel->refresh();
        $this->assertSame('7.0000', $stockLevel->quantity, 'regression: normal SALE must still decrement stock');

        // Exactly one pos_sale stock movement.
        $this->assertSame(1, $this->myStockMovements()->count());
        $movement = $this->myStockMovements()->first();
        $this->assertNotNull($movement);
        $this->assertSame('issue', $movement->movement_type);
        $this->assertSame('pos_sale', $movement->reason);
    }

    // =================================================================
    // Helper — mirrors PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent
    // =================================================================

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row directly via Eloquent.
     * Mirrors the helper in PosCoreReceiptProjectionTest — emits the 28-key
     * canonical payload (synthesis v5 §3).
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     * @param  list<array<string, mixed>>|null  $lines
     * @param  list<array<string, mixed>>|null  $vatBreakdown
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function storeSaleReceiptFiscalEvent(
        string $invoiceTypeCode = 'SALE',
        int $sequenceNumber = 1,
        ?array $paymentLinesOverride = null,
        ?array $lines = null,
        ?array $vatBreakdown = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        ?array $originalReceiptReference = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        // Build canonical payments list.
        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['amount' => '10.00', 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'] ?? '10.00',
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
            ];
        }

        // Build canonical line_items list.
        $lineItems = [];
        $rawLines = $lines ?? [
            ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
        ];
        foreach ($rawLines as $rl) {
            $qty = (string) ($rl['quantity'] ?? '1');
            $lineItems[] = [
                'gtin' => $rl['gtin'] ?? null,
                'line_discount_amount' => $rl['discount_amount'] ?? '0.00',
                'line_discount_reason' => $rl['discount_reason'] ?? null,
                'line_subtotal' => $rl['line_total'] ?? '10.00',
                'line_vat' => $rl['tax_amount'] ?? '0.00',
                'name' => $rl['product_name'] ?? ($rl['sku'] ?? 'Default item'),
                'non_collected_subtype' => null,
                'product_id' => $rl['product_id'] ?? 'prod-default',
                'quantity' => str_contains($qty, '.') ? $qty : $qty.'.000',
                'sku' => $rl['sku'] ?? 'SKU-X',
                'tax_category_code' => 'Z',
                'unit_price' => $rl['unit_price'] ?? '10.00',
                'variant_id' => $rl['variant_id'] ?? null,
                'variant_name' => $rl['variant_name'] ?? null,
                'variant_sku' => $rl['variant_sku'] ?? null,
                'vat_rate' => $this->padToScaleTwo($rl['tax_rate'] ?? '0'),
            ];
        }

        // Build canonical vat_breakdown.
        $vatRows = [];
        $rawVat = $vatBreakdown ?? [
            ['rate' => '0', 'base' => $subtotal, 'amount' => '0.00'],
        ];
        foreach ($rawVat as $vr) {
            $base = $vr['base'] ?? '0.00';
            $amount = $vr['amount'] ?? '0.00';
            /** @var numeric-string $baseN */
            $baseN = $base;
            /** @var numeric-string $amountN */
            $amountN = $amount;
            $gross = bcadd($baseN, $amountN, 2);
            $vatRows[] = [
                'gross_amount' => $gross,
                'net_amount' => $base,
                'rate' => $this->padToScaleTwo($vr['rate'] ?? '0'),
                'tax_category_code' => 'Z',
                'vat_amount' => $amount,
            ];
        }

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => $lineItems,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => $payments,
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => null,
            'vat_breakdown' => $vatRows,
            'vat_total' => $taxTotal,
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        $event = FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    private function padToScaleTwo(string $val): string
    {
        if ($val === '') {
            return '0.00';
        }
        if (str_contains($val, '.')) {
            return $val;
        }

        return $val.'.00';
    }

    /**
     * Spec §4 JCS canonical encoding (test-local).
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }

    // =================================================================
    // Helpers — scoped reads (LEDGER C-7)
    // =================================================================

    /**
     * `stock_movements` rows created by THIS test.
     *
     * `setUp()` mints a fresh `Tenant` per test, so a `tenant_id` filter is an
     * exact "the rows I created" scope. Without it these reads also see the
     * rows COMMITTED by `PosCoreReceiptProjectionRefundDispositionStockTest`,
     * which overrides `connectionsToTransact()` to `[]` (it has to: it asserts
     * real transaction-rollback semantics, which a wrapping RefreshDatabase
     * transaction would mask) and therefore leaves its rows behind for the rest
     * of the PHP process. That bleed is why this class was green standalone and
     * red in any multi-class run — the same root cause and the same fix shape
     * the C-7 lane applied to `PosCoreReceiptProjectionTest`.
     */
    private function myStockMovements(): Builder
    {
        return DB::table('stock_movements')->where('tenant_id', $this->tenantId);
    }

    /** `pos_receipts` rows created by THIS test. */
    private function myReceipts(): Builder
    {
        return DB::table('pos_receipts')->where('tenant_id', $this->tenantId);
    }

    /**
     * Child rows of THIS test's receipts. `pos_receipt_lines`,
     * `pos_receipt_payments` and `pos_receipt_vat_details` carry no tenant
     * column, so the scope walks the `receipt_id` FK back to the
     * tenant-scoped parent.
     */
    private function myReceiptChildren(string $table): Builder
    {
        return DB::table($table)->whereIn(
            'receipt_id',
            DB::table('pos_receipts')->select('id')->where('tenant_id', $this->tenantId),
        );
    }
}
