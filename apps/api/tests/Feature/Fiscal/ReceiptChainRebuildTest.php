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

    public function test_build_export_snapshot_logs_structured_error_on_canonical_bytes_tamper_for_projected_receipt(): void
    {
        // Round-2 BLOCKER closure: when a projection row's fiscal_event
        // has tampered canonical_bytes, the LIVE buildExportSnapshot
        // path emits a structured Log::error so auditors see the
        // diagnostic. The receipt is still included in the export (the
        // chain-verify pass is the primary defense — see
        // verifyReceiptChain).
        $event = $this->seedSingleFiscalEvent();

        $projectedReceipt = $this->seedProjectionReceiptLinkedTo($event);

        // Tamper the linked canonical_bytes (on SQLite the trigger is
        // not enforced; on PG the same scenario is exercised by
        // recording a hash mismatch at INSERT time, see
        // VerifyEventChainCommandTest::seedTamperedChain — here we
        // exercise the *export-time* rehash via SQLite-only mutation).
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Canonical-bytes UPDATE tamper requires SQLite; PG enforces immutability via trigger.');
        }

        DB::table('fiscal_events')
            ->where('id', $event['id'])
            ->update(['canonical_bytes' => '{"tampered":"yes"}']);

        // Capture structured Log::error calls without intercepting the
        // Log facade's behaviour (`shouldReceive` would swallow them;
        // shape assertion via a callback is the contract).
        $captured = [];
        Log::shouldReceive('error')
            ->atLeast()->once()
            ->withArgs(static function (string $msg, array $ctx) use (&$captured): bool {
                $captured[] = ['msg' => $msg, 'ctx' => $ctx];

                return true;
            });

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->copy()->subDay(),
            Carbon::now('UTC')->copy()->addDay(),
        );

        // The receipt still appears in the export.
        $this->assertNotEmpty(
            $snapshot->sales,
            'Tampered fiscal_event_backed receipt must still appear in export (chain-verify is the primary defense).',
        );
        $this->assertSame((string) $projectedReceipt->id, $snapshot->sales[0]->id);

        // The structured Log::error must have been emitted.
        $matched = false;
        foreach ($captured as $entry) {
            if (
                str_contains($entry['msg'], 'canonical_bytes rehash mismatch')
                && ($entry['ctx']['receipt_id'] ?? null) === $projectedReceipt->id
                && ($entry['ctx']['fiscal_event_id'] ?? null) === $event['id']
            ) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue(
            $matched,
            sprintf(
                'Expected canonical_bytes rehash mismatch log not found among %d captured Log::error calls',
                count($captured),
            ),
        );
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
        // Round-2 Codex T30-P2 closure: when the rebuilt verifier
        // detects a chain break (canonical_bytes rehash mismatch,
        // genesis_seed missing, or linkage broken), it MUST emit a
        // structured Log::error('chain_verification_failed', ...) with
        // terminal_id + fiscal_event_id + sequence_number +
        // expected_hash + actual_hash + failure_mode BEFORE returning
        // false. Bool return type preserved (3 callers depend on it).
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

        $captured = [];
        Log::shouldReceive('error')
            ->atLeast()->once()
            ->withArgs(static function (string $msg, array $ctx) use (&$captured): bool {
                $captured[] = ['msg' => $msg, 'ctx' => $ctx];

                return true;
            });

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($this->terminalId);
        /** @var ReceiptHashService $service */
        $service = $this->app->make(ReceiptHashService::class);

        $ok = $service->verifyTerminalChain($terminal);
        $this->assertFalse($ok, 'Tamper must produce a false return.');

        $matched = false;
        foreach ($captured as $entry) {
            if (
                $entry['msg'] === 'chain_verification_failed'
                && ($entry['ctx']['terminal_id'] ?? null) === $terminal->id
                && ($entry['ctx']['failed_fiscal_event_id'] ?? null) === $targetId
                && isset($entry['ctx']['failed_sequence_number'])
                && isset($entry['ctx']['expected_hash'])
                && isset($entry['ctx']['actual_hash'])
                && ($entry['ctx']['failure_mode'] ?? null) === 'hash_mismatch'
            ) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue(
            $matched,
            sprintf(
                'Expected structured chain_verification_failed log not found among %d captured Log::error calls',
                count($captured),
            ),
        );
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
}
