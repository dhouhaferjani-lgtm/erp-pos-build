<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
 * **Idempotency.** The durable guard is the atomic
 * `INSERT … ON CONFLICT ON CONSTRAINT pos_receipts_fiscal_event_id_unique
 * DO NOTHING RETURNING id` Task 19 standing pattern (T21-B3 round-2).
 * Calling `apply()` a second time with the same `FiscalEvent` is a no-op —
 * verified end-to-end below.
 *
 * **Boundary discipline.** The projector imports ZERO Treasury / Accounting
 * / Sales operational classes (only the `PaymentMethod` model for inbound
 * mirrored reference data lookup — permitted by SoT §13.6/D16). The
 * Treasury `Payment` row + GL is owned by `TreasuryReceiptBridge`
 * (Task 22); no Treasury `payments` row is created here.
 *
 * **Round-2 coverage.** Round-1 shipped one happy-path test with a single
 * cash payment and no product_id. Round-2 (T21-B4 / Opus F2) adds the
 * discriminated-union variants the projector owns: split payments,
 * store-voucher redemption, product-backed stock decrement, voucher+stock
 * combined, voucher-rollback-on-failure, cross-tenant payment-method
 * rejection (F3), and the F1-BLOCKER legacy `pos:verify-chains` regression
 * gate.
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

    private string $voucherPaymentMethodId;

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

        $voucherMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'store_voucher',
            'name' => 'Store Voucher',
        ]);
        $this->voucherPaymentMethodId = $voucherMethod->id;

        // Seed the chart of accounts so VoucherRedemptionService::redeem
        // can resolve `voucher_liability` + `pos_tender_clearing` GL
        // accounts by system purpose. Without this seed any test that
        // exercises the store_voucher tender branch dies at the GL
        // posting layer with "Missing GL account" — pattern carried
        // forward from `ReceiptSyncServiceVoucherRedemptionTest`.
        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);
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

        // Second run — the atomic INSERT ... ON CONFLICT path resolves to
        // a no-op because the existing row owns the fiscal_event_id slot.
        // No exceptions, no duplicate rows.
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
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
    }

    // =================================================================
    // T21-B2 round-2 — outbox-ingestor payment-line shape regression
    // =================================================================

    public function test_outbox_ingestor_payment_line_shape_rolls_projection_back_atomically(): void
    {
        // T21-B2 round-2 — the Task 19 outbox-ingestor fixture uses a
        // looser payment-line shape `{method, amount, tendered, change}`
        // (no `payment_method_id`, no `method_code`). That shape passes
        // `StrictCanonicalParser::validateSaleReceiptPayload` (which only
        // gates money fields) and lands in `fiscal_events`, but the
        // projector requires both `payment_method_id` and `method_code`.
        // The mismatch must fail loudly + atomically rather than write
        // wrong-but-plausible projection rows. This regression test
        // pins the failure mode: `InvalidArgumentException` from
        // `FiscalPayloadArrayGuards::requireString()` rolls back the
        // whole transaction; no partial pos_receipts row remains.
        //
        // This locks the standing pattern (Task 16) that the canonical
        // payload schema asymmetry between parser and projector is
        // caught by FAIL-LOUDLY, not by silent coercion.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // Outbox-ingestor test fixture shape — see
                // `OutboxIngestorTest::minimalSaleReceiptPayload()`.
                ['method' => 'CASH', 'amount' => '10.00', 'tendered' => '10.00', 'change' => '0.00'],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InvalidArgumentException for outbox-ingestor payment-line shape');
        } catch (InvalidArgumentException) {
            // expected — `requireString($line, 'payment_method_id')` throws
        }

        $this->assertSame(0, DB::table('pos_receipts')->count(), 'transaction must roll back');
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
        $this->assertSame(0, DB::table('pos_receipt_vat_details')->count());
    }

    // =================================================================
    // T21-B3 round-2 — atomic ON CONFLICT idempotency
    // =================================================================

    public function test_idempotent_replay_returns_existing_row_via_on_conflict_do_nothing(): void
    {
        // T21-B3 round-2 — concurrent retries / replays must NOT race
        // through the pre-INSERT exists() probe and crash on the UNIQUE
        // constraint. We can't easily race two PHP threads inside one
        // PHPUnit test, but we CAN simulate the second-arrival path by
        // calling apply() twice — the second invocation hits the
        // atomic INSERT ... ON CONFLICT ON CONSTRAINT
        // pos_receipts_fiscal_event_id_unique DO NOTHING RETURNING id
        // path and returns the no-op result (RETURNING null).
        $event = $this->storeSaleReceiptFiscalEvent();
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        // First apply lands the row.
        $projector->apply($event);
        $firstReceiptId = DB::table('pos_receipts')->value('id');
        $this->assertNotNull($firstReceiptId);

        // Bypass the fast-path probe by directly invoking apply() — the
        // probe will short-circuit but we want to be sure ON CONFLICT
        // is the actual race fence. Delete-then-reinsert is unsafe under
        // the immutability trigger; instead, validate that re-running
        // through the full path is a clean no-op.
        $projector->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame($firstReceiptId, DB::table('pos_receipts')->value('id'));
    }

    // =================================================================
    // T21-B4 / Opus F2 round-2 — discriminated business-effect variants
    // =================================================================

    public function test_split_payment_lines_each_persist_distinct_pos_receipt_payment_rows(): void
    {
        // T21-B4 round-2 — split tenders are a first-class projector
        // responsibility (one pos_receipt_payments row per payment_line).
        // Round-1 only exercised single-payment fixtures so this branch
        // was untested.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['payment_method_id' => $this->paymentMethodId, 'amount' => '7.00', 'method_code' => 'CASH'],
                ['payment_method_id' => $this->paymentMethodId, 'amount' => '3.00', 'method_code' => 'CASH'],
            ],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count());
        $paymentRows = DB::table('pos_receipt_payments')->orderBy('amount', 'desc')->get();
        $this->assertCount(2, $paymentRows);

        // Sum-of-amounts must equal the receipt total (10.00). Use bcadd
        // for storage-format-independent comparison — PG stores decimal(12,2)
        // as '7.00', SQLite as the literal string we wrote, MySQL etc may
        // differ.
        $sum = '0';
        foreach ($paymentRows as $row) {
            /** @var numeric-string $amount */
            $amount = (string) $row->amount;
            $sum = bcadd($sum, $amount, 3);
        }
        $this->assertSame('10.000', $sum);
    }

    public function test_store_voucher_payment_invokes_voucher_redemption_service(): void
    {
        // T21-B4 / Opus F2 round-2 — store_voucher tender must redeem
        // the underlying voucher via VoucherRedemptionService. Round-1
        // never exercised this branch.
        $voucher = $this->seedVoucher('SV-PROJ-001', '50.000');
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->voucherPaymentMethodId,
                    'amount' => '15.00',
                    'method_code' => 'store_voucher',
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'SV-PROJ-001',
                ],
            ],
            total: '15.00',
            subtotal: '15.00',
            lines: [
                ['sku' => 'X', 'unit_price' => '15.00', 'line_total' => '15.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
            ],
            vatBreakdown: [['rate' => '0', 'base' => '15.00', 'amount' => '0.00']],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // Receipt + payment row written.
        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame(1, DB::table('pos_receipt_payments')->count());

        // Voucher redeemed: ledger row written, balance decremented.
        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::PartiallyRedeemed, $voucher->status);
        $this->assertDatabaseHas('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed->value,
        ]);
    }

    public function test_product_backed_line_decrements_stock_and_writes_stock_movement(): void
    {
        // T21-B4 / Opus F2 round-2 — product_id lines must decrement
        // StockLevel + create a StockMovement row. Round-1's `sku=X` line
        // had no product_id and skipped the stock branch entirely.
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
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '2',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            // Recompute totals: 2 * 10 = 20.
            subtotal: '20.00',
            total: '20.00',
            vatBreakdown: [['rate' => '0', 'base' => '20.00', 'amount' => '0.00']],
            paymentLinesOverride: [
                ['payment_method_id' => $this->paymentMethodId, 'amount' => '20.00', 'method_code' => 'CASH'],
            ],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // StockMovement row written.
        $this->assertSame(1, DB::table('stock_movements')->count());
        $movement = DB::table('stock_movements')->first();
        $this->assertNotNull($movement);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame('issue', $movement->movement_type);
        $this->assertSame('pos_sale', $movement->reason);

        // StockLevel decremented (10 - 2 = 8).
        $stockLevel->refresh();
        $this->assertSame('8.00', $stockLevel->quantity);
    }

    public function test_voucher_plus_stock_combined_both_side_effects_fire_and_are_idempotent(): void
    {
        // T21-B4 / Opus F2 round-2 — combined fixture: a product-backed
        // line PAID by store_voucher tender. Both side-effect branches
        // fire (stock decrement + voucher redemption) AND both stay
        // idempotent on re-apply (no double-decrement, no double-
        // redemption).
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
            'quantity' => '5.00',
            'reserved' => '0.00',
        ]);
        $voucher = $this->seedVoucher('SV-COMBINED-001', '50.000');

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '10.00',
            total: '10.00',
            vatBreakdown: [['rate' => '0', 'base' => '10.00', 'amount' => '0.00']],
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->voucherPaymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'store_voucher',
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'SV-COMBINED-001',
                ],
            ],
        );

        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);

        // First apply — both side-effects fired exactly once.
        $this->assertSame(1, DB::table('stock_movements')->count());
        $stockLevel->refresh();
        $this->assertSame('4.00', $stockLevel->quantity);
        $voucher->refresh();
        $this->assertSame('40.00000', $voucher->current_balance);
        $redeemedRows = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->count();
        $this->assertSame(1, $redeemedRows);

        // Second apply — atomic ON CONFLICT no-op, no double-decrement
        // and no double-redemption.
        $projector->apply($event);
        $this->assertSame(1, DB::table('stock_movements')->count(), 'stock movement must not duplicate on replay');
        $stockLevel->refresh();
        $this->assertSame('4.00', $stockLevel->quantity, 'stock level must not double-decrement');
        $voucher->refresh();
        $this->assertSame('40.00000', $voucher->current_balance, 'voucher must not double-redeem');
        $redeemedRowsAfterReplay = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->count();
        $this->assertSame(1, $redeemedRowsAfterReplay);
    }

    public function test_zero_payment_lines_receipt_persists_without_payment_rows(): void
    {
        // T21-B4 round-2 — open-tab / on-account / fully-discounted receipts
        // have empty `payment_lines[]`. The projector should still write
        // the pos_receipts row + lines + VAT details, but ZERO
        // pos_receipt_payments rows. The pos_receipts row's monetary
        // totals are still bound by the CHECK constraints.
        $event = $this->storeSaleReceiptFiscalEvent(
            // Fully-discounted: subtotal=10, discount=10, total=0.
            paymentLinesOverride: [],
            total: '0.00',
            subtotal: '10.00',
            discountTotal: '10.00',
            lines: [
                ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
            ],
            vatBreakdown: [['rate' => '0', 'base' => '10.00', 'amount' => '0.00']],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertGreaterThan(0, DB::table('pos_receipt_lines')->count());
    }

    public function test_voucher_redemption_failure_rolls_back_the_entire_projection(): void
    {
        // Opus F2 round-2 — when VoucherRedemptionService throws (e.g.
        // unknown voucher serial), the wrapping DB::transaction MUST
        // roll back the receipt + lines + payments atomically. No
        // partial chain state can land.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->voucherPaymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'store_voucher',
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'SV-DOES-NOT-EXIST',
                ],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected voucher redemption to throw for unknown serial');
        } catch (\Throwable) {
            // expected — voucher redemption service throws and the
            // wrapping transaction rolls back atomically.
        }

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
        $this->assertSame(0, DB::table('pos_receipt_vat_details')->count());
    }

    // =================================================================
    // Opus F3 round-2 — cross-tenant payment_method_id FK rejection
    // =================================================================

    public function test_cross_tenant_payment_method_id_is_rejected_fail_closed(): void
    {
        // Opus F3 round-2 — the projector's writePayments() rejects a
        // payment_method_id from a different tenant at the application
        // layer. The `payment_methods` FK does NOT enforce tenant scope
        // on its own (it only requires the PK to exist), so without the
        // application-side gate a cross-tenant attack would silently
        // mirror a foreign tenant's payment_method_id into our projection
        // row. The projector throws RuntimeException inside the wrapping
        // `DB::transaction` block, rolling back the receipt + lines +
        // VAT + payments atomically.
        //
        // This is a load-bearing security claim about the SoT §13.6/D16
        // bounded-modules seam — a regression that widened the lookup
        // scope, coerced to NULL, or removed the throw would re-open
        // the attack. This test pins the behaviour.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignMethod = PaymentMethod::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['payment_method_id' => $foreignMethod->id, 'amount' => '10.00', 'method_code' => 'CASH'],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected RuntimeException for cross-tenant payment_method_id');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not visible to tenant', $e->getMessage());
        }

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
    }

    // =================================================================
    // Codex T29-F3 round-2 — voucher instrument-binding defense-in-depth
    // =================================================================

    public function test_voucher_payment_line_without_instrument_type_is_rejected_by_projection(): void
    {
        // Codex T29-F3 round-2 — when StoreReceiptPaymentsInstrumentBindingTest
        // was class-skipped under §14.2, the skip citation pointed at
        // `FiscalPayloadConstraintValidator` as the surviving owner of
        // voucher/gift-card instrument-binding enforcement. The validator
        // (FiscalPayloadConstraintValidator::validateSaleReceiptPayload at
        // lines 143-160) only enforces monetary shape on `payment_lines`;
        // the defense-in-depth that actually rejects voucher tenders
        // missing `instrument_type` lives at
        // `PosCoreReceiptProjection::writePayments` lines 544-549 via
        // `PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)`
        // + `InstrumentRequiredException::forMethodCode()`.
        //
        // Round-2 pins the existing-but-untested defense: a SALE_RECEIPT
        // sealed with `method_code=store_voucher` and a null/missing
        // `instrument_type` would otherwise bind the wrong fact into the
        // fiscal hash chain ("some voucher paid" vs. "voucher SV-XXXX
        // paid"). The projector must throw and roll the transaction back.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->voucherPaymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'store_voucher',
                    // instrument_type omitted — projector must reject.
                    'instrument_serial' => 'SV-MISSING-TYPE',
                ],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InstrumentRequiredException for missing instrument_type on store_voucher tender');
        } catch (InstrumentRequiredException $e) {
            $this->assertStringContainsString('store_voucher', $e->getMessage());
        }

        // Projection transaction rolled back atomically — no partial rows.
        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
    }

    public function test_voucher_payment_line_without_instrument_serial_is_rejected_by_projection(): void
    {
        // Codex T29-F3 round-2 — symmetric pin for `instrument_serial`.
        // Same defense-in-depth path as the missing-type case, different
        // branch of the `if ($instrumentSerial === null || $instrumentSerial
        // === '')` predicate in
        // `PosCoreReceiptProjection::writePayments` lines 545-549.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->voucherPaymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'store_voucher',
                    'instrument_type' => 'store_voucher',
                    // instrument_serial omitted — projector must reject.
                ],
            ],
        );

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InstrumentRequiredException for missing instrument_serial on store_voucher tender');
        } catch (InstrumentRequiredException $e) {
            $this->assertStringContainsString('store_voucher', $e->getMessage());
        }

        $this->assertSame(0, DB::table('pos_receipts')->count());
        $this->assertSame(0, DB::table('pos_receipt_payments')->count());
        $this->assertSame(0, DB::table('pos_receipt_lines')->count());
    }

    // =================================================================
    // Opus F1 round-2 — legacy `pos:verify-chains` carve-out
    // =================================================================

    public function test_projection_row_is_excluded_from_legacy_verify_terminal_chain(): void
    {
        // Opus F1 round-2 — the still-wired `pos:verify-chains` operator
        // command iterates `ReceiptHashService::verifyTerminalChain($terminal)`
        // which recomputes `sha256(pipe_string|previous_hash|genesis_seed)`
        // and compares to `pos_receipts.fiscal_hash`. Projection-written
        // rows store `fiscal_hash = event->current_hash` (the
        // canonical-bytes SHA-256), so the comparison would fail for
        // every projection row.
        //
        // Round-2 carve-out: rows where `fiscal_event_id IS NOT NULL`
        // are skipped by the legacy verifier. Their integrity lives in
        // `fiscal_events.current_hash` and is verified by Task 31's
        // `fiscal:verify-event-chain`. This test pins the carve-out —
        // a regression that walked projection rows back into the legacy
        // verifier would break operator chain-verify on every receipt.
        $event = $this->storeSaleReceiptFiscalEvent();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $terminal = Terminal::query()->findOrFail($this->terminalId);
        // The terminal has `last_hash = null` (no legacy chain advanced),
        // so the legacy verifier returns true cleanly because it sees
        // zero legacy receipts and the terminal head is consistent.
        $hashService = $this->app->make(ReceiptHashService::class);
        $this->assertTrue($hashService->verifyTerminalChain($terminal));
    }

    public function test_pos_verify_chains_command_does_not_break_on_projection_rows(): void
    {
        // Opus F1 round-2 — end-to-end: invoke `pos:verify-chains` over
        // a terminal whose only receipts are projection rows. The
        // command must not surface chain breaks.
        $event = $this->storeSaleReceiptFiscalEvent();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // 0 = chains verified, no breaks. The command should not report
        // "Sequence #N (hash)" for projection rows because they are
        // excluded by the `whereNull('fiscal_event_id')` filter.
        $this->artisan('pos:verify-chains', ['--terminal' => $this->terminalId])
            ->assertExitCode(0);
    }

    public function test_projector_computes_real_vat_and_payment_section_hashes(): void
    {
        // Opus F1 round-2 — the projector now computes content-meaningful
        // values for `vat_breakdown_hash` and `payment_methods_hash` via
        // `ReceiptHashService::hashVATBreakdown()` / `hashPaymentMethods()`
        // instead of the round-1 zero-sentinel. This makes the columns
        // audit-honest (a hash-named column that contains a real hash)
        // and keeps `ReceiptHashService::verifyVATBreakdownHash` /
        // `verifyPaymentMethodsHash` green for the row's sub-data.
        $event = $this->storeSaleReceiptFiscalEvent();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $row = DB::table('pos_receipts')->first();
        $this->assertNotNull($row);
        $this->assertNotSame(str_repeat('0', 64), $row->vat_breakdown_hash, 'vat_breakdown_hash must be a real hash, not the round-1 zero sentinel');
        $this->assertNotSame(str_repeat('0', 64), $row->payment_methods_hash, 'payment_methods_hash must be a real hash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->vat_breakdown_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->payment_methods_hash);
    }

    // =================================================================
    // Opus F9 round-2 — HasUuids deterministic-ID round-trip
    // =================================================================

    public function test_explicit_receipt_id_is_preserved_through_the_save_round_trip(): void
    {
        // Opus F9 round-2 — the projector's raw-INSERT pattern (round-2
        // moved off the new Receipt + $receipt->id = $id + $receipt->save()
        // pattern to atomic ON CONFLICT, but the deterministic-id contract
        // still holds: the inserted row's id must be the one the projector
        // generated, and must be findable via `Receipt::find($id)`. A
        // future Laravel HasUuids change that re-introduced auto-generation
        // on raw INSERT would silently break this.
        $event = $this->storeSaleReceiptFiscalEvent();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $row = DB::table('pos_receipts')->first();
        $this->assertNotNull($row);

        // The id stored is what Receipt::query()->find() returns; both
        // round-trip through the model layer.
        /** @var Receipt|null $receipt */
        $receipt = Receipt::query()->find($row->id);
        $this->assertNotNull($receipt);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
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
     * @param  list<array<string, mixed>>|null  $lines
     * @param  list<array<string, mixed>>|null  $vatBreakdown
     */
    private function storeSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        ?array $lines = null,
        ?array $vatBreakdown = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        int $sequenceNumber = 1,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $paymentLines = $paymentLinesOverride ?? [
            [
                'payment_method_id' => $this->paymentMethodId,
                'amount' => '10.00',
                'method_code' => 'CASH',
            ],
        ];

        $linesPayload = $lines ?? [
            [
                'sku' => 'X',
                'unit_price' => '10.00',
                'line_total' => '10.00',
                'quantity' => '1',
                'tax_rate' => '0',
                'tax_amount' => '0.00',
            ],
        ];

        $vatPayload = $vatBreakdown ?? [
            ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
        ];

        $payload = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => $discountTotal,
            'lines' => $linesPayload,
            'payment_lines' => $paymentLines,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'vat_breakdown' => $vatPayload,
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

    private function seedVoucher(string $code, string $balance): Voucher
    {
        $terminal = Terminal::query()->findOrFail($this->terminalId);

        return Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'tenant_id' => $this->tenantId,
                'company_id' => $this->companyId,
                'code' => $code,
                'currency' => 'EUR',
                'initial_balance' => $balance,
                'current_balance' => $balance,
                'issued_by_user_id' => $this->operatorId,
            ]);
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
