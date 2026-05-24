<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Task 30 — ReceiptHashService + Nf525DataProvider rebuild (spec v7 §5.0,
 * §13, §14.3, §8).
 *
 * Two contracts pinned:
 *
 *   1. `ReceiptHashService::verifyTerminalChain(Terminal)` now re-hashes
 *      the stored `fiscal_events.canonical_bytes` for the terminal in
 *      `sequence_number` order. Tampering with the pos_receipts mirror
 *      columns (model fields like `total`) does NOT cause a false break
 *      because the verifier is anchored on the authoritative
 *      `fiscal_events` row, not the projection mirror. Tampering with
 *      `canonical_bytes` itself IS caught (this is the §5.0 D1
 *      contract — re-hash stored bytes, not recompute from models).
 *      Legacy chain rows (`fiscal_event_id IS NULL`) are still verified
 *      through the legacy path (Task 21 R2 carve-out).
 *
 *   2. `Nf525DataProvider::buildExport(string $tenantId, string $companyId)`
 *      reads `fiscal_events.canonical_bytes` directly per receipt entry
 *      and exposes `canonical_bytes_source = 'fiscal_events.canonical_bytes'`
 *      so the audit-trail reader can confirm the export is not
 *      reconstructed from POS Domain models. Adds a `quarantine_section`
 *      enumerating every unresolved `fiscal_event_quarantine` row in
 *      window — per spec §8 non-admissible events MUST be visible to NF525
 *      auditors alongside the chain.
 */
