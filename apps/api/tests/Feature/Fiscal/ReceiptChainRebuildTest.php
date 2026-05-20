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
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_nf525_data_provider_reads_verified_canonical_bytes_and_includes_quarantine_state(): void
    {
        $this->seedValidChain(2);
        $this->seedQuarantineConflict(claimedSequence: 5);

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);

        $export = $provider->buildExport($this->tenantId, $this->companyId);

        // Shape is array-of-arrays — assertArrayHasKey on each layer is
        // the load-bearing contract; the assertIsArray on the top-level
        // is redundant given PHPStan's inference from the @return tag.
        $this->assertArrayHasKey(
            'quarantine_section',
            $export,
            'Export must include quarantine_section per spec §8.',
        );
        $this->assertNotEmpty(
            $export['quarantine_section'],
            'Seeded quarantine row must surface in the quarantine_section.',
        );

        $this->assertArrayHasKey('receipts', $export);
        $this->assertNotEmpty(
            $export['receipts'],
            'Export must enumerate at least one receipt — the seeded SALE_RECEIPT chain.',
        );
        $this->assertArrayHasKey(
            'canonical_bytes_source',
            $export['receipts'][0],
            'Each receipt entry must declare canonical_bytes_source for audit-trail provenance.',
        );
        $this->assertSame(
            'fiscal_events.canonical_bytes',
            $export['receipts'][0]['canonical_bytes_source'],
            'Receipt entries must pull from fiscal_events, not from POS Domain models.',
        );

        // Quarantine entry shape: spec §8 reader contract.
        $q = $export['quarantine_section'][0];
        $this->assertArrayHasKey('envelope_id', $q);
        $this->assertArrayHasKey('claimed_sequence_number', $q);
        $this->assertArrayHasKey('integrity_exception_class', $q);
        $this->assertArrayHasKey('integrity_reason', $q);
        $this->assertArrayHasKey('server_received_at', $q);
        $this->assertArrayHasKey('raw_envelope', $q);
        $this->assertSame(5, $q['claimed_sequence_number']);
        $this->assertSame(
            IntegrityExceptionClass::SequenceConflict->value,
            $q['integrity_exception_class'],
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
