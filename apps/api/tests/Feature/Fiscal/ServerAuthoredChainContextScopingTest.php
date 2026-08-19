<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Services\TerminalRegistrySnapshotService;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\ServerAuthoredChainPlacementException;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Application\Services\VirtualAdminTerminalResolver;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ES-09 — server-authored fiscal events resolved the chain head by
 * `(tenant_id, terminal_id)` ONLY.
 *
 * Register row (`ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md:132`):
 * *"Server-authored fiscal events resolve the chain head by `(tenant, terminal)`
 * only — no `company_id`, no `chain_context` — while every other component is
 * `(tenant, company, terminal, chain_context)`-scoped; on any terminal carrying
 * two contexts (every v3 terminal: `z_session` + `operational`) they can write a
 * `previous_hash` from a different chain and emit a permanently unverifiable
 * link. Both also stamp `integrity_status=Verified` / `payload_parse_status=Parsed`
 * unconditionally, skipping `verifyLinkage`/`verifyClock`."*
 *
 * **This is a CORRECTNESS contract, not a refusal contract** (brief R-6). Nothing
 * legitimate starts being refused: the after-state is that a write which
 * previously resolved the WRONG head now resolves the RIGHT one, and the
 * two-context append SUCCEEDS. A single-context fixture passes vacuously and is
 * not evidence, which is why every fixture here seeds BOTH contexts with the
 * OTHER context deliberately DEEPER — under the old unscoped
 * `orderByDesc('sequence_number')->first()` the deeper chain's head always wins,
 * so the defect is deterministic rather than a coin flip between two equal-depth
 * heads.
 *
 * **Scope, amended** by
 * `docs/handoff/reviews/es-wave-a0/ORCHESTRATOR-RULING-2026-08-19-m2-stop-c.md`:
 * ES-09 covers `TerminalRegistrySnapshotService` and
 * `VirtualAdminFiscalEventService` ONLY. `ReceiptHashService`'s
 * `(company_id, chain_context)` partition was pulled forward into M2 (commit
 * `bc692820a`) and must NOT be re-fixed here — but the end-to-end test below
 * still asserts the full shape INCLUDING the receipt-verification arm.
 *
 * The correct contrast is `OutboxIngestor`'s prior-row read, which keys on
 * `tenant_id + company_id + terminal_id + chain_context`; the fix matches that
 * shape rather than inventing a third one. The schema has enforced it since
 * `2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31`, where the
 * UNIQUE is `(tenant_id, company_id, terminal_id, chain_context, sequence_number)`.
 * That UNIQUE is also why the defect is SILENT: the mis-placed row does not
 * collide with anything, so it INSERTs cleanly and only the verifier ever
 * notices.
 */
final class ServerAuthoredChainContextScopingTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private string $genesisSeed;

    private User $verifierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('a', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => $this->genesisSeed,
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;

        $operator = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'ES-09 operator']);
        $this->operatorId = $operator->id;

        $this->verifierUser = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'ES-09 verifier']);
        $this->verifierUser->givePermissionTo('fiscal.events.verify_chain');
    }

    // =================================================================
    // ES-09, defect (a) — context-blind head resolution.
    // TerminalRegistrySnapshotService.
    // =================================================================

    public function test_snapshot_service_resolves_the_head_of_its_own_chain_context_not_the_deepest_chain(): void
    {
        [$operationalHead, $zSessionHead] = $this->seedTwoContextTerminal();

        $event = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);

        $this->assertSame(
            'operational',
            (string) $row->chain_context,
            'ES-09: the server-authored snapshot belongs to the operational chain; leaving chain_context to a DB '
            .'default while scoping the read explicitly is the implicit coupling that produced this defect.',
        );
        $this->assertSame(
            3,
            (int) $row->sequence_number,
            'ES-09 defect (a): the operational chain head is sequence 2, so the snapshot is sequence 3. Reading the '
            .'head on (tenant_id, terminal_id) alone picks the DEEPER z_session chain (head 5) and writes 6 — a '
            .'permanent numeric gap in the operational chain that no UNIQUE constraint catches, because the UNIQUE '
            .'is per (tenant, company, terminal, chain_context, sequence).',
        );
        $this->assertSame(
            $operationalHead,
            (string) $row->previous_hash,
            'ES-09 defect (a): previous_hash must be the OPERATIONAL head. Linking to the z_session head produces a '
            .'row that hashes correctly and is still permanently unverifiable — it is a link into the wrong chain.',
        );
        $this->assertNotSame(
            $zSessionHead,
            (string) $row->previous_hash,
            'ES-09 defect (a): the z_session head must never appear as an operational row’s previous_hash.',
        );
    }

    // =================================================================
    // ES-09, defect (a) — the SAME defect at the second call site.
    // VirtualAdminFiscalEventService, both of its live callers
    // (ACCOUNT_STATUS_CHANGED and DEPOSIT_RECEIPT share one
    // resolveChainPlacement()).
    // =================================================================

    public function test_virtual_admin_account_status_change_resolves_the_head_of_its_own_chain_context(): void
    {
        $partner = $this->partner();
        $virtualTerminal = $this->app->make(VirtualAdminTerminalResolver::class)
            ->resolve($this->tenantId, $this->companyId);
        [$operationalHead, $zSessionHead] = $this->seedTwoContextTerminal(
            terminalId: $virtualTerminal->id,
            genesisSeed: (string) $virtualTerminal->genesis_seed,
        );

        $event = $this->app->make(VirtualAdminFiscalEventService::class)->appendAccountStatusChanged(
            partner: $partner,
            oldStatus: CustomerAccountStatus::Active,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: $this->operatorId,
            reason: 'ES-09 two-context control',
        );

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('operational', (string) $row->chain_context);
        $this->assertSame(
            3,
            (int) $row->sequence_number,
            'ES-09 defect (a) at the second call site: ACCOUNT_STATUS_CHANGED is a live caller named by the register row.',
        );
        $this->assertSame($operationalHead, (string) $row->previous_hash);
        $this->assertNotSame($zSessionHead, (string) $row->previous_hash);
    }

    public function test_virtual_admin_deposit_receipt_resolves_the_head_of_its_own_chain_context(): void
    {
        $partner = $this->partner();
        $virtualTerminal = $this->app->make(VirtualAdminTerminalResolver::class)
            ->resolve($this->tenantId, $this->companyId);
        [$operationalHead, $zSessionHead] = $this->seedTwoContextTerminal(
            terminalId: $virtualTerminal->id,
            genesisSeed: (string) $virtualTerminal->genesis_seed,
        );

        $event = $this->app->make(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $this->operatorId,
            actorName: 'ES-09 operator',
            currencyCode: 'TND',
            amount: '25.000',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('operational', (string) $row->chain_context);
        $this->assertSame(
            3,
            (int) $row->sequence_number,
            'ES-09 defect (a): DEPOSIT_RECEIPT is the register row’s second named live caller and shares the same '
            .'resolveChainPlacement(), so both arms must be proven, not one.',
        );
        $this->assertSame($operationalHead, (string) $row->previous_hash);
        $this->assertNotSame($zSessionHead, (string) $row->previous_hash);
    }

    // =================================================================
    // ES-09, the CORRECTNESS after-state — the two-context append
    // SUCCEEDS end to end, on BOTH chains, with the receipt-verification
    // arm still green (STOP-C ruling: M4 must not re-fix ReceiptHashService,
    // but must still assert the full shape).
    // =================================================================

    public function test_the_two_context_append_succeeds_end_to_end_and_both_chains_still_verify(): void
    {
        $this->seedTwoContextTerminal();

        $event = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        // The append SUCCEEDED — this is the point of a correctness contract.
        $this->assertSame(
            1,
            DB::table('fiscal_events')->where('id', $event->id)->count(),
            'ES-09 is CORRECTNESS, not refusal: the two-context append must SUCCEED. A diff that makes this write '
            .'start failing has misread the row.',
        );

        $this->withoutMockingConsoleOutput();

        $operationalExit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--chain-context' => 'operational',
            '--actor-id' => $this->verifierUser->id,
        ]);
        $this->assertSame(
            0,
            $operationalExit,
            'ES-09: after the server-authored append the OPERATIONAL chain must still verify. Output: '
            .Artisan::output(),
        );

        $zSessionExit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--chain-context' => 'z_session',
            '--actor-id' => $this->verifierUser->id,
        ]);
        $this->assertSame(
            0,
            $zSessionExit,
            'ES-09: the OTHER context must be untouched by the append — a fix that repaired one chain by disturbing '
            .'the other would be worse than the defect. Output: '.Artisan::output(),
        );

        // The receipt-verification arm, asserted per the STOP-C ruling. This
        // wave does NOT re-fix it (M2 landed the (company_id, chain_context)
        // partition at bc692820a); the assertion exists so an ES-09 change
        // that disturbed the receipt arm could not pass unnoticed.
        $posExit = Artisan::call('pos:verify-chains', [
            '--company' => $this->companyId,
        ]);
        $this->assertSame(
            0,
            $posExit,
            'ES-09 end-to-end shape: the receipt-verification arm must still be green after the server-authored '
            .'two-context append. Output: '.Artisan::output(),
        );
    }

    // =================================================================
    // ES-09, defect (b) — the unconditional Verified / Parsed stamp.
    // The verdict must be DERIVED from the link the service just built,
    // not asserted.
    // =================================================================

    public function test_a_legitimate_server_authored_append_derives_verified_rather_than_asserting_it(): void
    {
        $this->seedTwoContextTerminal();

        $event = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(IntegrityStatus::Verified->value, (string) $row->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed->value, (string) $row->payload_parse_status);
        $this->assertNull($row->integrity_exception_class);

        // The derivation is real, not decorative: the persisted hash is the
        // hash of the persisted canonical bytes, and the link points at the
        // operational head.
        $canonicalBytes = $this->stringifyBytes($row->canonical_bytes);
        $this->assertSame(
            hash('sha256', $canonicalBytes),
            (string) $row->current_hash,
            'ES-09 defect (b): a Verified stamp must be earned by the row it describes.',
        );
    }

    public function test_a_server_authored_append_onto_a_clock_rolled_back_head_refuses_instead_of_stamping_verified(): void
    {
        // The prior operational head carries an event_time_device far in the
        // FUTURE, so appending "now" is a clock ROLLBACK against it — exactly
        // the condition `OutboxIngestor::verifyClock()` quarantines a device
        // envelope for. Before this milestone both services skipped that check
        // and stamped `integrity_status = Verified` anyway, which is the
        // register row's defect (b) stated literally.
        $this->seedTwoContextTerminal(
            operationalHeadEventTimeDevice: Carbon::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z'),
        );

        $before = DB::table('fiscal_events')->count();

        try {
            $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
                $this->tenantId,
                $this->companyId,
                $this->terminalId,
                $this->operatorId,
            );
            $this->fail(
                'ES-09 defect (b): the service must not stamp Verified on a link whose clock is provably backwards. '
                .'Failing closed is the honest outcome — a server-authored row is not a device fact the ledger is '
                .'obliged to preserve, so there is nothing to quarantine and admit.',
            );
        } catch (ServerAuthoredChainPlacementException $e) {
            $this->assertStringContainsString('time_anomaly', $e->getMessage());
        }

        $this->assertSame(
            $before,
            DB::table('fiscal_events')->count(),
            'ES-09 defect (b): the refusal must roll back — a half-written row claiming Verified is worse than no row.',
        );
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * Seed a two-context terminal with the z_session chain DELIBERATELY
     * DEEPER than the operational one (5 vs 2).
     *
     * The depth asymmetry is the whole point: the pre-fix
     * `orderByDesc('sequence_number')->first()` over
     * `(tenant_id, terminal_id)` returns the deepest row on the terminal
     * REGARDLESS of context, so a deeper z_session chain makes the defect
     * deterministic. With both chains at equal depth the pre-fix query
     * returns whichever row the planner happens to order first, and a test
     * built on that would be flaky rather than falsifying.
     *
     * @return array{0: string, 1: string} [operational head hash, z_session head hash]
     */
    private function seedTwoContextTerminal(
        ?string $terminalId = null,
        ?string $genesisSeed = null,
        ?string $operationalHeadEventTimeDevice = null,
    ): array {
        $terminalId ??= $this->terminalId;
        $genesisSeed ??= $this->genesisSeed;

        $operationalHead = $genesisSeed;
        for ($sequence = 1; $sequence <= 2; $sequence++) {
            $operationalHead = $this->insertChainRow(
                terminalId: $terminalId,
                chainContext: 'operational',
                sequenceNumber: $sequence,
                previousHash: $operationalHead,
                eventTimeDevice: $sequence === 2 ? $operationalHeadEventTimeDevice : null,
            );
        }

        $zSessionHead = $genesisSeed;
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $zSessionHead = $this->insertChainRow(
                terminalId: $terminalId,
                chainContext: 'z_session',
                sequenceNumber: $sequence,
                previousHash: $zSessionHead,
            );
        }

        // Prove the fixture produces the intended asymmetry, or a later "red"
        // could be red for the wrong reason (M0 toolkit rule).
        $this->assertSame(
            2,
            (int) DB::table('fiscal_events')
                ->where('terminal_id', $terminalId)
                ->where('chain_context', 'operational')
                ->max('sequence_number'),
        );
        $this->assertSame(
            5,
            (int) DB::table('fiscal_events')
                ->where('terminal_id', $terminalId)
                ->where('chain_context', 'z_session')
                ->max('sequence_number'),
        );

        return [$operationalHead, $zSessionHead];
    }

    /**
     * Insert one well-formed chain row and return its `current_hash`.
     */
    private function insertChainRow(
        string $terminalId,
        string $chainContext,
        int $sequenceNumber,
        string $previousHash,
        ?string $eventTimeDevice = null,
    ): string {
        $now = Carbon::now('UTC');
        $eventTimeDevice ??= $now->copy()->subHours(6)->format('Y-m-d\TH:i:s\Z');

        // A FULL canonical envelope, not a stand-in: M1's sealed-coordinate
        // check re-derives (event_type, sequence_number, previous_hash,
        // chain_context …) from `canonical_bytes` and reports a chain break on
        // any row it cannot read, so an abbreviated fixture would go red for a
        // reason that has nothing to do with ES-09.
        // Each context needs an event type that context ADMITS —
        // `StrictCanonicalParser` refuses an operational type on the z_session
        // chain and vice versa, and the verifier reports that refusal as a
        // chain break.
        $isZSession = $chainContext === 'z_session';
        $eventType = $isZSession
            ? FiscalEventType::SESSION_OPEN
            : FiscalEventType::CHAIN_BREAK_DETECTED;

        $envelope = [
            'business_date' => $now->copy()->startOfDay()->toDateString(),
            'chain_context' => $chainContext,
            'company_id' => $this->companyId,
            'event_time_device' => $eventTimeDevice,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $isZSession
                ? $this->sessionOpenPayload($terminalId, $sequenceNumber, $eventTimeDevice, $now)
                : [
                    'last_good_hash' => str_repeat('b', 64),
                    'last_good_sequence' => 0,
                    'offending_record_reference' => [
                        'observed_previous_hash' => $previousHash,
                        'sequence_number' => $sequenceNumber,
                        'terminal_id' => $terminalId,
                    ],
                    'reason' => sprintf('ES-09 %s fixture %d', $chainContext, $sequenceNumber),
                ],
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $terminalId,
        ];
        ksort($envelope);
        $canonicalBytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $currentHash = hash('sha256', $canonicalBytes);

        DB::table('fiscal_events')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTimeDevice,
            'business_date' => $now->copy()->startOfDay(),
            'chain_context' => $chainContext,
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
        ]);

        return $currentHash;
    }

    /**
     * A `SESSION_OPEN` payload in the shape the M0 two-context fixture already
     * proved parses — reused rather than reinvented, so the z_session arm of
     * this fixture is a real z_session chain the verifier can walk, not filler.
     *
     * @return array<string, mixed>
     */
    private function sessionOpenPayload(
        string $terminalId,
        int $sequenceNumber,
        string $eventTimeDevice,
        Carbon $now,
    ): array {
        return [
            'business_date' => $now->copy()->startOfDay()->toDateString(),
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'opened_at_device' => Carbon::parse($eventTimeDevice, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'opening_float_amount' => '100.000',
            'operator_id' => $this->operatorId,
            'operator_name' => 'ES-09 operator',
            'session_id' => sprintf('00000000-0000-4000-8000-0000000002%02d', $sequenceNumber),
            'shift_id' => sprintf('00000000-0000-4000-8000-0000000003%02d', $sequenceNumber),
            'shift_number' => $sequenceNumber,
            'terminal_id' => $terminalId,
            'terminal_label' => 'ES-09 fixture terminal',
            'training_flag' => false,
        ];
    }

    private function partner(): Partner
    {
        return Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);
    }

    /**
     * `canonical_bytes` is `bytea` on PG and comes back as a stream handle.
     */
    private function stringifyBytes(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return is_string($value) ? $value : (string) $value;
    }
}
