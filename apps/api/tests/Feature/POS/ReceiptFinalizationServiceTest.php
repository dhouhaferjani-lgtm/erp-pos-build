<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for ReceiptFinalizationService.
 *
 * Covers:
 *   - v2 legacy hash parity (byte-for-byte match with ReceiptHashService::calculateHash)
 *   - Terminal last_hash and current_sequence advance
 *   - Idempotency on already-fiscalized receipt
 *   - Throws on voided/invalid status
 *   - v3 fixture 01 round-trip hash match
 */
final class ReceiptFinalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private PaymentMethod $cashPaymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashPaymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
    }

    // -------------------------------------------------------------------------
    // v2 legacy hash parity
    // -------------------------------------------------------------------------

    public function test_finalize_v2_cash_only_produces_legacy_hash_byte_for_byte(): void
    {
        // Arrange: v2 terminal (default schema version)
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $receipt = $this->makePendingSealReceipt($terminal, [
            'receipt_number' => 'T001-C001-L01-POS01-2026-00000001',
            'total' => '12.500',
            'subtotal' => '10.417',
            'tax_amount' => '2.083',
            'currency' => 'EUR',
            'previous_hash' => null,
        ]);

        // Create a VAT detail row (required for vat_breakdown_hash computation)
        $vatData = [
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '10.417',
            'vat_amount' => '2.083',
            'gross_amount' => '12.500',
        ];
        ReceiptVatDetail::create(array_merge(['id' => Str::uuid()->toString()], $vatData));

        // Create a cash payment row
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashPaymentMethod->id,
            'payment_type' => 'Cash',
            'amount' => '12.500',
        ]);

        // Pre-compute what the legacy hash service would produce for this receipt
        /** @var ReceiptHashService $legacyService */
        $legacyService = app(ReceiptHashService::class);

        // Load the terminal relation so the hash service can access genesis_seed
        $receipt->setRelation('terminal', $terminal);
        $expectedHash = $legacyService->calculateHash($receipt, $terminal->last_hash);

        // Act
        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $finalized = $service->finalize($receipt);

        // Assert: byte-for-byte parity with legacy hash
        $this->assertSame($expectedHash, $finalized->fiscal_hash);
        $this->assertSame(FiscalStatus::Fiscalized, $finalized->fiscal_status);
    }

    // -------------------------------------------------------------------------
    // Terminal chain advance
    // -------------------------------------------------------------------------

    public function test_finalize_advances_terminal_last_hash_and_sequence(): void
    {
        $genesisSeed = bin2hex(random_bytes(32));
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'genesis_seed' => $genesisSeed,
            'last_hash' => null,
            'current_sequence' => 5,
        ]);

        $receipt = $this->makePendingSealReceipt($terminal, [
            'total' => '50.000',
            'subtotal' => '42.017',
            'tax_amount' => '7.983',
            'currency' => 'EUR',
            'previous_hash' => null,
        ]);

        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'tax_rate' => '19.00',
            'net_amount' => '42.017',
            'vat_amount' => '7.983',
            'gross_amount' => '50.000',
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashPaymentMethod->id,
            'payment_type' => 'Cash',
            'amount' => '50.000',
        ]);

        $receipt->setRelation('terminal', $terminal);

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $finalized = $service->finalize($receipt);

        $terminalFresh = Terminal::find($terminal->id);
        $this->assertNotNull($terminalFresh);
        $this->assertSame($finalized->fiscal_hash, $terminalFresh->last_hash);
        $this->assertSame(6, $terminalFresh->current_sequence);
        $this->assertSame(5, $finalized->chain_sequence);
        $this->assertNull($finalized->previous_hash);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_finalize_is_idempotent_on_already_fiscalized_receipt(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => 'aabbccdd',
            'current_sequence' => 3,
        ]);

        // Build a receipt that is already fiscalized (not pending_seal)
        $existingHash = hash('sha256', 'existing-hash');
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'fiscal_hash' => $existingHash,
            'chain_sequence' => 2,
        ]);

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $result = $service->finalize($receipt);

        // Same record returned, hash unchanged, terminal NOT advanced
        $this->assertSame($existingHash, $result->fiscal_hash);
        $this->assertSame(FiscalStatus::Fiscalized, $result->fiscal_status);

        $terminalFresh = Terminal::find($terminal->id);
        $this->assertNotNull($terminalFresh);
        $this->assertSame(3, $terminalFresh->current_sequence); // unchanged
        $this->assertSame('aabbccdd', $terminalFresh->last_hash); // unchanged
    }

    // -------------------------------------------------------------------------
    // Throws on voided receipt
    // -------------------------------------------------------------------------

    public function test_finalize_throws_on_voided_receipt(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'fiscal_status' => FiscalStatus::Voided,
            'is_voided' => true,
        ]);

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/voided/');

        $service->finalize($receipt);
    }

    // -------------------------------------------------------------------------
    // v3 fixture 01 round-trip
    // -------------------------------------------------------------------------

    /**
     * Sets up a receipt that exactly matches fixture 01 (cash-only EUR) and
     * asserts the finalized fiscal_hash equals the fixture's expected_hash.
     *
     * Fixture 01 spec:
     *   receipt_number: R-2026-000001
     *   posted_at:      2026-04-28T10:15:30Z
     *   previous_hash:  abc123
     *   total:          12.50
     *   currency:       EUR
     *   vat:            rate=0.20 (20%), amount=2.08
     *   payment:        method_code=cash, payment_type=pos, amount=12.50
     *   vouchers:       []
     *   exchange:       null
     *   audit:          null
     *
     * Expected hash: 4db73253d2456bb80d2577904c4a600f72f218648b7b0350c84311e4f4871003
     */
    public function test_finalize_v3_produces_canonical_hash_from_fixture_01(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json')),
            true
        );

        $this->assertIsArray($fixture, 'Fixture 01 must be a valid JSON object');

        $expectedHash = $fixture['expected_hash'];

        // Terminal pinned to v3, previous_hash = "abc123" (from fixture)
        $terminal = Terminal::factory()->v3Schema()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'last_hash' => 'abc123',
            'current_sequence' => 1,
        ]);

        // Receipt matching fixture 01 exactly
        $postedAt = Carbon::parse('2026-04-28T10:15:30Z');

        $receipt = $this->makePendingSealReceipt($terminal, [
            'receipt_number' => 'R-2026-000001',
            'posted_at' => $postedAt,
            'total' => '12.500',
            'subtotal' => '10.420',
            'tax_amount' => '2.080',
            'currency' => 'EUR',
            'previous_hash' => null, // will be set by finalize from terminal.last_hash
        ]);

        // VAT: rate 20% (stored as "20.00"), vat_amount = "2.08"
        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '10.420',
            'vat_amount' => '2.080',
            'gross_amount' => '12.500',
        ]);

        // Payment: stored payment_type = "cash" (the tender method name).
        // V3ReceiptHashComputer Phase 1 mapping:
        //   method_code = strtolower(payment_type) = "cash"  → matches fixture method_code
        //   payment_type = "pos" (Phase 1 sentinel for POS channel) → matches fixture payment_type
        // This produces the exact canonical input that fixture 01 was built from.
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashPaymentMethod->id,
            'payment_type' => 'cash',
            'amount' => '12.500',
        ]);

        $receipt->setRelation('terminal', $terminal);

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $finalized = $service->finalize($receipt);

        $this->assertSame($expectedHash, $finalized->fiscal_hash,
            'v3 fixture 01 round-trip: fiscal_hash must match golden expected_hash'
        );
        $this->assertSame(FiscalStatus::Fiscalized, $finalized->fiscal_status);
        $this->assertSame('abc123', $finalized->previous_hash);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a pending_seal receipt on the given terminal with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makePendingSealReceipt(Terminal $terminal, array $overrides = []): Receipt
    {
        return Receipt::factory()->pendingSeal()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'currency' => 'EUR',
        ], $overrides));
    }
}
