<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\OriginalReceiptUnresolvableException;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Partner;
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
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use App\Shared\Domain\ByteaBinding;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *
 * ---------------------------------------------------------------------
 * **Read scoping — LEDGER row C-7 (committed-state bleed).**
 *
 * This class must NEVER assume the projection tables are empty at the start
 * of a test. A sibling class in this very directory —
 * `PosCoreReceiptProjectionRefundDispositionStockTest` — overrides
 * `connectionsToTransact()` to return `[]` (that file, lines 63-66), which
 * disables the `RefreshDatabase` wrapping transaction, so every fixture and
 * projection row it writes is COMMITTED and survives for the rest of the PHP
 * process. It sorts alphabetically BEFORE this file, so in any multi-class
 * run of `tests/Feature/Fiscal/` its rows are already in `pos_receipts` /
 * `pos_receipt_lines` / `stock_movements` when this class starts.
 *
 * Consequence: an unscoped `DB::table('pos_receipts')->first()` picks an
 * arbitrary leaked row (PostgreSQL has no implicit ordering), and an
 * unscoped `->count()` counts leaked rows — which is exactly why this class
 * was 31/31 standalone but 35-41 assertions red, with a *different* count on
 * every run, inside a directory run.
 *
 * Every read below is therefore scoped to the rows THIS test created:
 *   - `myReceipts()`         — `pos_receipts` filtered by this test's tenant
 *                              (`setUp()` mints a fresh `Tenant` per test).
 *   - `myReceiptChildren()`  — `pos_receipt_*` child tables have no tenant
 *                              column, so the scope walks the `receipt_id` FK.
 *   - `myStockMovements()`   — `stock_movements` filtered by this test's tenant.
 *   - `projectionTableCounts()` + `assertNoProjectionRowsWritten()` — the
 *                              leaked-row-tolerant form of "the transaction
 *                              rolled back and NOTHING landed", used where a
 *                              receipt-scoped subquery would be vacuous
 *                              (no parent receipt ⇒ no child rows by
 *                              construction, which would silently weaken the
 *                              rollback claim).
 * Explicit `orderBy('id')` accompanies every single-row read.
 */
final class PosCoreReceiptProjectionTest extends TestCase
{
    use RefreshDatabase;

    private ?SaleEarnContext $capturedEarn = null;

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

