<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 21 — `PosCoreReceiptProjection::apply()` (spec v7 §7.5 + §13 + SoT §13.6/D16).
 *
 * Verifies the **always-active** POS-core projector that translates a
 * verified `SALE_RECEIPT` fiscal event into:
 *   - one `pos_receipts` projection row (with the Task 11 `fiscal_event_id`
 *     linkage column and mirror columns `fiscal_hash` / `previous_hash` /
 *     `chain_sequence` populated from `$event`)
 *   - one row per line in `pos_receipt_lines`
 *   - one row per VAT rate in `pos_receipt_vat_details`
 *   - one row per payment line in `pos_receipt_payments` (the single owner
 *     of `ReceiptPayment` row creation regardless of input path)
 *   - voucher redemption (`store_voucher` instruments only)
 *   - stock movement (`product_id` lines only)
 *
 * **Idempotency.** The durable guard is the `pos_receipts.fiscal_event_id
 * UNIQUE` column added in Task 11. Calling `apply()` a second time with
 * the same `FiscalEvent` is a no-op — verified end-to-end below.
 *
 * **Boundary discipline.** The projector imports ZERO Treasury / Accounting
 * / Sales operational classes (only the `PaymentMethod` model for inbound
 * mirrored reference data lookup — permitted by SoT §13.6/D16). The
 * Treasury `Payment` row + GL is owned by `TreasuryReceiptBridge`
 * (Task 22); no Treasury `payments` row is created here.
 */
