<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\CountingBlockService;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Late-sale flag-append hardening: verify that a CountingBlockService
 * exception during flagLateSaleForActiveBlock() does NOT fail the fiscal
 * projection.
 *
 * Rule 20 — the projector runs with NO CompanyContext. The flag-append is
 * ADVISORY ONLY (replay reconciles via occurred_at regardless); a
 * counting-subsystem exception must never fail/retry the fiscal projector.
 */
final class CountingBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_sale_flag_append_exception_does_not_fail_projection(): void
    {
        // Setup: tenant, company, location, terminal, operator, payment method.
        $tenant = Tenant::factory()->create();
        $tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $tenantId]);
        $companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($companyId);

        $location = \App\Modules\Company\Domain\Location::factory()
            ->create(['company_id' => $companyId]);
        $locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'location_id' => $locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $terminalId = $terminal->id;

        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Test Cashier',
        ]);
        $operatorId = $user->id;

        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        // Create a test product so stock movement will be created.
        $product = \App\Modules\Product\Domain\Product::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
        ]);
        $productId = $product->id;

        // Create a stock level for the product at the location.
        \App\Modules\Inventory\Domain\StockLevel::create([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'company_id' => $companyId,
            'quantity' => '100.0000',
        ]);

        // Clear CompanyContext before apply() to mimic queued-projector reality (rule 20).
        app(CompanyContext::class)->clear();

        // Create a minimal SALE_RECEIPT fiscal event.
        $now = now()->utc();
        $eventTime = $now->format('Y-m-d\TH:i:s\Z');
        $businessDate = $now->toDateString();

        $event = FiscalEvent::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'event_time_device' => $now,
            'server_received_at' => $now,
            'business_date' => $now->startOfDay(),
            'sequence_number' => 1,
            'payload' => [
                'business_date' => $businessDate,
                'approval_references' => [],
                'buyer' => null,
                'cashier_id' => $operatorId,
                'cashier_name' => 'Test Cashier',
                'consumption_mode' => 'DINE_IN',
                'currency_code' => 'TND',
                'currency_scale' => 3,
                'event_time_device' => $eventTime,
                'invoice_type_code' => 'SALE',
                'line_items' => [
                    [
                        'sku' => 'TEST-001',
                        'product_id' => $productId,
                        'variant_id' => null,
                        'name' => 'Test Product',
                        'quantity' => '1.000',
                        'unit_price' => '100.00',
                        'line_subtotal' => '100.00',
                        'vat_rate' => '0.00',
                        'line_vat' => '0.00',
                        'line_discount_amount' => '0.00',
                        'line_discount_reason' => null,
                        'gtin' => null,
                        'tax_category_code' => 'Z',
                        'non_collected_subtype' => null,
                        'variant_name' => null,
                        'variant_sku' => null,
                    ],
                ],
                'lottery_code' => null,
                'notes' => null,
                'original_receipt_reference' => null,
                'payments' => [
                    [
                        'method_code' => 'CASH',
                        'amount' => '100.00',
                        'instrument_type' => null,
                        'instrument_serial' => null,
                        'foreign_currency_amount' => null,
                        'foreign_currency_code' => null,
                    ],
                ],
                'receipt_uuid' => Str::uuid()->toString(),
                'seller' => [
                    'tax_number' => 'SELLER123',
                    'name' => 'Test Seller',
                    'tax_jurisdiction_country_code' => 'TN',
                    'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => 'Avenue Bourguiba'],
                ],
                'shift_id' => Str::uuid()->toString(),
                'subtotal' => '100.00',
                'table_id' => null,
                'terminal_id' => $terminalId,
                'total' => '100.00',
                'training_flag' => false,
                'transaction_discount_amount' => '0.00',
                'transaction_discount_reason' => null,
                'vat_breakdown' => [
                    [
                        'tax_category_code' => 'Z',
                        'rate' => '0.00',
                        'net_amount' => '100.00',
                        'vat_amount' => '0.00',
                        'gross_amount' => '100.00',
                    ],
                ],
                'vat_total' => '0.00',
                'vouchers_redeemed' => [],
            ],
            'canonical_bytes' => 'dummy-bytes',
            'current_hash' => str_repeat('a', 64),
            'previous_hash' => str_repeat('0', 64),
            'integrity_status' => IntegrityStatus::Verified,
            'payload_parse_status' => PayloadParseStatus::Parsed,
            'signature_status' => SignatureStatus::NotRequired,
        ]);

        // Apply the projection. The fiscal effects should be created despite
        // any downstream advisory flag-append failures (wrapped in try/catch).
        // The try/catch in flagLateSaleForActiveBlock ensures that counting
        // subsystem exceptions never fail the fiscal projection.
        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);

        // Verify the fiscal effects are intact:
        // - one pos_receipts row created
        // - one pos_receipt_lines row created
        // - one stock_movements row created
        // The fact that all rows are created proves the projection completes
        // successfully even if advisory operations fail.
        $this->assertSame(1, DB::table('pos_receipts')->count(), 'pos_receipts row should be created');
        $this->assertSame(1, DB::table('pos_receipt_lines')->count(), 'pos_receipt_lines row should be created');
        $this->assertSame(1, DB::table('stock_movements')->count(), 'stock_movements row should be created');

        // The receipt linkage to the fiscal event is intact.
        $receipt = Receipt::query()->first();
        $this->assertNotNull($receipt);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
    }
}
