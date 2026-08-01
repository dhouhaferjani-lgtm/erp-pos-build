<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\RefundQuantityExceededException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §12 — per-original quantity cap.
 *
 * PG-mode: the `FOR UPDATE` lock this test exercises is meaningless under
 * SQLite's whole-database serialization — run via:
 *
 *   php artisan test -c phpunit-pgsql.xml --filter=PosCoreReceiptProjectionRefundQuantityCapTest
 *
 * Rule 20 — every `apply()` call clears `CompanyContext` first (the
 * projection must not depend on it — mirrors the worker reality).
 */
final class PosCoreReceiptProjectionRefundQuantityCapTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('§12 per-original FOR UPDATE lock is PG-only; run via phpunit-pgsql.xml.');
        }

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
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
    }

    public function test_refund_within_cap_succeeds(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        $refund = $this->v4RefundEvent($sale, $product->id, '3.000', sequenceNumber: 2);
        $this->project($refund);

        $refundReceipt = Receipt::where('fiscal_event_id', $refund->id)->sole();
        self::assertSame($refund->id, $refundReceipt->fiscal_event_id);
    }

    public function test_refund_exceeding_original_quantity_throws_and_is_non_retryable(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '2.000', sequenceNumber: 1);
        $this->project($sale);

        $refund = $this->v4RefundEvent($sale, $product->id, '3.000', sequenceNumber: 2);

        $this->expectException(RefundQuantityExceededException::class);
        $this->project($refund);
    }

    public function test_two_sequential_partial_refunds_up_to_the_cap_both_succeed(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '2.000', sequenceNumber: 1);
        $this->project($sale);

        $this->project($this->v4RefundEvent($sale, $product->id, '1.000', sequenceNumber: 2, receiptUuid: '00000000-0000-4000-8000-000000000002'));
        $this->project($this->v4RefundEvent($sale, $product->id, '1.000', sequenceNumber: 3, receiptUuid: '00000000-0000-4000-8000-000000000003'));

        self::assertSame(2, Receipt::where('original_receipt_id', Receipt::where('fiscal_event_id', $sale->id)->sole()->id)->count());
    }

    public function test_third_partial_refund_exceeding_cumulative_cap_throws(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '2.000', sequenceNumber: 1);
        $this->project($sale);

        $this->project($this->v4RefundEvent($sale, $product->id, '1.000', sequenceNumber: 2, receiptUuid: '00000000-0000-4000-8000-000000000002'));
        $this->project($this->v4RefundEvent($sale, $product->id, '1.000', sequenceNumber: 3, receiptUuid: '00000000-0000-4000-8000-000000000003'));

        $thirdRefund = $this->v4RefundEvent($sale, $product->id, '0.001', sequenceNumber: 4, receiptUuid: '00000000-0000-4000-8000-000000000004');

        $this->expectException(RefundQuantityExceededException::class);
        $this->project($thirdRefund);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function v4SaleEvent(string $productId, string $quantity, int $sequenceNumber): FiscalEvent
    {
        return $this->buildEvent(
            invoiceTypeCode: 'SALE',
            eventVersion: 3,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: '00000000-0000-4000-8000-000000000001',
            originalLineReferences: null,
            originalReceiptReference: null,
        );
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function v4RefundEvent(
        FiscalEvent $original,
        string $productId,
        string $quantity,
        int $sequenceNumber,
        string $receiptUuid = '00000000-0000-4000-8000-000000000002',
    ): FiscalEvent {
        return $this->buildEvent(
            invoiceTypeCode: 'REFUND',
            eventVersion: 4,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: $receiptUuid,
            originalLineReferences: [[
                'disposition' => 'restock',
                'original_line_index' => 0,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]],
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'customer return',
            ],
        );
    }

    /**
     * @param  numeric-string  $quantity
     * @param  list<array<string, mixed>>|null  $originalLineReferences
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function buildEvent(
        string $invoiceTypeCode,
        int $eventVersion,
        string $productId,
        string $quantity,
        int $sequenceNumber,
        string $receiptUuid,
        ?array $originalLineReferences,
        ?array $originalReceiptReference,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();

        $unitPrice = '10.00';
        $lineTotal = bcmul($unitPrice, $quantity, 2);

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => $lineTotal,
            'line_vat' => '0.00',
            'name' => 'Cap Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sku' => 'SKU-CAP',
            'tax_category_code' => 'Z',
            'unit_price' => $unitPrice,
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ];

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
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $lineTotal,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $lineTotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $lineTotal,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $lineTotal,
                'net_amount' => $lineTotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        if ($originalLineReferences !== null) {
            $payload['original_line_references'] = $originalLineReferences;
            $payload['refund_destination'] = 'cash';
            $payload['settlement_allocation'] = null;
        }

        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes.(string) $sequenceNumber);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
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
            'previous_hash' => str_repeat('0', 64),
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
}
