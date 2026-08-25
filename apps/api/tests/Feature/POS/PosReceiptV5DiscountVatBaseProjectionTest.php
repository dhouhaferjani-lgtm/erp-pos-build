<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\Services\SaleReceiptForwardVersionGate;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * D-1 (owner ruling 2026-08-25) — the read model behind the DGI declaration.
 *
 * `EloquentVatDataRepository` reads `pos_receipt_vat_details` VERBATIM, so what
 * this projector writes IS what the tenant declares. Three things have to hold
 * at once:
 *
 *   1. a v5 event lands its POST-remise base and VAT, with each row carrying
 *      its ventilated share, and the header aggregates match Σ details;
 *   2. a v3 event still projects unchanged (FORWARD-ONLY — a device on an older
 *      build has no other shape to author), with `discount_allocated` NULL;
 *   3. a 100 %-comp v5 receipt projects with NO `pos_receipt_payments` row, so
 *      the `CHECK (amount > 0)` that made it unprojectable (G3-A) is satisfied
 *      by absence rather than weakened.
 *
 * PG-MEANINGFUL BY CONSTRUCTION: (1) only passes if `pos_receipts_totals`
 * admits `total = subtotal + tax_amount + rounding` (the D-1 widening), and (3)
 * only passes if the payments CHECK is never reached. Both are PostgreSQL-only
 * constraints — run this class on PG, not just sqlite.
 *
 * Rule 20: the projector runs on a Horizon worker with NO `CompanyContext`;
 * `project()` clears it first so the tests reproduce the worker reality.
 */
