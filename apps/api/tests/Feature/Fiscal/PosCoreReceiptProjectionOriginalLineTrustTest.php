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
use App\Modules\Fiscal\Domain\Exceptions\OriginalLineUnresolvableException;
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
 * v3-refund-chain-integration spec §3.3/§12 — review round-2 CRITICAL 1:
 * the `original_line_index`/`product_id` TRUST HOLE closure.
 *
 * `FiscalPayloadConstraintValidator::validateOriginalLineReferences()`
 * only checks the refund's OWN payload for internal parallel-array
 * consistency (`original_line_references[i]` matches `line_items[i]` at
 * signing time) — it has NO visibility into the ORIGINAL receipt's real
 * projected `pos_receipt_lines` rows. A malformed or bypassed device could
 * still sign a structurally-valid-looking reference that points at the
 * WRONG position or the WRONG product on the original. This file proves
 * `PosCoreReceiptProjection::resolveOriginalLineForReference()` catches
 * both shapes server-side and throws
 * {@see OriginalLineUnresolvableException} (non-retryable) rather than
 * silently `continue`-ing past the bad reference (the pre-fix behavior,
 * which left `original_line_id` silently NULL and let a bogus reference
 * skip the §12 quantity cap entirely).
 *
 * PG-mode: mirrors `PosCoreReceiptProjectionRefundQuantityCapTest`'s own
 * PG-only stance — the cap check's `FOR UPDATE` lock is meaningless under
 * SQLite's whole-database serialization.
 *
 *   php artisan test -c phpunit-pgsql.xml --filter=PosCoreReceiptProjectionOriginalLineTrustTest
 */
final class PosCoreReceiptProjectionOriginalLineTrustTest extends TestCase
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

    public function test_out_of_range_original_line_index_throws_and_is_non_retryable(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        // The original has exactly ONE line (line_number=1, index 0). The
        // reference claims index 5 -- no line_number=6 exists.
        $refund = $this->v4RefundEvent(
            $sale,
            productId: $product->id,
            quantity: '2.000',
            sequenceNumber: 2,
            originalLineIndexOverride: 5,
        );

        $this->expectException(OriginalLineUnresolvableException::class);
        $this->expectExceptionMessageMatches('/no pos_receipt_lines row exists at that line_number/');
        $this->project($refund);

        // Nothing persisted -- the whole projection rolled back atomically.
        self::assertDatabaseMissing('pos_receipts', ['fiscal_event_id' => $refund->id]);
    }

    public function test_product_id_mismatch_on_a_valid_index_throws_and_is_non_retryable(): void
    {
        $originalProduct = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);
        $unrelatedProduct = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($originalProduct->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        // Valid index (0 -- the original's only line), but the reference
        // row claims a DIFFERENT, unrelated product than what is actually
        // at that position on the original.
        $refund = $this->v4RefundEvent(
            $sale,
            productId: $originalProduct->id,
            quantity: '2.000',
            sequenceNumber: 2,
            referenceProductIdOverride: $unrelatedProduct->id,
        );

        $this->expectException(OriginalLineUnresolvableException::class);
        $this->expectExceptionMessageMatches('/product_id mismatch/');
        $this->project($refund);

        self::assertDatabaseMissing('pos_receipts', ['fiscal_event_id' => $refund->id]);
    }

    public function test_a_reference_whose_local_fk_was_nulled_still_resolves_via_the_signed_payload_snapshot(): void
    {
        // fiscal re-verification IMPORTANT — writeLines() deliberately
        // NULLs pos_receipt_lines.product_id for deleted/ad-hoc/
        // cross-tenant products (its own comment, :1096-1105). A refund of
        // such an original must still PROJECT: the product_id check
        // compares against the ORIGINAL's own SIGNED PAYLOAD snapshot
        // (fiscal_events.payload.line_items[i].product_id), which is
        // chain-immutable and survives the local FK being suppressed.
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        $originalReceipt = Receipt::where('fiscal_event_id', $sale->id)->sole();
        DB::table('pos_receipt_lines')
            ->where('receipt_id', $originalReceipt->id)
            ->update(['product_id' => null]);

        $refund = $this->v4RefundEvent($sale, productId: $product->id, quantity: '2.000', sequenceNumber: 2);
        $this->project($refund);

        $refundReceipt = Receipt::where('fiscal_event_id', $refund->id)->sole();
        $refundLine = DB::table('pos_receipt_lines')->where('receipt_id', $refundReceipt->id)->sole();
        self::assertNotNull($refundLine->original_line_id, 'original_line_id must resolve even when the local FK is NULLed');

        $originalLine = DB::table('pos_receipt_lines')->where('receipt_id', $originalReceipt->id)->sole();
        self::assertSame((string) $originalLine->id, (string) $refundLine->original_line_id);
    }

    public function test_a_well_formed_reference_still_resolves_and_writes_original_line_id(): void
    {
        // Non-regression: the trust-hole closure must not break the
        // legitimate, well-formed case it sits alongside.
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        $refund = $this->v4RefundEvent($sale, productId: $product->id, quantity: '2.000', sequenceNumber: 2);
        $this->project($refund);

        $refundReceipt = Receipt::where('fiscal_event_id', $refund->id)->sole();
        $refundLine = DB::table('pos_receipt_lines')->where('receipt_id', $refundReceipt->id)->sole();
        self::assertNotNull($refundLine->original_line_id, 'original_line_id must never be silently null on a v4 refund');

        $originalReceipt = Receipt::where('fiscal_event_id', $sale->id)->sole();
        $originalLine = DB::table('pos_receipt_lines')->where('receipt_id', $originalReceipt->id)->sole();
        self::assertSame((string) $originalLine->id, (string) $refundLine->original_line_id);
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
        ?int $originalLineIndexOverride = null,
        ?string $referenceProductIdOverride = null,
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
                'original_line_index' => $originalLineIndexOverride ?? 0,
                'product_id' => $referenceProductIdOverride ?? $productId,
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
            'name' => 'Trust Hole Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sku' => 'SKU-TRUST',
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