        // LEDGER C-7, second face of the same committed-state bleed. The
        // default `TenantFactory` slug is `Str::slug($faker->unique()->company())`
        // — `unique()` de-dupes the COMPANY NAME, but `Str::slug()` collapses
        // distinct names onto the same slug ("Collier PLC" / "Collier, PLC" →
        // `collier-plc`). Against the tenants COMMITTED by
        // `PosCoreReceiptProjectionRefundDispositionStockTest` (see the class
        // docblock) that lands a random `tenants_slug_unique` violation in
        // setUp — observed once in three directory runs, killing an otherwise
        // green test. An explicitly unique slug removes the coupling; same
        // pattern as VerifyEventChainFleetCommandDbPerTenantTest.
        $tenant = Tenant::factory()->create([
            'slug' => 'poscore-projection-'.Str::lower(Str::random(16)),
        ]);
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
        // posting layer with "Missing GL account".
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
            $this->myReceipts()->whereNotNull('canonical_bytes')->count(),
        );

        $this->assertGreaterThan(0, $this->myReceiptChildren('pos_receipt_lines')->count());
        $this->assertGreaterThan(0, $this->myReceiptChildren('pos_receipt_payments')->count());

        // The count above is filtered on canonical_bytes, so it does not bound
        // the unfiltered tenant-scoped set — guard the single-row read.
        $this->assertSame(1, $this->myReceipts()->count());
        $receipt = $this->myReceipts()->orderBy('id')->first();
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

    /**
     * `pos_receipts.canonical_bytes` is a BINARY column, written by the raw
     * `INSERT ... ON CONFLICT DO NOTHING` in `insertReceiptOnConflictDoNothing`.
     * A product name carrying a quote or a backslash makes the canonical bytes
     * contain RFC 8785 `\"` / `\\`, which a `PDO::PARAM_STR` bind hands to
     * PostgreSQL's bytea *escape* parser — `SQLSTATE[22P02]`.
     */
    public function test_receipt_mirror_preserves_canonical_bytes_with_backslash_escapes(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(lines: [[
            'sku' => 'X',
            'product_name' => 'Filtre à huile 5" "Prémium" \\ réf C:\\PARTS\\OIL',
            'unit_price' => '10.00',
            'line_total' => '10.00',
            'quantity' => '1',
            'tax_rate' => '0',
            'tax_amount' => '0.00',
        ]]);

        $bytes = $event->canonical_bytes;
        $this->assertStringContainsString('\\"', $bytes);
        $this->assertStringContainsString('\\\\', $bytes);
        $this->assertStringContainsString('à', $bytes);

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($bytes, ByteaBinding::read($receipt->canonical_bytes));
    }

    public function test_apply_links_pos_receipt_to_the_fiscal_event(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent();

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // Scoped by tenant (NOT by fiscal_event_id — that would make the
        // assertion below tautological); this test's tenant owns exactly one
        // receipt, so the row read here is the one apply() just wrote.
        $this->assertSame(1, $this->myReceipts()->count());
        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        // The Task 11 fiscal_event_id linkage column is the idempotency anchor.
        $this->assertSame($event->id, $receipt->fiscal_event_id);
    }

    public function test_apply_is_idempotent_via_the_fiscal_event_id_guard(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent();
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $projector->apply($event);
        $paymentRowsAfterFirst = $this->myReceiptChildren('pos_receipt_payments')->count();
        $lineRowsAfterFirst = $this->myReceiptChildren('pos_receipt_lines')->count();
        $vatRowsAfterFirst = $this->myReceiptChildren('pos_receipt_vat_details')->count();
        $stockMovementsAfterFirst = $this->myStockMovements()->count();

        // Second run — the atomic INSERT ... ON CONFLICT path resolves to
        // a no-op because the existing row owns the fiscal_event_id slot.
        // No exceptions, no duplicate rows.
        $projector->apply($event);

        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertSame($paymentRowsAfterFirst, $this->myReceiptChildren('pos_receipt_payments')->count());
        $this->assertSame($lineRowsAfterFirst, $this->myReceiptChildren('pos_receipt_lines')->count());
        $this->assertSame($vatRowsAfterFirst, $this->myReceiptChildren('pos_receipt_vat_details')->count());
        $this->assertSame($stockMovementsAfterFirst, $this->myStockMovements()->count());
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

        $this->assertSame(1, $this->myReceipts()->count());
        // ZERO Treasury Payment rows — that's the TreasuryReceiptBridge's job
        // (Task 22), which runs only when the Treasury module is active.
        $this->assertSame(0, DB::table('payments')->where('tenant_id', $this->tenantId)->count());
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
        $orphanTerminalId = Str::uuid()->toString();
        $event = $this->storeSaleReceiptFiscalEvent(terminalId: $orphanTerminalId);

        $before = $this->projectionTableCounts();

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(0, $this->myReceipts()->count());
        // No separate child-table assertion here: with zero parent receipts a
        // receipt-scoped subquery is vacuously empty. The snapshot delta below
        // is the load-bearing "nothing landed" claim.
        $this->assertNoProjectionRowsWritten($before, 'fail-closed terminal lookup must write nothing');
    }

    public function test_unknown_method_code_rolls_projection_back(): void
    {
        // Pass 2A.PHP.2 — the 28-key canonical payload no longer carries
        // `payment_method_id`; the projector resolves the FK via
        // `PaymentMethodResolver::resolveByCode($tenantId, $companyId, $methodCode)`.
        // An unknown method_code returns null → fail-closed RuntimeException
        // → the wrapping projection transaction rolls back atomically.
        // This replaces the pre-2A.PHP.2 "missing payment_method_id" guard
        // (the guard no longer applies because the field is gone).
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '10.00', 'method_code' => 'UNKNOWN_METHOD_NEVER_SEEDED'],
            ],
        );

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected RuntimeException from PaymentMethodResolver resolution failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_not_found', $e->getMessage());
        }

        $this->assertSame(0, $this->myReceipts()->count());
        $this->assertNoProjectionRowsWritten($before, 'transaction must roll back');
    }

    // =================================================================
    // Cross-tenant payment-method rejection (Task 21 R2 Opus F3 →
    // Pass 2A.PHP.2: same security stance via Shared/Contracts seam).
    // =================================================================

    public function test_cross_tenant_method_code_rolls_projection_back_atomically(): void
    {
        // Pass 2A.PHP.2 — the PaymentMethodResolver is tenant-scoped. A
        // method_code that exists in a FOREIGN tenant but not in the event's
        // own tenant returns null → fail-closed RuntimeException →
        // transaction rolls back. Replaces the pre-2A.PHP.2 cross-tenant
        // payment_method_id rejection (Task 21 R2 Opus F3) — same security
        // stance, cleaner separation via the Shared/Contracts seam.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // 'XENO_CODE' is not seeded in this tenant.
                ['amount' => '10.00', 'method_code' => 'XENO_CODE'],
            ],
        );

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected RuntimeException for unresolved method_code');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_not_found', $e->getMessage());
            $this->assertStringContainsString('XENO_CODE', $e->getMessage());
        }

        $this->assertSame(0, $this->myReceipts()->count(), 'transaction must roll back');
        $this->assertNoProjectionRowsWritten($before, 'transaction must roll back');
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
        $this->assertSame(1, $this->myReceipts()->count());
        $firstReceiptId = $this->myReceipts()->orderBy('id')->value('id');
        $this->assertNotNull($firstReceiptId);

        // Bypass the fast-path probe by directly invoking apply() — the
        // probe will short-circuit but we want to be sure ON CONFLICT
        // is the actual race fence. Delete-then-reinsert is unsafe under
        // the immutability trigger; instead, validate that re-running
        // through the full path is a clean no-op.
        $projector->apply($event);

        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertSame($firstReceiptId, $this->myReceipts()->orderBy('id')->value('id'));
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

        $this->assertSame(1, $this->myReceipts()->count());
        $paymentRows = $this->myReceiptChildren('pos_receipt_payments')
            ->orderBy('amount', 'desc')
            ->orderBy('id')
            ->get();
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
        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertSame(1, $this->myReceiptChildren('pos_receipt_payments')->count());

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
        $this->assertSame(1, $this->myStockMovements()->count());
        $movement = $this->myStockMovements()->orderBy('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame('issue', $movement->movement_type);
        $this->assertSame('pos_sale', $movement->reason);

        // StockLevel decremented (10 - 2 = 8).
        $stockLevel->refresh();
        $this->assertSame('8.0000', $stockLevel->quantity);
    }

    public function test_projection_warns_and_continues_on_insufficient_stock_even_under_block_policy(): void
    {
        // Task 6 pin (spec §4.2) — the fiscal-event projection path is
        // POLICY-INDEPENDENT: a signed fiscal event always lands. Even when
        // the company's pos_stock_policy is Block (the draft path would
        // reject), the projection warns and continues into negative stock.
        Company::query()->whereKey($this->companyId)
            ->update(['pos_stock_policy' => PosStockPolicy::Block->value]);

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
            'quantity' => '1.00',
            'reserved' => '0.00',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'unit_price' => '10.00',
                    'line_total' => '20.00',
                    'quantity' => '2',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
            subtotal: '20.00',
            total: '20.00',
            vatBreakdown: [['rate' => '0', 'base' => '20.00', 'amount' => '0.00']],
            paymentLinesOverride: [
                ['payment_method_id' => $this->paymentMethodId, 'amount' => '20.00', 'method_code' => 'CASH'],
            ],
        );

        Log::spy();

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // Receipt projection row landed despite the shortfall.
        $this->assertSame(1, $this->myReceipts()->count());

        // Stock went negative (1 - 2 = -1) — warn-and-continue, never throw.
        $stockLevel->refresh();
        $this->assertSame('-1.0000', $stockLevel->quantity);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($product): bool {
                return str_contains($message, 'insufficient stock')
                    && ($context['product_id'] ?? null) === $product->id;
            });
    }

    public function test_v2_variant_line_writes_variant_id_on_pos_receipt_line(): void
    {
        // SaleReceiptV2 (M4): the canonical line carries the variant identity;
        // the projection binds pos_receipt_lines.variant_id tenant-scoped and
        // anchored to the resolved product FK.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'variant_name' => 'Default item — Red / L',
                    'variant_sku' => 'SKU-X-RED-L',
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, $this->myReceiptChildren('pos_receipt_lines')->count());
        $line = $this->myReceiptChildren('pos_receipt_lines')->orderBy('line_number')->orderBy('id')->first();
        $this->assertNotNull($line);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame($variant->id, $line->variant_id);
    }

    public function test_v2_variant_of_a_different_product_does_not_bind(): void
    {
        // A variant_id that is not a variant OF the line's product must not
        // bind the FK — the sealed canonical payload stays authoritative.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $foreignVariant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $otherProduct->id,
            'is_active' => true,
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'variant_id' => $foreignVariant->id,
                    'variant_name' => 'Foreign variant',
                    'variant_sku' => 'SKU-FOREIGN',
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, $this->myReceiptChildren('pos_receipt_lines')->count());
        $line = $this->myReceiptChildren('pos_receipt_lines')->orderBy('line_number')->orderBy('id')->first();
        $this->assertNotNull($line);
        $this->assertSame($product->id, $line->product_id);
        $this->assertNull($line->variant_id);
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
        $this->assertSame(1, $this->myStockMovements()->count());
        $stockLevel->refresh();
        $this->assertSame('4.0000', $stockLevel->quantity);
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
        $this->assertSame(1, $this->myStockMovements()->count(), 'stock movement must not duplicate on replay');
        $stockLevel->refresh();
        $this->assertSame('4.0000', $stockLevel->quantity, 'stock level must not double-decrement');
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

        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertSame(0, $this->myReceiptChildren('pos_receipt_payments')->count());
        $this->assertGreaterThan(0, $this->myReceiptChildren('pos_receipt_lines')->count());
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

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected voucher redemption to throw for unknown serial');
        } catch (\Throwable) {
            // expected — voucher redemption service throws and the
            // wrapping transaction rolls back atomically.
        }

        $this->assertSame(0, $this->myReceipts()->count());
        $this->assertNoProjectionRowsWritten($before, 'voucher-redemption failure must roll back atomically');
    }

    // =================================================================
    // Opus F3 round-2 — cross-tenant payment_method_id FK rejection
    // =================================================================

    public function test_cross_tenant_payment_method_id_is_rejected_fail_closed(): void
    {
        // Pass 2A.PHP.2 — the canonical payload no longer carries
        // `payment_method_id`. The PaymentMethodResolver scopes the lookup
        // to the EVENT's tenant. A method_code that exists in a foreign
        // tenant but NOT in the local tenant returns null → fail-closed
        // RuntimeException → wrapping transaction rolls back.
        //
        // Same security stance as Task 21 R2 Opus F3 (cross-tenant
        // rejection), now expressed via the Shared/Contracts seam. This
        // test pins the behaviour: a payment method with code `CASH_FX_X`
        // that ONLY exists in another tenant must not resolve in this
        // tenant — the resolver returns null because the unique
        // `(tenant_id, code)` constraint partitions by tenant.
        // Explicit slug — see the setUp() note on tenants_slug_unique.
        $otherTenant = Tenant::factory()->create([
            'slug' => 'poscore-foreign-'.Str::lower(Str::random(16)),
        ]);
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        PaymentMethod::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'CASH_FX_X',
            'name' => 'Foreign Cash',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // 'CASH_FX_X' exists only in $otherTenant, not in $this->tenantId.
                ['amount' => '10.00', 'method_code' => 'CASH_FX_X'],
            ],
        );

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected RuntimeException for cross-tenant method_code');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_not_found', $e->getMessage());
            $this->assertStringContainsString('CASH_FX_X', $e->getMessage());
        }

        $this->assertSame(0, $this->myReceipts()->count());
        $this->assertNoProjectionRowsWritten($before, 'cross-tenant method_code must roll back');
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

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InstrumentRequiredException for missing instrument_type on store_voucher tender');
        } catch (InstrumentRequiredException $e) {
            $this->assertStringContainsString('store_voucher', $e->getMessage());
        }

        // Projection transaction rolled back atomically — no partial rows.
        $this->assertSame(0, $this->myReceipts()->count());
        $this->assertNoProjectionRowsWritten($before, 'missing instrument_type must roll back atomically');
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

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected InstrumentRequiredException for missing instrument_serial on store_voucher tender');
        } catch (InstrumentRequiredException $e) {
            $this->assertStringContainsString('store_voucher', $e->getMessage());
        }

        $this->assertSame(0, $this->myReceipts()->count());
        $this->assertNoProjectionRowsWritten($before, 'missing instrument_serial must roll back atomically');
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

        $this->assertSame(1, $this->myReceipts()->count());
        $row = $this->myReceipts()->orderBy('id')->first();
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

        $this->assertSame(1, $this->myReceipts()->count());
        $row = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($row);

        // The id stored is what Receipt::query()->find() returns; both
        // round-trip through the model layer.
        /** @var Receipt|null $receipt */
        $receipt = Receipt::query()->find($row->id);
        $this->assertNotNull($receipt);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
    }

    // =================================================================
    // Pass 2A.PHP.2 R2 — Codex BLOCKER-1: cross-tenant product FK rejection
    // =================================================================

    public function test_product_fk_resolver_rejects_cross_tenant_product_id(): void
    {
        // Pass 2A.PHP.2 R2 — Codex BLOCKER-1 closure. Round-1's
        // `PosCoreReceiptProjection::resolveProductFk()` looked up
        // `products.id` WITHOUT a `tenant_id` scope, so a `product_id`
        // UUID belonging to tenant A could bind into a tenant B receipt's
        // `pos_receipt_lines.product_id`. Same defect class as Task 21 R2
        // Opus F3 closed for `payment_method_id`, but missed for
        // `product_id`. The fix scopes the lookup by tenant; this test
        // pins it.
        //
        // Setup: seed product X in a FOREIGN tenant. Project a SALE_RECEIPT
        // in $this->tenantId with `line_items[0].product_id = X`. The
        // projector must NOT bind X (it lives in another tenant) — the
        // sealed snapshot stays in the canonical payload, and the column
        // is written as null.
        // Explicit slug — see the setUp() note on tenants_slug_unique.
        $foreignTenant = Tenant::factory()->create([
            'slug' => 'poscore-foreign-'.Str::lower(Str::random(16)),
        ]);
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignProduct = Product::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            lines: [
                [
                    'sku' => $foreignProduct->sku,
                    // product_id UUID lives in tenant $foreignTenant — projector
                    // must NOT bind it into tenant $this->tenantId's receipt.
                    'product_id' => $foreignProduct->id,
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'quantity' => '1',
                    'tax_rate' => '0',
                    'tax_amount' => '0.00',
                ],
            ],
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertSame(1, $this->myReceiptChildren('pos_receipt_lines')->count());

        $line = $this->myReceiptChildren('pos_receipt_lines')->orderBy('line_number')->orderBy('id')->first();
        $this->assertNotNull($line);
        // Sealed canonical snapshot still carries the original product_id
        // inside fiscal_events.payload.line_items[0].product_id — the FK
        // column itself is null because the cross-tenant lookup MUST fail
        // closed. NF525 export reads the snapshot via CanonicalPayloadReader.
        $this->assertNull(
            $line->product_id,
            'cross-tenant product UUID must NOT bind into pos_receipt_lines.product_id — '.
            'the canonical sealed snapshot remains authoritative inside fiscal_events.payload',
        );

        // R3 closure (Codex P3 / N-03+N-08). Reload the fiscal_events
        // payload and assert the sealed snapshot still carries the
        // foreign tenant's product UUID. The FK resolution failed (column
        // is null, asserted above) but the canonical payload is immutable
        // — NF525 export reads from this snapshot via CanonicalPayloadReader,
        // so a regression that mutated the payload to null out the
        // foreign UUID would silently corrupt the audit trail.
        /** @var FiscalEvent $reloaded */
        $reloaded = FiscalEvent::query()->findOrFail($event->id);
        /** @var array<string, mixed> $reloadedPayload */
        $reloadedPayload = $reloaded->payload;
        /** @var list<array<string, mixed>> $reloadedLineItems */
        $reloadedLineItems = $reloadedPayload['line_items'];
        $this->assertSame(
            $foreignProduct->id,
            $reloadedLineItems[0]['product_id'],
            'sealed canonical snapshot in fiscal_events.payload.line_items[0].product_id '.
            'must still carry the foreign tenant UUID — the FK resolution failure must NOT '.
            'mutate the immutable payload',
        );
    }

    // =================================================================
    // Pass 2A.PHP.2 R2 — Codex BLOCKER-2: REFUND/VOID unresolvable original
    // =================================================================

    public function test_refund_projection_throws_when_original_receipt_unresolvable(): void
    {
        // Pass 2A.PHP.2 R2 — Codex BLOCKER-2 closure. Round-1 silently
        // downgraded REFUND with an unresolvable `original_receipt_id`
        // to `receipt_type='sale'`. NF525 then exported as sale — a
        // fiscal-compliance violation (spec v7 §14 + NF525 §11.2-§11.4).
        // The fix throws `OriginalReceiptUnresolvableException` (extends
        // `ProjectionDependencyMissingException`), letting the Task 23
        // retry contract advance the job through Horizon until the
        // original SALE_RECEIPT projects locally.
        //
        // Setup: build a REFUND with `original_receipt_reference.fiscal_event_id`
        // pointing at a non-existent fiscal event. Assert the projector
        // throws with the right forensic context, no rows are written
        // (atomic rollback), and the exception is a
        // `ProjectionDependencyMissingException` so the job-level retry
        // contract applies.
        $upstreamFiscalEventId = Str::uuid()->toString();
        $originalReceiptUuid = Str::uuid()->toString();

        $event = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'REFUND',
            originalReceiptReference: [
                'fiscal_event_id' => $upstreamFiscalEventId,
                'original_business_date' => '2026-05-19',
                'original_receipt_uuid' => $originalReceiptUuid,
                'refund_reason' => 'Customer changed mind',
            ],
        );

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected OriginalReceiptUnresolvableException for unresolvable REFUND original');
        } catch (OriginalReceiptUnresolvableException $e) {
            $this->assertSame('REFUND', $e->eventType);
            $this->assertSame($upstreamFiscalEventId, $e->upstreamFiscalEventId);
            $this->assertSame($originalReceiptUuid, $e->originalReceiptUuid);
            $this->assertSame($this->tenantId, $e->tenantId);
            // Subclass relationship is the retry-contract anchor — the
            // `ApplyFiscalEventProjectionJob` catches Throwable, but log
            // scrapers / Horizon dashboards filter by the broader
            // ProjectionDependencyMissingException type.
            $this->assertInstanceOf(ProjectionDependencyMissingException::class, $e);
        }

        // Atomic rollback — no partial rows survive.
        $this->assertSame(0, $this->myReceipts()->count(), 'transaction must roll back atomically');
        $this->assertNoProjectionRowsWritten($before, 'transaction must roll back atomically');
    }

    public function test_void_projection_throws_when_original_receipt_unresolvable(): void
    {
        // Pass 2A.PHP.2 R2 — Codex BLOCKER-2 symmetry. Same fail-loud
        // contract for VOID as for REFUND — silent fallback to
        // `receipt_type='sale'` would falsify the audit trail. NF525
        // export must surface VOIDs as voids.
        $upstreamFiscalEventId = Str::uuid()->toString();
        $originalReceiptUuid = Str::uuid()->toString();

        $event = $this->storeSaleReceiptFiscalEvent(
            invoiceTypeCode: 'VOID',
            originalReceiptReference: [
                'fiscal_event_id' => $upstreamFiscalEventId,
                'original_business_date' => '2026-05-19',
                'original_receipt_uuid' => $originalReceiptUuid,
                'refund_reason' => 'Operator error — voided',
            ],
        );

        $before = $this->projectionTableCounts();

        try {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
            $this->fail('Expected OriginalReceiptUnresolvableException for unresolvable VOID original');
        } catch (OriginalReceiptUnresolvableException $e) {
            $this->assertSame('VOID', $e->eventType);
            $this->assertSame($upstreamFiscalEventId, $e->upstreamFiscalEventId);
            $this->assertSame($originalReceiptUuid, $e->originalReceiptUuid);
            $this->assertSame($this->tenantId, $e->tenantId);
            $this->assertInstanceOf(ProjectionDependencyMissingException::class, $e);
        }

        $this->assertSame(0, $this->myReceipts()->count(), 'transaction must roll back atomically');
        $this->assertNoProjectionRowsWritten($before, 'transaction must roll back atomically');
    }

    // =================================================================
    // Pass 2A.PHP.2 R2 — Codex P1-1: buyer-block sale-time snapshot
    // (synthesis v5 §5 invariant #4)
    // =================================================================

    public function test_legacy_non_uuid_buyer_id_lands_snapshot_with_null_partner_fk(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => 'cust-007',
                'name' => 'Legacy Sealed Buyer',
                'tax_number' => 'FR12345678901',
            ],
            eventVersion: 1,
        );

        // Projection workers run without request-bound company context.
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertNull($receipt->partner_id);
        $this->assertSame('Legacy Sealed Buyer', $receipt->customer_name);
        $this->assertSame('FR12345678901', $receipt->customer_identifier);
    }

    public function test_uuid_buyer_outside_event_company_lands_snapshot_with_null_partner_fk(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $otherCompanyPartner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => $otherCompanyPartner->id,
                'name' => 'Cross-company Sealed Buyer',
                'tax_number' => 'FR10987654321',
            ],
            eventVersion: 5,
        );

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertNull($receipt->partner_id);
        $this->assertSame('Cross-company Sealed Buyer', $receipt->customer_name);
        $this->assertSame('FR10987654321', $receipt->customer_identifier);
    }

    public function test_supplier_only_uuid_buyer_preserves_partner_fk_snapshot_and_loyalty_context(): void
    {
        $supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => $supplier->id,
                'name' => 'Supplier-only Sealed Buyer',
                'tax_number' => 'FR10123456789',
            ],
            eventVersion: 5,
        );

        $this->captureLoyaltyEarning();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($supplier->id, $receipt->partner_id);
        $this->assertSame('Supplier-only Sealed Buyer', $receipt->customer_name);
        $this->assertSame('FR10123456789', $receipt->customer_identifier);
        $this->assertSame($supplier->id, $this->capturedEarn?->partnerId);
    }

    public function test_archived_after_seal_uuid_buyer_preserves_partner_fk_snapshot_and_loyalty_context(): void
    {
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Archived Partner Master Name',
            'vat_number' => 'FR99999999999',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => $partner->id,
                'name' => 'Archived Sealed Buyer',
                'tax_number' => 'FR10123456789',
            ],
            eventVersion: 5,
        );
        $partner->delete();

        $this->captureLoyaltyEarning();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($partner->id, $receipt->partner_id);
        $this->assertSame('Archived Sealed Buyer', $receipt->customer_name);
        $this->assertSame('FR10123456789', $receipt->customer_identifier);
        $this->assertSame($partner->id, $this->capturedEarn?->partnerId);
    }

    // =================================================================
    // Merge-gate r1 finding 1 — buyer.contact_id is the sibling FK of
    // partner_id and must be guarded the same way.
    // =================================================================

    /**
     * The fiscal validator accepts an arbitrary non-empty string for
     * `buyer.contact_id` at EVERY event version (unlike `customer_id`, which
     * is UUID-gated from v5). `pos_receipts.contact_id` is a
     * `foreignUuid(...)->constrained('contacts')` column, so the raw value
     * would raise 22P02 and cost the whole projection. The exact shape lives in
     * the repo: `tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-15-large/payload.json`
     * seals `"contact_id": "contact-f15-001"`.
     */
    public function test_legacy_non_uuid_buyer_contact_id_lands_snapshot_with_null_contact_fk(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => 'contact-f15-001',
                'customer_id' => null,
                'name' => 'Legacy Contact Buyer',
                'tax_number' => 'FR12345678901',
            ],
            eventVersion: 5,
        );

        $this->captureLoyaltyEarning();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertNull($receipt->contact_id);
        $this->assertNull($receipt->partner_id);
        $this->assertSame('Legacy Contact Buyer', $receipt->customer_name);
        $this->assertSame('FR12345678901', $receipt->customer_identifier);
        $this->assertNull($this->capturedEarn?->contactId);
    }

    public function test_uuid_buyer_contact_outside_event_company_lands_snapshot_with_null_contact_fk(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $otherCompanyContactId = $this->seedContact($this->tenantId, (string) $otherCompany->id);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => $otherCompanyContactId,
                'customer_id' => null,
                'name' => 'Cross-company Contact Buyer',
                'tax_number' => null,
            ],
            eventVersion: 5,
        );

        $this->captureLoyaltyEarning();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertNull($receipt->contact_id);
        $this->assertSame('Cross-company Contact Buyer', $receipt->customer_name);
        $this->assertNull($this->capturedEarn?->contactId);
    }

    public function test_in_scope_uuid_buyer_contact_preserves_contact_fk_and_loyalty_context(): void
    {
        $contactId = $this->seedContact($this->tenantId, $this->companyId);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => null,
                'codice_fiscale' => null,
                'contact_id' => $contactId,
                'customer_id' => null,
                'name' => 'Scoped Contact Buyer',
                'tax_number' => null,
            ],
            eventVersion: 5,
        );

        $this->captureLoyaltyEarning();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($contactId, $receipt->contact_id);
        $this->assertSame('Scoped Contact Buyer', $receipt->customer_name);
        $this->assertSame($contactId, $this->capturedEarn?->contactId);
    }

    /**
     * Seed a `contacts` row without importing the Contact module model into the
     * fiscal test surface — the projector reaches contacts only through
     * `ContactResolverInterface`, and this helper mirrors that arm's-length
     * stance at the fixture level.
     */
    private function seedContact(string $tenantId, string $companyId): string
    {
        $id = (string) Str::uuid();
        DB::table('contacts')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'first_name' => 'Sealed',
            'last_name' => 'Contact',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_buyer_block_is_sale_time_snapshot_survives_customer_deletion(): void
    {
        // Pass 2A.PHP.2 R2 — Codex P1-1 closure (R3-tightened per R2-P2).
        // Synthesis v5 §5 invariant #4: the projector reads buyer data
        // EXCLUSIVELY from the parsed `fiscal_events.payload.buyer`
        // snapshot. A future regression that re-introduced a runtime
        // customer/contact/B2B lookup would silently fail when the
        // underlying partner row is deleted between sale-time and
        // projection-time / replay-time. The D16 grep guard
        // (PosCoreReceiptProjectionD16Test) pins the import surface;
        // THIS test pins the functional invariant end-to-end.
        //
        // **R3 closure (Codex P2 / N-05+N-08).** R2 seeded the Partner row
        // with the SAME `name` + `vat_number` as the canonical payload's
        // `buyer.name` + `buyer.tax_number` — so the INITIAL projection
        // assertion `customer_name == "Sealed Customer Name"` could not
        // distinguish payload-read from runtime-Partner-read. R3 deliberately
        // mismatches the values: the Partner row carries "Partner Display
        // Name" / "99999999999"; the canonical payload carries "Sealed
        // Customer Name" / "12345678901". The projection assertion now
        // PROVES the read comes from the payload — a regression that
        // re-introduced a runtime Partner lookup would surface "Partner
        // Display Name" and the assertion would fail loudly.
        //
        // Setup: seed a Partner $X in $this->tenantId with values that
        // DIFFER from the canonical payload. Build a SALE_RECEIPT canonical
        // payload with `buyer.customer_id = $X->id` plus a full name /
        // tax_number snapshot. Project the receipt: pos_receipts.partner_id
        // MUST be $X->id and the snapshot columns (customer_name,
        // customer_identifier) MUST come from the canonical PAYLOAD, NOT
        // a Partner::find() lookup.
        //
        // Then simulate the deletion regression by:
        //   a) DELETE the partner row (the actual deletion event the
        //      invariant defends against).
        //   b) Manually NULL out pos_receipts.partner_id via DB UPDATE
        //      (the PG migration uses `nullOnDelete` — under PG the FK
        //      cascade would do this automatically; in SQLite test the
        //      FK is not enforced so we cascade manually to put the row
        //      in the same shape).
        //   c) Re-run apply() — idempotent on fiscal_event_id — and
        //      verify the projector does NOT crash with "missing
        //      partner", does NOT attempt to re-populate partner_id from
        //      the (now-deleted) partner row, and the snapshot columns
        //      remain authoritative copies of the canonical payload.
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            // Partner row values DIFFER from payload — a regression that
            // re-introduced a runtime Partner lookup would surface these
            // strings instead of the sealed payload values, failing the
            // initial-projection assertion below.
            'name' => 'Partner Display Name',
            'vat_number' => 'FR99999999999',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: [
                'address' => [
                    'city' => 'Paris',
                    'country_code' => 'FR',
                    'postal_code' => '75001',
                    'street' => '10 rue de Rivoli',
                ],
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => $partner->id,
                // Payload snapshot values — DIFFER from the Partner row so
                // the projector's payload-read can be distinguished from a
                // (forbidden) Partner-read at INITIAL projection time.
                'name' => 'Sealed Customer Name',
                'tax_number' => 'FR12345678901',
            ],
            eventVersion: 5,
        );

        app(CompanyContext::class)->clear();
        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);

        $this->assertSame(1, $this->myReceipts()->count());
        $receipt = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($partner->id, $receipt->partner_id);
        // R3-tightened — these assertions now PROVE the projector reads
        // from `payload.buyer`, not from the `partners` table. A regression
        // that re-introduced a runtime lookup would surface "Partner Display
        // Name" / "FR99999999999" and fail loudly.
        $this->assertSame(
            'Sealed Customer Name',
            $receipt->customer_name,
            'projector MUST read customer_name from payload.buyer.name, not from partners.name',
        );
        $this->assertSame(
            'FR12345678901',
            $receipt->customer_identifier,
            'projector MUST read customer_identifier from payload.buyer.tax_number, not from partners.vat_number',
        );

        // Simulate the post-deletion shape: delete partner + cascade FK
        // to null. Under PG the `nullOnDelete` clause would handle the
        // second step; SQLite's test runner doesn't enforce FKs, so we
        // mirror the production shape manually.
        Partner::query()->where('id', $partner->id)->delete();
        DB::table('pos_receipts')->where('id', $receipt->id)->update(['partner_id' => null]);

        $refreshed = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->partner_id, 'partner FK null after deletion + cascade');
        // Snapshot columns are sealed copies — partner deletion must NOT
        // disturb them. With the Partner row deleted, a runtime-lookup
        // regression would surface as null/empty columns; the payload
        // values must persist.
        $this->assertSame(
            'Sealed Customer Name',
            $refreshed->customer_name,
            'customer_name snapshot must survive partner deletion (synthesis v5 §5 invariant #4)',
        );
        $this->assertSame(
            'FR12345678901',
            $refreshed->customer_identifier,
            'customer_identifier snapshot must survive partner deletion',
        );

        // Re-run the projection — idempotent on fiscal_event_id. The
        // projector MUST NOT crash with "missing partner" and MUST NOT
        // attempt to re-populate partner_id from the (now-empty)
        // partners table by re-querying. The early-return guard at
        // apply():143 fires before any payload traversal, BUT a
        // regression that bypassed the guard and re-queried the partner
        // table would surface here as either a thrown exception or a
        // resurrected partner_id value.
        $projector->apply($event);

        $afterReplay = $this->myReceipts()->orderBy('id')->first();
        $this->assertNotNull($afterReplay);
        // Idempotent — single row, unchanged snapshot, NULL partner_id
        // not resurrected from any runtime lookup.
        $this->assertSame(1, $this->myReceipts()->count());
        $this->assertNull(
            $afterReplay->partner_id,
            'idempotent replay must not re-populate partner_id from a runtime lookup — '.
            'the projector reads from payload only, never from the partners table',
        );
        $this->assertSame('Sealed Customer Name', $afterReplay->customer_name);
        $this->assertSame('FR12345678901', $afterReplay->customer_identifier);
        // Confirm the regression target — no partner rows remain.
        $this->assertSame(
            0,
            Partner::query()->where('id', $partner->id)->count(),
            'partner row must remain deleted — projector replay does not resurrect it',
        );
    }

    // =================================================================
    // Helpers — scoped reads (LEDGER C-7, see the class docblock)
    // =================================================================

    /**
     * `pos_receipts` rows created by THIS test.
     *
     * `setUp()` mints a fresh `Tenant` per test, so a `tenant_id` filter is an
     * exact "the rows I created" scope — it excludes the committed rows leaked
     * by `PosCoreReceiptProjectionRefundDispositionStockTest` (and any other
     * `connectionsToTransact() === []` sibling) without weakening any claim.
     */
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

    /** `stock_movements` rows created by THIS test. */
    private function myStockMovements(): Builder
    {
        return DB::table('stock_movements')->where('tenant_id', $this->tenantId);
    }

    /**
     * Whole-table counts of every table the projection writes.
     *
     * Rows leaked by an earlier class are COMMITTED and therefore constant for
     * the duration of this test, so comparing this snapshot before/after a
     * failing `apply()` proves "the transaction rolled back and nothing
     * landed" — the leaked-row-tolerant equivalent of the original
     * `assertSame(0, DB::table(...)->count())`, and strictly stronger than a
     * receipt-scoped subquery (which is vacuously empty when no parent
     * receipt row survives).
     *
     * **Single-process only.** The snapshot is sound because the leaked rows
     * are committed by an EARLIER test in the SAME PHP process and nothing
     * else writes these tables while this test runs. If this suite is ever
     * moved onto parallel workers (paratest / `--parallel`) sharing one
     * database, a concurrent worker could change the whole-table count
     * mid-test and these assertions would go flaky — at that point the
     * snapshot form must be replaced by a per-worker database or a
     * tenant-scoped equivalent.
     *
     * @return array<string, int>
     */
    private function projectionTableCounts(): array
    {
        $counts = [];
        foreach ([
            'pos_receipts',
            'pos_receipt_lines',
            'pos_receipt_payments',
            'pos_receipt_vat_details',
            'stock_movements',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Assert that not a single new row landed in any projection table since
     * the `$before` snapshot was taken.
     *
     * @param  array<string, int>  $before
     */
    private function assertNoProjectionRowsWritten(array $before, string $because): void
    {
        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::table($table)->count(),
                $because.' — no new '.$table.' row may survive',
            );
        }
    }

    // =================================================================
    // Helpers — fixtures
    // =================================================================

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row directly via the
     * Eloquent model — bypasses OutboxIngestor (Task 19).
     *
     * **Pass 2A.PHP.2 — emits the 28-key Candidate C-v3 canonical payload
     * per synthesis v5 §3.** The helper accepts old-shape overrides
     * (`paymentLinesOverride` with `payment_method_id`, `lines` with
     * `sku/unit_price/line_total`, etc.) and translates them to the new
     * canonical shape so existing test bodies stay readable. Pass 2B may
     * tighten the helper to accept only 28-key overrides once the wider
     * codebase has migrated.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride  legacy {payment_method_id, amount, method_code, instrument_*} shape
     * @param  list<array<string, mixed>>|null  $lines  legacy {sku, unit_price, line_total, quantity, tax_rate, tax_amount, product_id} shape
     * @param  list<array<string, mixed>>|null  $vatBreakdown  legacy {rate, base, amount} shape
     * @param  array<string, mixed>|null  $buyer  canonical buyer block (see synthesis v5 §3 + BuyerDTO) — null = no buyer attached
     * @param  array<string, mixed>|null  $originalReceiptReference  canonical {fiscal_event_id, original_business_date, original_receipt_uuid, refund_reason}
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
        ?array $buyer = null,
        string $invoiceTypeCode = 'SALE',
        ?array $originalReceiptReference = null,
        ?string $terminalId = null,
        int $eventVersion = 1,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        // ---- Translate legacy overrides → 28-key canonical shape. ----
        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['payment_method_id' => $this->paymentMethodId, 'amount' => '10.00', 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            // Defensive: leave malformed entries intact so
            // the "missing payment_method_id" + "outbox-ingestor shape"
            // regression tests can still exercise the failure modes via
            // their direct field access into payload. The TEST projector
            // failure modes via guards now fire downstream of the parse;
            // we surface them by leaving the canonical payload deliberately
            // mal-formed in those specific tests.
            $payments[] = [
                'amount' => $pl['amount'] ?? '10.00',
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
            ];
        }

        $lineItems = [];
        $rawLines = $lines ?? [
            ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
        ];
        foreach ($rawLines as $rl) {
            $lineItems[] = [
                'gtin' => $rl['gtin'] ?? null,
                'line_discount_amount' => $rl['discount_amount'] ?? '0.00',
                'line_discount_reason' => $rl['discount_reason'] ?? null,
                'line_subtotal' => $rl['line_total'] ?? '10.00',
                'line_vat' => $rl['tax_amount'] ?? '0.00',
                'name' => $rl['product_name'] ?? ($rl['sku'] ?? 'Default item'),
                'non_collected_subtype' => null,
                'product_id' => $rl['product_id'] ?? 'prod-default',
                // Pad legacy "1" quantity to 3-decimal scale per synthesis v5 §6.B.
                'quantity' => str_contains((string) ($rl['quantity'] ?? '1.000'), '.') ? (string) ($rl['quantity'] ?? '1.000') : ((string) ($rl['quantity'] ?? '1')).'.000',
                'sku' => $rl['sku'] ?? 'SKU-X',
                'tax_category_code' => 'Z',
                'unit_price' => $rl['unit_price'] ?? '10.00',
                // SaleReceiptV2 (M4) variant identity — null for non-variant lines.
                'variant_id' => $rl['variant_id'] ?? null,
                'variant_name' => $rl['variant_name'] ?? null,
                'variant_sku' => $rl['variant_sku'] ?? null,
                // Pad to scale-2 if integer ("0" → "0.00").
                'vat_rate' => $this->padToScaleTwo($rl['tax_rate'] ?? '0'),
            ];
        }

        $vatRows = [];
        $rawVat = $vatBreakdown ?? [
            ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
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
            'buyer' => $buyer,
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
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
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
            'event_version' => $eventVersion,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $terminalId ?? $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        $event = FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $terminalId ?? $this->terminalId,
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

    private function captureLoyaltyEarning(): void
    {
        $capture = function (SaleEarnContext $context): void {
            $this->capturedEarn = $context;
        };

        $this->app->instance(LoyaltyEarningContract::class, new class($capture) implements LoyaltyEarningContract
        {
            /** @param Closure(SaleEarnContext): void $capture */
            public function __construct(private readonly Closure $capture) {}

            public function earnForSale(SaleEarnContext $context): void
            {
                ($this->capture)($context);
            }
        });
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