#[Group('chokepoint-gate')]
final class ReceiptChainRebuildTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('a', 64);

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
            'genesis_seed' => $this->genesisSeed,
            'last_hash' => null,
        ]);
        $this->terminalId = $terminal->id;

        $this->operatorId = Str::uuid()->toString();
    }

    public function test_verify_terminal_chain_rehashes_stored_canonical_bytes_no_recompute_from_models(): void
    {
        // Seed a 3-event valid chain (fiscal_events rows only — no
        // pos_receipts projection rows). The rebuild must verify directly
        // against the fiscal_events canonical_bytes; the mirror table is
        // optional for the rebuilt verifier.
        $this->seedValidChain(3);

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($this->terminalId);
        /** @var ReceiptHashService $service */
        $service = $this->app->make(ReceiptHashService::class);

        $this->assertTrue(
            $service->verifyTerminalChain($terminal),
            'Valid fiscal_events chain must verify after the rebuild.',
        );

        // Tamper with the LATEST row's canonical_bytes (using a raw DB
        // update — the Task 8 trigger forbids canonical_bytes UPDATE on
        // PG so this path is SQLite-only. On PG the column is immutable
        // by construction; the rehash defense is still required because
        // a row could be inserted with mismatched canonical_bytes vs
        // current_hash off the ingestion path.)
        $targetId = DB::table('fiscal_events')
            ->where('tenant_id', $this->tenantId)
            ->where('terminal_id', $this->terminalId)
            ->orderByDesc('sequence_number')
            ->value('id');
        $this->assertIsString($targetId);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('fiscal_events')
                ->where('id', $targetId)
                ->update(['canonical_bytes' => '{"tampered":"yes"}']);

            $this->assertFalse(
                $service->verifyTerminalChain($terminal),
                'Tampered canonical_bytes must be detected by the re-hash.',
            );
        } else {
            // On PG the BEFORE UPDATE trigger blocks canonical_bytes
            // changes. Re-seed a fresh chain row with a deliberate
            // hash mismatch at INSERT time, mirroring the
            // VerifyEventChainCommandTest::seedTamperedChain pattern.
            $this->insertEvent(
                sequenceNumber: 99,
                canonicalBytes: '{"ok":"yes"}',
                previousHash: DB::table('fiscal_events')
                    ->where('id', $targetId)
                    ->value('current_hash'),
                currentHash: str_repeat('f', 64),
            );

            $this->assertFalse(
                $service->verifyTerminalChain($terminal),
                'Mismatched current_hash vs sha256(canonical_bytes) must be detected.',
            );
        }
    }

    public function test_build_export_snapshot_includes_quarantine_section_per_spec_8(): void
    {
        // Round-2 BLOCKER closure: the LIVE buildExportSnapshot path (the
        // one Nf525JetExportService consumes) MUST now carry the §8
        // quarantine section folded directly into the DTO. Round-1's
        // separate buildExport() is removed; the live path is the only
        // surface.
        $this->seedValidChain(2);
        $this->seedQuarantineConflict(claimedSequence: 5);

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);

        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        $this->assertNotEmpty(
            $snapshot->quarantineSection,
            'Seeded quarantine row must surface in the quarantineSection of the live snapshot DTO.',
        );

        $entry = $snapshot->quarantineSection[0];
        $this->assertSame(5, $entry['claimed_sequence_number']);
        $this->assertSame(
            IntegrityExceptionClass::SequenceConflict->value,
            $entry['integrity_exception_class'],
        );
        $this->assertArrayHasKey('envelope_id', $entry);
        $this->assertArrayHasKey('integrity_reason', $entry);
        $this->assertArrayHasKey('server_received_at', $entry);
        $this->assertArrayHasKey('raw_envelope', $entry);
    }

    public function test_build_export_snapshot_routes_tampered_receipt_to_tampered_section_not_sales(): void
    {
        // Round-3 (T30-R2-B1) closure: tamper detection ≠ tamper
        // exclusion. When a projection row's linked fiscal_events row
        // has tampered canonical_bytes (rehash != current_hash), the
        // receipt MUST be:
        //   (a) excluded from the regular sales bucket (no
        //       tampered data in the auditor's "verified" view), and
        //   (b) routed to snapshot->tamperedSection with
        //       failure_mode / expected_hash / actual_hash so auditors
        //       see the row, and
        //   (c) accompanied by a structured Log::error(
        //       'chain_verification_failed', ...) for ops triage.
        //
        // Defense-in-depth: this test ALSO verifies the structured
        // log payload does NOT include canonical_bytes content (a
        // sensitive-data leak in the log).
        $event = $this->seedSingleFiscalEvent();

        $projectedReceipt = $this->seedProjectionReceiptLinkedTo($event);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Canonical-bytes UPDATE tamper requires SQLite; PG enforces immutability via trigger.');
        }

        DB::table('fiscal_events')
            ->where('id', $event['id'])
            ->update(['canonical_bytes' => '{"tampered":"yes"}']);

        // Round-3 T30-R2-P4: use Log::spy + shouldHaveReceived (Laravel-
        // native, no Mockery setup needed). Captures every Log::error
        // call for shape assertion.
        Log::spy();

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        // (a) Tampered receipt is excluded from the regular sales bucket.
        $this->assertEmpty(
            $snapshot->sales,
            'Tampered fiscal_event_backed receipt MUST NOT appear in the verified sales bucket (round-3 T30-R2-B1).',
        );

        // (b) Tampered receipt appears in the dedicated tamperedSection.
        $this->assertNotEmpty(
            $snapshot->tamperedSection,
            'Tampered receipt MUST surface in snapshot->tamperedSection for §8 audit visibility.',
        );
        $tamperedEntry = $snapshot->tamperedSection[0];
        $this->assertSame((string) $projectedReceipt->id, $tamperedEntry['receipt_id']);
        $this->assertSame($event['id'], $tamperedEntry['fiscal_event_id']);
        $this->assertSame('sale', $tamperedEntry['bucket']);
        $this->assertSame('canonical_bytes_rehash_mismatch', $tamperedEntry['failure_mode']);
        $this->assertArrayHasKey('expected_hash', $tamperedEntry);
        $this->assertArrayHasKey('actual_hash', $tamperedEntry);

        // (c) Structured Log::error emitted with the expected shape.
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($projectedReceipt, $event): bool {
                if ($message !== 'chain_verification_failed') {
                    return false;
                }
                if (($context['receipt_id'] ?? null) !== $projectedReceipt->id) {
                    return false;
                }
                if (($context['fiscal_event_id'] ?? null) !== $event['id']) {
                    return false;
                }
                if (($context['failure_mode'] ?? null) !== 'canonical_bytes_rehash_mismatch') {
                    return false;
                }
                if (! array_key_exists('expected_hash', $context)) {
                    return false;
                }
                if (! array_key_exists('actual_hash', $context)) {
                    return false;
                }
                // Defense-in-depth: canonical_bytes content (and full
                // payload dict) must NEVER appear in the log. Asserts
                // the absence — operators see ids + hashes only.
                if (array_key_exists('canonical_bytes', $context)) {
                    return false;
                }
                if (array_key_exists('payload', $context)) {
                    return false;
                }

                return true;
            })
            ->atLeast()->once();
    }

    public function test_build_export_snapshot_hydrates_dto_from_canonical_payload_for_fiscal_event_backed_receipts(): void
    {
        // Pass 2A.PHP.2 — emit 28-key Candidate C-v3 payload per
        // synthesis v5 §3. The Nf525DataProvider now reads via
        // CanonicalPayloadReader (mapSaleReceiptFromCanonical) and sources
        // monetary fields directly from the canonical payload — same
        // contract (canonical authoritative, mirror is read-through),
        // updated field names (vat_total / transaction_discount_amount /
        // currency_code).
        $payload = [
            'business_date' => Carbon::now('UTC')->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '50.00',
                'line_vat' => '10.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => '',
                'unit_price' => '50.00',
                'vat_rate' => '20.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '60.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '50.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '60.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '60.00',
                'net_amount' => '50.00',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '10.00',
            ]],
            'vat_total' => '10.00',
            'vouchers_redeemed' => [],
        ];
        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 42,
            'event_time_device' => Carbon::now('UTC'),
            'business_date' => Carbon::now('UTC')->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => Carbon::now('UTC'),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $this->genesisSeed,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => PayloadParseStatus::Parsed->value,
            'created_at' => Carbon::now('UTC'),
        ]);

        $projectedReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'fiscal_event_id' => $eventId,
            'fiscal_hash' => $currentHash,
            'previous_hash' => $this->genesisSeed,
            'chain_sequence' => 42,
            'receipt_year' => (int) Carbon::now('UTC')->format('Y'),
            'posted_at' => Carbon::now('UTC'),
            // Tamper the projection mirror values — the export MUST
            // ignore these and pull from the canonical payload.
            'total' => '999999.99',
            'subtotal' => '888888.88',
            'tax_amount' => '777777.77',
            'discount_amount' => '666666.66',
            'currency' => 'XYZ',
        ]);

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        $this->assertCount(1, $snapshot->sales, 'Verified receipt must appear in sales bucket.');
        $entry = $snapshot->sales[0];
        $this->assertSame((string) $projectedReceipt->id, $entry->id);
        // CANONICAL values (from fiscal_events.payload) — not mirror.
        $this->assertSame('60.00', $entry->total, 'total MUST come from canonical payload, not pos_receipts mirror.');
        $this->assertSame('50.00', $entry->subtotal, 'subtotal MUST come from canonical payload.');
        $this->assertSame('10.00', $entry->taxAmount, 'tax_total → taxAmount MUST come from canonical payload.');
        $this->assertSame('0.00', $entry->discountAmount, 'discount_total → discountAmount MUST come from canonical payload.');
        $this->assertSame('EUR', $entry->currency, 'currency MUST come from canonical payload.');
    }

    public function test_build_export_snapshot_pos_receipts_fiscal_hash_drift_does_not_trigger_canonical_bytes_log(): void
    {
        // Round-3 (T30-R2-B1) positive-direction test: tampering the
        // pos_receipts MIRROR fiscal_hash (NOT the authoritative
        // fiscal_events.current_hash) must NOT trigger a tamper log.
        // The §5.0 D1 contract: the hash source is
        // fiscal_events.current_hash; pos_receipts.fiscal_hash is a
        // mirror only.
        $event = $this->seedSingleFiscalEvent();
        $projectedReceipt = $this->seedProjectionReceiptLinkedTo($event);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Mirror update requires SQLite.');
        }

        // Tamper the projection mirror's fiscal_hash — fiscal_events
        // canonical_bytes + current_hash are intact.
        DB::table('pos_receipts')
            ->where('id', $projectedReceipt->id)
            ->update(['fiscal_hash' => str_repeat('e', 64)]);

        Log::spy();

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        // Receipt remains in verified sales — mirror drift is not a
        // canonical-truth tamper.
        $this->assertCount(1, $snapshot->sales);
        $this->assertEmpty($snapshot->tamperedSection);

        // No chain_verification_failed log because canonical_bytes
        // and current_hash are intact.
        Log::shouldNotHaveReceived('error', [
            'chain_verification_failed',
            \Mockery::any(),
        ]);
    }

    public function test_build_quarantine_section_includes_in_table_quarantined_fiscal_events(): void
    {
        // Round-3 (T30-R2-P3) closure: spec §8 requires the export's
        // quarantine section to span BOTH partitions:
        //   - fiscal_event_quarantine (sequence_conflict, malformed)
        //   - fiscal_events WHERE integrity_status='quarantined' OR
        //     payload_parse_status='failed' (in-table verified-but-flagged)
        // Each row is tagged with source so auditors can distinguish.
        $this->seedQuarantineConflict(claimedSequence: 7);
        $this->seedInTableQuarantinedFiscalEvent();

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        $this->assertCount(
            2,
            $snapshot->quarantineSection,
            'Both quarantine partitions must surface in the export.',
        );

        $sources = array_column($snapshot->quarantineSection, 'source');
        sort($sources);
        $this->assertSame(['fiscal_events_in_table', 'quarantine_table'], $sources);

        foreach ($snapshot->quarantineSection as $entry) {
            // Spec §8 fields auditors need to triage:
            $this->assertArrayHasKey('envelope_id', $entry);
            $this->assertArrayHasKey('claimed_sequence_number', $entry);
            $this->assertArrayHasKey('integrity_exception_class', $entry);
            $this->assertArrayHasKey('integrity_reason', $entry);
            $this->assertArrayHasKey('integrity_status', $entry);
            $this->assertArrayHasKey('server_received_at', $entry);
            $this->assertArrayHasKey('previous_hash', $entry);
            $this->assertArrayHasKey('current_hash', $entry);
            $this->assertArrayHasKey('event_type', $entry);
            $this->assertArrayHasKey('payload_parse_status', $entry);
        }
    }

    public function test_verify_receipt_chain_delegates_to_receipt_hash_service_for_fiscal_event_backed_rows(): void
    {
        // Round-2 BLOCKER closure: the LIVE verifyReceiptChain endpoint
        // (Nf525ExportController::verifyChains) must use the rebuilt
        // canonical-bytes verifier for fiscal_event-backed rows. Seeding
        // a valid fiscal_events chain (no legacy rows) must return
        // isValid=true; tampering with the canonical_bytes must flip it
        // to isValid=false WITHOUT the verifier touching POS Domain
        // model fields.
        $this->seedValidChain(3);

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);

        $resultClean = $provider->verifyReceiptChain($this->terminalId);
        $this->assertTrue($resultClean->isValid, 'Clean fiscal_events chain must verify via the rebuilt arm.');

        // Tamper the latest row's canonical_bytes (SQLite-only mutation
        // path — PG enforces immutability via trigger; the equivalent
        // scenario there is a hash-mismatched INSERT).
        $targetId = DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->orderByDesc('sequence_number')
            ->value('id');
        $this->assertIsString($targetId);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('fiscal_events')
                ->where('id', $targetId)
                ->update(['canonical_bytes' => '{"tampered":"yes"}']);

            $resultTampered = $provider->verifyReceiptChain($this->terminalId);
            $this->assertFalse(
                $resultTampered->isValid,
                'verifyReceiptChain must delegate to the rebuilt canonical-bytes verifier and detect tampering.',
            );
        }
    }

    public function test_pos_receipts_mirror_tamper_does_not_break_fiscal_events_chain(): void
    {
        // Round-2 Opus P2-2 closure: the §5.0 D1 mirror-tamper contract.
        // pos_receipts is a PROJECTION mirror; the chain truth lives on
        // fiscal_events.canonical_bytes. Mutating pos_receipts.total
        // (off-path, e.g. from an admin SQL session) MUST NOT cause a
        // false chain break — the verifier re-hashes canonical_bytes,
        // not model fields.
        $event = $this->seedSingleFiscalEvent();
        $projectedReceipt = $this->seedProjectionReceiptLinkedTo($event);

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($this->terminalId);
        /** @var ReceiptHashService $service */
        $service = $this->app->make(ReceiptHashService::class);

        // Sanity: clean chain verifies.
        $this->assertTrue($service->verifyTerminalChain($terminal));

        // Tamper the pos_receipts mirror field.
        DB::table('pos_receipts')->where('id', $projectedReceipt->id)->update([
            'total' => '999999.99',
        ]);

        // Chain must STILL verify — the verifier walks fiscal_events,
        // not pos_receipts.
        $this->assertTrue(
            $service->verifyTerminalChain($terminal),
            'pos_receipts mirror tamper must NOT break the fiscal_events chain — §5.0 D1 contract.',
        );
    }

    public function test_verify_terminal_chain_logs_structured_failure_on_canonical_bytes_tamper(): void
    {
        // Round-3 (T30-R2-P4): migrated to Log::spy() — Laravel-native,
        // no Mockery setup needed. Same contract as round-2: when the
        // rebuilt verifier detects a chain break, it MUST emit a
        // structured Log::error('chain_verification_failed', ...)
        // BEFORE returning false. Bool return preserved (3 callers).
        //
        // Defense-in-depth: also asserts the payload does NOT include
        // canonical_bytes content (sensitive-data leak prevention).
        $this->seedValidChain(2);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Tamper path requires SQLite; PG enforces canonical_bytes immutability via trigger.');
        }

        $targetId = DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->orderByDesc('sequence_number')
            ->value('id');
        $this->assertIsString($targetId);

        DB::table('fiscal_events')
            ->where('id', $targetId)
            ->update(['canonical_bytes' => '{"tampered":"yes"}']);

        Log::spy();

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($this->terminalId);
        /** @var ReceiptHashService $service */
        $service = $this->app->make(ReceiptHashService::class);

        $ok = $service->verifyTerminalChain($terminal);
        $this->assertFalse($ok, 'Tamper must produce a false return.');

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($terminal, $targetId): bool {
                if ($message !== 'chain_verification_failed') {
                    return false;
                }
                if (($context['terminal_id'] ?? null) !== $terminal->id) {
                    return false;
                }
                if (($context['failed_fiscal_event_id'] ?? null) !== $targetId) {
                    return false;
                }
                if (! array_key_exists('failed_sequence_number', $context)) {
                    return false;
                }
                if (! array_key_exists('expected_hash', $context)) {
                    return false;
                }
                if (! array_key_exists('actual_hash', $context)) {
                    return false;
                }
                if (($context['failure_mode'] ?? null) !== 'hash_mismatch') {
                    return false;
                }
                // Defense-in-depth: canonical_bytes must NOT leak.
                if (array_key_exists('canonical_bytes', $context)) {
                    return false;
                }

                return true;
            })
            ->atLeast()->once();
    }

    // =================================================================
    // Helpers — mirror VerifyEventChainCommandTest seeders so the chain
    // fixtures stay schema-aligned with the production migration.
    // =================================================================

    private function seedValidChain(int $length): void
    {
        $previousHash = $this->genesisSeed;
        for ($seq = 1; $seq <= $length; $seq++) {
            $canonicalBytes = json_encode(
                ['event' => 'seq'.$seq, 'sequence_number' => $seq],
                JSON_THROW_ON_ERROR,
            );
            $currentHash = hash('sha256', $canonicalBytes);

            $this->insertEvent(
                sequenceNumber: $seq,
                canonicalBytes: $canonicalBytes,
                previousHash: $previousHash,
                currentHash: $currentHash,
            );

            $previousHash = $currentHash;
        }
    }

    private function insertEvent(
        int $sequenceNumber,
        string $canonicalBytes,
        string $previousHash,
        string $currentHash,
    ): void {
        $now = Carbon::now('UTC');
        $row = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => $now,
        ];

        DB::table('fiscal_events')->insert($row);
    }

    /**
     * Seed a single SALE_RECEIPT fiscal_events row (no chain — just one
     * standalone admissible row) and return the raw row attributes the
     * caller will need for cross-table assertions.
     *
     * @return array{id: string, canonical_bytes: string, current_hash: string}
     */
    private function seedSingleFiscalEvent(): array
    {
        $now = Carbon::now('UTC');
        $canonicalBytes = json_encode(
            ['event' => 'seq1', 'sequence_number' => 1],
            JSON_THROW_ON_ERROR,
        );
        $currentHash = hash('sha256', $canonicalBytes);
        $id = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $this->genesisSeed,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => $now,
        ]);

        return [
            'id' => $id,
            'canonical_bytes' => $canonicalBytes,
            'current_hash' => $currentHash,
        ];
    }

    /**
     * Seed a pos_receipts projection row linked to the given fiscal_events
     * row. fiscal_status is FISCALIZED so the row is picked up by the
     * sales bucket in buildExportSnapshot.
     *
     * @param  array{id: string, canonical_bytes: string, current_hash: string}  $event
     */
    private function seedProjectionReceiptLinkedTo(array $event): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'fiscal_event_id' => $event['id'],
            'fiscal_hash' => $event['current_hash'],
            'previous_hash' => $this->genesisSeed,
            'chain_sequence' => 1,
            'receipt_year' => (int) Carbon::now('UTC')->format('Y'),
            'posted_at' => Carbon::now('UTC'),
        ]);
    }

    private function seedQuarantineConflict(int $claimedSequence): void
    {
        $canonicalBytes = '{"event":"conflict_envelope"}';
        $envelopeEventId = Str::uuid()->toString();
        $conflictingEventId = Str::uuid()->toString();

        $row = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'envelope_event_id' => $envelopeEventId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'claimed_sequence_number' => $claimedSequence,
            'event_time_device' => Carbon::now('UTC'),
            'business_date' => Carbon::now('UTC')->startOfDay(),
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => str_repeat('c', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'canonical_bytes' => $canonicalBytes,
            'raw_envelope' => json_encode(['envelope_id' => $envelopeEventId], JSON_THROW_ON_ERROR),
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'conflicting_event_id' => $conflictingEventId,
            'server_received_at' => Carbon::now('UTC'),
        ];

        $quarantine = (new FiscalEventQuarantine)->forceFill(array_merge(
            $row,
            [
                'integrity_exception_class' => IntegrityExceptionClass::SequenceConflict->value,
                'integrity_exception_reason' => 'sequence_conflict:different_event_at_occupied_slot',
            ],
        ));
        $quarantine->save();
    }

    /**
     * Round-3 (T30-R2-P3) helper: seed a fiscal_events row that landed
     * admissible but was flagged AT INGESTION with
     * integrity_status='quarantined' and a canonical_parse_failure
     * exception class. These rows must surface in the export's
     * quarantine section per spec §8 ("in-table
     * integrity_status=quarantined covers admitted-but-flagged events").
     */
    private function seedInTableQuarantinedFiscalEvent(): void
    {
        $now = Carbon::now('UTC');
        $canonicalBytes = '{"event":"in_table_quarantine_demo"}';

        DB::table('fiscal_events')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1001,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('b', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Quarantined->value,
            'integrity_exception_class' => IntegrityExceptionClass::CanonicalParseFailure->value,
            'integrity_exception_reason' => 'parse_failure:simulated_schema_violation',
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Failed->value,
            'created_at' => $now,
        ]);
    }
}