final class PosReceiptV5DiscountVatBaseProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
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

        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
        ]);
    }

    // =================================================================
    // The ruling, end to end
    // =================================================================

    public function test_v5_projects_the_post_remise_base_and_the_ventilated_shares(): void
    {
        $event = $this->storeEvent($this->workedExamplePayload(), 5);

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        self::assertSame(0, bccomp($this->numeric($receipt->subtotal), '524.547', 3));
        self::assertSame(0, bccomp($this->numeric($receipt->tax_amount), '65.453', 3));
        self::assertSame(0, bccomp($this->numeric($receipt->discount_amount), '50.000', 3));
        self::assertSame(0, bccomp($this->numeric($receipt->total), '590.000', 3));

        $rows = DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $receipt->id)
            ->orderBy('tax_rate')
            ->get();

        self::assertCount(4, $rows);
        $byRate = [];
        foreach ($rows as $row) {
            $byRate[(string) bcadd((string) $row->tax_rate, '0', 2)] = $row;
        }

        // The sealed ventilation, mirrored verbatim.
        self::assertSame(0, bccomp((string) $byRate['0.00']->net_amount, '63.609', 3));
        self::assertSame(0, bccomp((string) $byRate['0.00']->vat_amount, '0.000', 3));
        self::assertSame(0, bccomp((string) $byRate['0.00']->discount_allocated, '5.391', 3));
        self::assertSame(0, bccomp((string) $byRate['19.00']->net_amount, '184.375', 3));
        self::assertSame(0, bccomp((string) $byRate['19.00']->vat_amount, '35.031', 3));
        self::assertSame(0, bccomp((string) $byRate['19.00']->discount_allocated, '18.594', 3));

        // Header == Σ details, which is what the declaration and the ledger
        // both reconcile against.
        $sumNet = '0.000';
        $sumVat = '0.000';
        $sumDiscount = '0.000';
        foreach ($rows as $row) {
            $sumNet = bcadd($sumNet, (string) $row->net_amount, 3);
            $sumVat = bcadd($sumVat, (string) $row->vat_amount, 3);
            $sumDiscount = bcadd($sumDiscount, (string) $row->discount_allocated, 3);
        }
        self::assertSame(0, bccomp($sumNet, $this->numeric($receipt->subtotal), 3));
        self::assertSame(0, bccomp($sumVat, $this->numeric($receipt->tax_amount), 3));
        self::assertSame(0, bccomp($sumDiscount, '50.000', 3));

        // The whole point: 65.453 declared, not the pre-D-1 71.000.
        self::assertSame(-1, bccomp($sumVat, '71.000', 3));
    }

    /**
     * FORWARD-ONLY: the pre-D-1 shape still projects, and its rows say NULL —
     * "this version had no concept of ventilation", never a fabricated 0.000.
     */
    public function test_v3_still_projects_and_leaves_discount_allocated_null(): void
    {
        $event = $this->storeEvent($this->preDiscountBasePayload(), 3);

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        self::assertSame(0, bccomp($this->numeric($receipt->subtotal), '569.000', 3));
        self::assertSame(0, bccomp($this->numeric($receipt->tax_amount), '71.000', 3));

        $rows = DB::table('pos_receipt_vat_details')->where('receipt_id', $receipt->id)->get();
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertNull($row->discount_allocated);
        }
    }

    /**
     * G3-A fold-in. Before D-1 the device emitted a `0.000` tender leg for a
     * full comp and `pos_receipt_payments CHECK (amount > 0)` refused the row,
     * taking the whole projection down with it on PostgreSQL. The comp now
     * carries NO tender line at all — the CHECK is untouched and simply never
     * reached.
     */
    public function test_a_fully_comped_v5_receipt_projects_with_no_payment_row(): void
    {
        $event = $this->storeEvent($this->fullyCompedPayload(), 5);

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        self::assertSame(0, bccomp($this->numeric($receipt->total), '0.000', 3));
        self::assertSame(0, bccomp($this->numeric($receipt->discount_amount), '640.000', 3));
        self::assertSame(0, DB::table('pos_receipt_payments')->where('receipt_id', $receipt->id)->count());

        // The CHECK is still in force — proven by trying to insert the very row
        // the device used to emit.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->expectException(QueryException::class);
            DB::table('pos_receipt_payments')->insert([
                'id' => (string) Str::uuid(),
                'receipt_id' => $receipt->id,
                'payment_method_id' => (string) DB::table('payment_methods')->value('id'),
                'payment_type' => 'Cash',
                'payment_method_code' => 'CASH',
                'amount' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // =================================================================
    // Forward-only version gate
    // =================================================================

    public function test_the_gate_admits_v3_from_a_terminal_that_has_never_authored_v5(): void
    {
        $this->storeEvent($this->preDiscountBasePayload(), 3);

        self::assertNull(app(SaleReceiptForwardVersionGate::class)->verdict(
            $this->envelopeFor(3, 2),
        ));
    }

    public function test_the_gate_refuses_a_v3_downgrade_once_the_terminal_has_authored_v5(): void
    {
        $this->storeEvent($this->workedExamplePayload(), 5);

        $verdict = app(SaleReceiptForwardVersionGate::class)->verdict($this->envelopeFor(3, 2));

        self::assertNotNull($verdict);
        self::assertMatchesRegularExpression('/sale_receipt_version_downgrade/', $verdict);
    }

    public function test_the_gate_never_blocks_a_v4_refund_or_a_v5_sale(): void
    {
        $this->storeEvent($this->workedExamplePayload(), 5);

        $gate = app(SaleReceiptForwardVersionGate::class);
        self::assertNull($gate->verdict($this->envelopeFor(4, 2)));
        self::assertNull($gate->verdict($this->envelopeFor(5, 2)));
    }

    public function test_the_gate_is_scoped_to_the_terminal_that_authored_the_watermark(): void
    {
        $this->storeEvent($this->workedExamplePayload(), 5);

        $other = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => Location::factory()->create(['company_id' => $this->companyId])->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);

        $envelope = $this->envelopeFor(3, 2);
        $envelope->terminalId = $other->id;

        self::assertNull(app(SaleReceiptForwardVersionGate::class)->verdict($envelope));
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function envelopeFor(int $eventVersion, int $sequenceNumber): FiscalEventEnvelope
    {
        return new FiscalEventEnvelope(
            envelopeId: (string) Str::uuid(),
            idempotencyKey: $this->terminalId.':'.$sequenceNumber,
            payloadVersion: 1,
            id: (string) Str::uuid(),
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            terminalId: $this->terminalId,
            operatorId: $this->operatorId,
            eventType: FiscalEventType::SALE_RECEIPT,
            eventVersion: $eventVersion,
            signatureVersion: 'hash-chain-integrity-v1',
            sequenceNumber: $sequenceNumber,
            eventTimeDevice: now()->utc()->format('Y-m-d\TH:i:s\Z'),
            businessDate: now()->utc()->toDateString(),
            chainContext: 'operational',
            lastServerTimeSeen: null,
            referenceEventId: null,
            referenceDocumentId: null,
            sourceEventClass: null,
            sourceEventId: null,
            previousHash: str_repeat('0', 64),
            currentHash: str_repeat('1', 64),
            canonicalBytes: '{}',
        );
    }

    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();
        app(PosCoreReceiptProjection::class)->apply($event);
    }

    private function numeric(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail(sprintf('Expected a numeric value, got %s.', var_export($value, true)));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeEvent(array $payload, int $eventVersion): FiscalEvent
    {
        static $sequence = 0;
        $sequence++;

        $eventTime = now()->utc();
        $canonical = [
            'business_date' => $eventTime->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequence,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];
        $bytes = json_encode($this->sortRecursive($canonical), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($bytes === false) {
            throw new RuntimeException('canonical encode failed');
        }

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequence,
            'event_time_device' => $eventTime,
            'business_date' => $eventTime->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $bytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $bytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
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

    /** @return array<string, mixed> */
    private function workedExamplePayload(): array
    {
        return $this->basePayload('590.000', '524.547', '65.453', '50.000', 'Geste commercial', [
            ['0.00', 'EXEMPT', '5.391', '63.609', '0.000', '69.000', '0.000'],
            ['13.00', '', '17.656', '184.375', '23.969', '200.000', '26.000'],
            ['19.00', '', '18.594', '184.375', '35.031', '200.000', '38.000'],
            ['7.00', '', '8.359', '92.188', '6.453', '100.000', '7.000'],
        ], [['amount' => '590.000', 'method_code' => 'CASH']], 5);
    }

    /** @return array<string, mixed> */
    private function preDiscountBasePayload(): array
    {
        return $this->basePayload('590.000', '569.000', '71.000', '50.000', 'Geste commercial', [
            ['0.00', 'EXEMPT', '0.000', '69.000', '0.000', '69.000', '0.000'],
            ['13.00', '', '0.000', '200.000', '26.000', '200.000', '26.000'],
            ['19.00', '', '0.000', '200.000', '38.000', '200.000', '38.000'],
            ['7.00', '', '0.000', '100.000', '7.000', '100.000', '7.000'],
        ], [['amount' => '590.000', 'method_code' => 'CASH']], 3);
    }

    /** @return array<string, mixed> */
    private function fullyCompedPayload(): array
    {
        return $this->basePayload('0.000', '0.000', '0.000', '640.000', 'Comp direction', [
            ['0.00', 'EXEMPT', '69.000', '0.000', '0.000', '69.000', '0.000'],
            ['13.00', '', '226.000', '0.000', '0.000', '200.000', '26.000'],
            ['19.00', '', '238.000', '0.000', '0.000', '200.000', '38.000'],
            ['7.00', '', '107.000', '0.000', '0.000', '100.000', '7.000'],
        ], [], 5);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>  $vatBreakdown
     * @param  list<array{amount: string, method_code: string}>  $payments
     * @return array<string, mixed>
     */
    private function basePayload(
        string $total,
        string $subtotal,
        string $vatTotal,
        string $discount,
        string $discountReason,
        array $vatBreakdown,
        array $payments,
        int $eventVersion,
    ): array {
        $lines = [];
        $rows = [];
        foreach ($vatBreakdown as $i => [$rate, $category, $allocated, $net, $vat, $lineNet, $lineVat]) {
            $lines[] = [
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $lineNet,
                'line_vat' => $lineVat,
                'name' => 'Article '.$rate,
                'non_collected_subtype' => null,
                'product_id' => 'prod-'.$rate,
                'quantity' => '1.000',
                'sku' => 'SKU-'.$rate,
                'tax_category_code' => $category,
                'unit_price' => bcadd($lineNet, $lineVat, 3),
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => $rate,
            ];
            $row = [
                'gross_amount' => bcadd($net, $vat, 3),
                'net_amount' => $net,
                'rate' => $rate,
                'tax_category_code' => $category,
                'vat_amount' => $vat,
            ];
            if ($eventVersion >= 5) {
                $row['discount_allocated'] = $allocated;
            }
            $rows[] = $row;
            unset($i);
        }

        return [
            'approval_references' => [],
            'business_date' => now()->utc()->toDateString(),
            'buyer' => null,
            'cash_rounding_adjustment' => '0.000',
            'cash_rounding_denomination' => '0.000',
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'Test Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'invoice_type_code' => 'SALE',
            'line_items' => $lines,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => array_map(static fn (array $p): array => [
                'amount' => $p['amount'],
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $p['method_code'],
            ], $payments),
            'receipt_uuid' => (string) Str::uuid(),
            'seller' => [
                'address' => [
                    'city' => 'Tunis',
                    'country_code' => 'TN',
                    'postal_code' => '1000',
                    'street' => '1 avenue Habib Bourguiba',
                ],
                'name' => 'Cafe Tunis SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => (string) Str::uuid(),
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $discount,
            'transaction_discount_reason' => $discountReason,
            'vat_breakdown' => $rows,
            'vat_total' => $vatTotal,
            'vouchers_redeemed' => [],
        ];
    }
}
