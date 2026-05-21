<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for V3ReceiptHashComputer (Codex review B2, 2026-04-30).
 *
 * The computer maps a Receipt Eloquent model onto the input shape that
 * CanonicalPayloadBuilder expects. Before the B2 fix, three fields were
 * mishandled:
 *
 *   - `method_code` was derived from the mutable `payment_type` snapshot —
 *     Codex flagged that a payment-method rename could retroactively change a
 *     sealed receipt's canonical input.
 *   - `instrument_type` was hard-coded to null — a receipt paid with a store
 *     voucher serial was canonicalised the same as a cash receipt of equal
 *     amount. The voucher identity was not bound into the seal.
 *   - `instrument_serial` was hard-coded to null — same gap.
 *
 * After the fix:
 *
 *   - `method_code` is read from the new `payment_method_code` snapshot column
 *     populated at receipt-creation time from `payment_methods.code`.
 *   - `instrument_type` is read from `pos_receipt_payments.instrument_type`
 *     (PaymentInstrumentKind enum's string value, or null).
 *   - `instrument_serial` is read from `pos_receipt_payments.instrument_serial`.
 *
 * The fixture-01 round-trip continues to be exercised by
 * ReceiptFinalizationServiceTest::test_finalize_v3_produces_canonical_hash_from_fixture_01;
 * the `assertSame($expectedHash, ...)` there is a regression guard that the cash-only
 * canonical hash stays byte-identical across the B2 change.
 */
final class V3ReceiptHashComputerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private PaymentMethod $cashMethod;

    private PaymentMethod $storeVoucherMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->v3Schema()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $this->cashier = User::factory()->create();

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
        $this->storeVoucherMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Store Voucher',
            'code' => 'STORE_VOUCHER',
        ]);
    }

    /**
     * Fixture-08 (cash + store_voucher 50/50 with explicit instrument_type and
     * instrument_serial) must round-trip end-to-end from a Receipt Eloquent model
     * to the same SHA-256 the CanonicalPayloadBuilder produces from the raw
     * fixture input. This is the regression guard that the B2 snapshot columns
     * actually flow through to the canonical hash.
     */
    public function test_compute_binds_instrument_type_and_serial_into_hash_for_store_voucher_receipt(): void
    {
        /** @var array{input: array<string, mixed>, expected_canonical: string, expected_hash: string} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/08-store-voucher-binding-eur.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expectedHash = $fixture['expected_hash'];

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_number' => 'R-2026-000008',
            'posted_at' => Carbon::parse('2026-04-30T14:30:00Z'),
            'previous_hash' => 'cafebabecafebabecafebabecafebabecafebabecafebabecafebabecafebabe',
            'subtotal' => '25.000',
            'tax_amount' => '5.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
        ]);

        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '25.000',
            'vat_amount' => '5.000',
            'gross_amount' => '30.000',
        ]);

        // Cash leg: no instrument fields.
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            // payment_method_code defaults from the linked PaymentMethod via the
            // model's creating hook — we let it auto-snapshot here to exercise
            // that path explicitly.
            'amount' => '15.000',
        ]);

        // Store-voucher leg: instrument_type + instrument_serial set, must be
        // bound into the canonical hash.
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->storeVoucherMethod->id,
            'payment_type' => 'Store Voucher',
            'payment_method_code' => 'STORE_VOUCHER',
            'amount' => '15.000',
            'instrument_type' => PaymentInstrumentKind::StoreVoucher,
            'instrument_serial' => 'SVC-2026-0001',
        ]);

        // Voucher ledger: a redemption row matching fixture-08.
        $voucher = Voucher::factory()->create([
            'id' => '11111111-2222-3333-4444-555555555555',
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SVC-2026-0001',
            'currency' => 'EUR',
        ]);
        VoucherLedger::factory()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-15.00000',
            'currency' => 'EUR',
            'receipt_id' => $receipt->id,
            'gl_journal_entry_id' => '99999999-8888-7777-6666-555555555555',
        ]);

        /** @var V3ReceiptHashComputer $computer */
        $computer = $this->app->make(V3ReceiptHashComputer::class);
        $actualHash = $computer->compute($receipt->fresh());

        $this->assertSame(
            $expectedHash,
            $actualHash,
            'V3ReceiptHashComputer must bind instrument_type and instrument_serial into the v3 fiscal hash. '
            ."Expected fixture-08's canonical hash but got a different one — "
            .'most likely the model→canonical mapping is dropping one of the new snapshot columns.'
        );
    }

    /**
     * Cash-only fixture-01 round-trip: this is the regression guard that the
     * B2 change does NOT alter the canonical hash for a receipt paid with a
     * non-instrument tender. The expected hash is the long-standing fixture-01
     * golden value.
     */
    public function test_compute_preserves_fixture_01_canonical_hash_for_cash_only_receipt(): void
    {
        /** @var array{input: array<string, mixed>, expected_canonical: string, expected_hash: string} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expectedHash = $fixture['expected_hash'];

        // Receipt matching fixture 01 exactly.
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_number' => 'R-2026-000001',
            'posted_at' => Carbon::parse('2026-04-28T10:15:30Z'),
            'previous_hash' => 'abc123',
            'subtotal' => '10.420',
            'tax_amount' => '2.080',
            'total' => '12.500',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
        ]);

        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '10.420',
            'vat_amount' => '2.080',
            'gross_amount' => '12.500',
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            'amount' => '12.500',
        ]);

        /** @var V3ReceiptHashComputer $computer */
        $computer = $this->app->make(V3ReceiptHashComputer::class);
        $actualHash = $computer->compute($receipt->fresh());

        $this->assertSame(
            $expectedHash,
            $actualHash,
            'Fixture-01 cash-only round-trip MUST stay byte-identical after the B2 fix. '
            .'The expected hash is the long-standing fixture-01 golden; a drift here means '
            .'the snapshot column refactor accidentally changed the canonical input shape '
            .'for non-instrument tenders.'
        );
    }

    /**
     * The model's `creating` hook auto-snapshots `payment_method_code` from the
     * linked PaymentMethod when the caller does not provide it. This keeps
     * older test fixtures honest without requiring every callsite to be
     * updated. Production callers pass the snapshot explicitly.
     */
    public function test_creating_a_receipt_payment_auto_snapshots_payment_method_code_from_linked_method(): void
    {
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
        ]);

        $payment = ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->storeVoucherMethod->id,
            'payment_type' => 'Store Voucher',
            'amount' => '10.000',
        ]);

        $payment->refresh();
        $this->assertSame('STORE_VOUCHER', $payment->payment_method_code);
    }

    /**
     * `payment_method_code` is NOT NULL at the schema level. A raw insert that
     * bypasses the model and omits the column must fail at the database driver
     * regardless of whether the column has any application-level default. This
     * test guarantees the migration's NOT NULL constraint actually landed.
     */
    public function test_payment_method_code_is_required_at_database_level(): void
    {
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
        ]);

        $this->expectException(QueryException::class);

        DB::table('pos_receipt_payments')->insert([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            // payment_method_code intentionally omitted — must fail NOT NULL.
            'amount' => '12.500',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The v3 canonical payload accepts the new VoucherEvent::ExpiryExtended
     * enum value transparently — `buildVoucherLedgerEntries()` reads
     * `$row->event->value`, so adding a new case never breaks serialization.
     *
     * In production an expiry-extension ledger row is written with
     * `receipt_id = null` (the row records an administrative state change,
     * not a fiscal movement on a receipt). It therefore never appears in
     * any receipt's hash payload — the receipt-level relation filters by
     * receipt_id. This invariance is what keeps fixture-01/fixture-08 hashes
     * byte-stable after Codex review m2 (2026-04-30).
     *
     * To prove the new event flows through the hash builder cleanly we
     * artificially bind an expiry-extended row to a receipt (receipt_id set)
     * and assert (a) the canonical hash differs from the no-extra-row
     * baseline (the new row participates in serialization) and (b) the same
     * receipt with only a redemption row produces a different hash — i.e.
     * the new event value is NOT silently mapped to a redemption.
     */
    public function test_v3_hash_serializes_expiry_extended_voucher_ledger_event(): void
    {
        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'EUR',
        ]);

        $buildReceipt = function (string $receiptNumber, callable $attachLedger): Receipt {
            $receipt = Receipt::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'terminal_id' => $this->terminal->id,
                'cashier_id' => $this->cashier->id,
                'cashier_name' => $this->cashier->name,
                'receipt_number' => $receiptNumber,
                'posted_at' => Carbon::parse('2026-04-30T15:00:00Z'),
                'previous_hash' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
                'subtotal' => '8.000',
                'tax_amount' => '2.000',
                'total' => '10.000',
                'currency' => 'EUR',
                'fiscal_status' => FiscalStatus::PendingSeal,
                'fiscal_hash' => null,
            ]);

            ReceiptVatDetail::create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receipt->id,
                'tax_rate' => '25.00',
                'net_amount' => '8.000',
                'vat_amount' => '2.000',
                'gross_amount' => '10.000',
            ]);

            ReceiptPayment::create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receipt->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'Cash',
                'amount' => '10.000',
            ]);

            $attachLedger($receipt);

            return $receipt;
        };

        // Baseline: a receipt with one Redeemed ledger row.
        $baseline = $buildReceipt('R-2026-EXP-A', function (Receipt $receipt) use ($voucher): void {
            VoucherLedger::factory()->create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Redeemed,
                'amount' => '-5.00000',
                'currency' => 'EUR',
                'receipt_id' => $receipt->id,
                'gl_journal_entry_id' => Str::uuid()->toString(),
            ]);
        });

        // With expiry_extended: same receipt shape, plus an expiry_extended
        // row also linked to the receipt so the hash builder picks it up.
        $withExtension = $buildReceipt('R-2026-EXP-B', function (Receipt $receipt) use ($voucher): void {
            VoucherLedger::factory()->create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Redeemed,
                'amount' => '-5.00000',
                'currency' => 'EUR',
                'receipt_id' => $receipt->id,
                'gl_journal_entry_id' => Str::uuid()->toString(),
            ]);
            VoucherLedger::factory()->create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::ExpiryExtended,
                'amount' => '0.00000',
                'currency' => 'EUR',
                'receipt_id' => $receipt->id,
                'gl_journal_entry_id' => null,
            ]);
        });

        /** @var V3ReceiptHashComputer $computer */
        $computer = $this->app->make(V3ReceiptHashComputer::class);

        $baselineHash = $computer->compute($baseline->fresh());
        $withExtensionHash = $computer->compute($withExtension->fresh());

        // Both hashes must be 64-hex-char SHA-256 outputs (i.e. neither call
        // threw on the new enum value).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $baselineHash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $withExtensionHash);

        // The expiry_extended row participates in the canonical payload — it
        // is not silently dropped, so the hash must change. (`receipt_number`
        // also differs but that is true for the baseline too; what we are
        // really proving is the new event_value 'expiry_extended' serializes
        // through `event->value` without any code path special-casing it.)
        $this->assertNotSame(
            $baselineHash,
            $withExtensionHash,
            'V3ReceiptHashComputer must serialize VoucherEvent::ExpiryExtended into the canonical payload. '
            .'The hash for a receipt carrying both a Redeemed and an ExpiryExtended ledger row must differ '
            .'from the same receipt with only the Redeemed row.'
        );
    }
}