final class PosCoreReceiptProjectionTest extends TestCase
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

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        // The fiscal event's operator_id is FK-constrained on `pos_receipts.cashier_id`
        // (foreign('users')->restrictOnDelete()), so we need a real user row.
        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        // PaymentMethod scoped to the same tenant + company — the projector's
        // SoT §13.6/D16 inbound mirror lookup walks (tenant, company, id).
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $this->paymentMethodId = $method->id;
    }

    // =================================================================
    // Plan §1571 cases (four)
    // =================================================================

    public function test_applies_pos_core_effects_exactly_once(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent();

        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);

        // Exactly one pos_receipts row with canonical_bytes mirrored from the event.
        $this->assertSame(
            1,
            DB::table('pos_receipts')->whereNotNull('canonical_bytes')->count(),
        );

        $this->assertGreaterThan(0, DB::table('pos_receipt_lines')->count());
        $this->assertGreaterThan(0, DB::table('pos_receipt_payments')->count());

        $receipt = DB::table('pos_receipts')->first();
        $this->assertNotNull($receipt);
        // Mirror columns populated from the authoritative fiscal_events row.
        $this->assertSame($event->current_hash, $receipt->fiscal_hash);
        $this->assertSame($event->previous_hash, $receipt->previous_hash);
        $this->assertSame($event->sequence_number, (int) $receipt->chain_sequence);
        // canonical_bytes mirrors the verbatim canonical encoding from the event.
        $bytes = is_resource($receipt->canonical_bytes)
            ? stream_get_contents($receipt->canonical_bytes)
            : (string) $receipt->canonical_bytes;
        $this->assertSame($event->canonical_bytes, $bytes);
    }

    public function test_apply_links_pos_receipt_to_the_fiscal_event(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent();

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = DB::table('pos_receipts')->first();
        $this->assertNotNull($receipt);
        // The Task 11 fiscal_event_id linkage column is the idempotency anchor.
        $this->assertSame($event->id, $receipt->fiscal_event_id);
    }

    public function test_apply_is_idempotent_via_the_fiscal_event_id_guard(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent();
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $projector->apply($event);
        $paymentRowsAfterFirst = DB::table('pos_receipt_payments')->count();
        $lineRowsAfterFirst = DB::table('pos_receipt_lines')->count();
        $vatRowsAfterFirst = DB::table('pos_receipt_vat_details')->count();
        $stockMovementsAfterFirst = DB::table('stock_movements')->count();

        // Second run — the pos_receipts.fiscal_event_id UNIQUE-backed guard
        // finds the existing row and skips. No exceptions, no duplicate rows.
        $projector->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame($paymentRowsAfterFirst, DB::table('pos_receipt_payments')->count());
        $this->assertSame($lineRowsAfterFirst, DB::table('pos_receipt_lines')->count());
        $this->assertSame($vatRowsAfterFirst, DB::table('pos_receipt_vat_details')->count());
        $this->assertSame($stockMovementsAfterFirst, DB::table('stock_movements')->count());
    }

    public function test_runs_to_completion_with_treasury_inactive(): void
    {
        // PosCoreReceiptProjection depends only on mirrored reference data
        // (`payment_methods` lookup — the SoT §13.6/D16 inbound seam), not
        // the Treasury operational module. With no Treasury services
        // resolved and no `payments` rows ever written, the projection
        // still completes.
        $event = $this->storeSaleReceiptFiscalEvent();

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count());
        // ZERO Treasury Payment rows — that's the TreasuryReceiptBridge's job
        // (Task 22), which runs only when the Treasury module is active.
        $this->assertSame(0, DB::table('payments')->count());
    }

    // =================================================================
    // Standing-pattern defense cases (handoff §4.2)
    // =================================================================

    public function test_projector_publishes_canonical_metadata(): void
    {
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $this->assertSame('pos_core_receipt', $projector->name());
        $this->assertTrue($projector->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertFalse($projector->handlesEventType(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertFalse($projector->handlesEventType(FiscalEventType::REFUND_RECEIPT));
        // POS-core projector — always-active. Null token signals "skip the
        // ModuleActivationResolver gate" to FiscalEventProjectionRegistry.
        $this->assertNull($projector->requiresModule());
    }

    public function test_projector_is_registered_as_a_fiscal_event_projector_tag(): void
    {
        // Provider wiring (plan §1625): `POSServiceProvider::register()`
        // tags the projector so Task 18's registry picks it up via
        // `app->tagged(FiscalEventProjector::class)`. A missing tag would
        // leave a `SALE_RECEIPT` ingest with zero active projectors —
        // silent regression.
        /** @var list<FiscalEventProjector> $tagged */
        $tagged = iterator_to_array(
            $this->app->tagged(FiscalEventProjector::class),
            false,
        );
        $names = array_map(
            static fn (FiscalEventProjector $p): string => $p->name(),
            $tagged,
        );
        $this->assertContains('pos_core_receipt', $names);
    }

    public function test_terminal_not_found_fails_closed_without_crashing(): void
    {
        // Standing pattern 3 (Task 18 BLOCKER F1 carry-forward): a downstream
        // lookup failure must fail-closed — log + skip the projection row,
        // never crash the projector job. If a fiscal event's terminal_id has
        // no matching row (deleted terminal, mis-routed event), apply()
        // returns cleanly with NO pos_receipts row written.
        $event = $this->storeSaleReceiptFiscalEvent();

        // Mutate the persisted fiscal_events row's terminal_id to a UUID
        // that no terminal owns. The integrity hash is not re-validated
        // by the projector (the event was already verified in Task 19),
        // so this is a clean test of the projector's terminal-lookup gate.
        $orphanTerminalId = Str::uuid()->toString();
        FiscalEvent::query()->where('id', $event->id)->update(['terminal_id' => $orphanTerminalId]);
        $refreshed = FiscalEvent::query()->find($event->id);
        $this->assertNotNull($refreshed);

        $this->app->make(PosCoreReceiptProjection::class)->apply($refreshed);

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
    }

    public function test_malformed_payload_payment_method_id_rolls_projection_back(): void
    {
        // Standing pattern 1 (Task 14 BLOCKER carry-forward): silent
        // coercion is forbidden. A payment_lines[] entry missing
        // `payment_method_id` must throw via FiscalPayloadArrayGuards,
        // rolling the entire projection transaction back atomically —
        // no partial pos_receipts row should remain.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // payment_method_id missing — the guard throws.
                ['amount' => '10.00', 'method_code' => 'CASH'],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InvalidArgumentException from missing payment_method_id');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row directly via the
     * Eloquent model — bypasses OutboxIngestor (Task 19) because the
     * projector's contract is "given a verified `FiscalEvent` row, apply
     * the business effects." Reusing the ingestor here would couple this
     * test to Task 19's lifecycle for no value.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     */
    private function storeSaleReceiptFiscalEvent(?array $paymentLinesOverride = null): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);
        $sequenceNumber = 1;

        $paymentLines = $paymentLinesOverride ?? [
            [
                'payment_method_id' => $this->paymentMethodId,
                'amount' => '10.00',
                'method_code' => 'CASH',
            ],
        ];

        $payload = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [
                [
                    'sku' => 'X',
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            'payment_lines' => $paymentLines,
            'subtotal' => '10.00',
            'tax_total' => '0.00',
            'total' => '10.00',
            'vat_breakdown' => [
                ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
            ],
            'voucher_redemptions' => [],
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

        // Refresh so the model carries DB-driver-normalized values
        // (e.g., the `created_at` timestamp, the canonical_bytes BYTEA
        // round-trip on PG).
        return $event->refresh();
    }

    /**
     * Spec §4 JCS canonical encoding (test-local). Sorts keys at every
     * depth, no whitespace, integer-only numbers (the test payload is
     * already strings for money so this is satisfied). Not the
     * production encoder — good enough to drive the projector path.
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
}
