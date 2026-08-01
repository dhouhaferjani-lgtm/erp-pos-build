<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §1.1 — the acceptance test for the whole
 * feature. Reproduces the ORIGINAL failure mode's two `pos_terminals
 * .current_sequence` starting topologies (0 and 1) on a v3-from-birth
 * terminal to prove the new device-authored fiscal-events chain
 * (sale@v3 -> refund@v4 -> next sale@v3) is fully decoupled from that
 * now-vestigial legacy counter — unlike the pre-fix `ReceiptReturnService`
 * path §1 describes (CHECK 23514 / unique 23505 collisions), nothing in the
 * new path reads or writes `current_sequence` at all.
 *
 * Companions (§1.1, §9.4, D1):
 *   - negative-409: a legacy /return HTTP call against a terminal whose v4
 *     refund authoring has been ACKNOWLEDGED must be refused
 *     LEGACY_CORRECTION_RETIRED, not silently downgraded to a 422.
 *   - v2-non-regression: the legacy ReceiptReturnService path must still
 *     fully succeed, unaffected, on a v2/unacknowledged terminal — D1
 *     changed default terminal creation, so this fixture pins
 *     fiscal_schema_version explicitly rather than relying on any factory
 *     default.
 *
 * PG-mode only (`phpunit-pgsql.xml`) — exercises the real
 * `/api/v1/pos/sync/fiscal-events` ingestion pipeline, PG hash-chain CHECK
 * constraints, and `fiscal:verify-event-chain` / `pos:verify-chains`.
 *
 * **Z close leg (wave 3 — the leg wave 1 deferred).** The flow above now
 * continues into a full device-authored close: SESSION_OPEN ->
 * SESSION_CLOSE -> Z_REPORT on the parallel `z_session` chain, then the
 * next session's first sale back on `operational`. This is the launch-gate
 * evidence for owner gate E-7 (a refund must not break the fiscal chain
 * across a Z close), and it additionally pins the Z's own aggregate signs
 * (§7.3/§7.4: sale-only gross/net/tax, a positive-magnitude `refunds_*`
 * block, cash and VAT NET of the refund) plus the §7.7 hazard that a
 * `receipt_type`-blind `SUM(pos_receipts.total)` over the same window is
 * +2x the truth. See {@see self::runZCloseLeg()}.
 */
final class ReceiptReturnRefactorV3Test extends TestCase
{
    use RefreshDatabase;

    /**
     * The shift every SALE_RECEIPT payload in this file is authored under.
     * The Z leg's SESSION_OPEN projects it into a real `pos_shifts` row —
     * `pos_z_reports.shift_id` is FK-constrained to that table.
     */
    private const SHIFT_ID = '22222222-2222-4222-8222-222222222222';

    /** The shift the post-Z session re-opens on. */
    private const NEXT_SHIFT_ID = '22222222-2222-4222-8222-222222222223';

    private const Z_REPORT_UUID = '66666666-6666-4666-8666-666666666666';

    private const SESSION_CLOSE_UUID = '77777777-7777-4777-8777-777777777777';

    /** Opening float the Z leg's SESSION_OPEN declares. */
    private const OPENING_FLOAT = '100.00';

    /**
     * Opening float + NET cash for the window: 100.00 + (24.00 sold −
     * 12.00 refunded) = 112.00. Written out here rather than derived from
     * any figure the Z assertions read back.
     */
    private const EXPECTED_CASH = '112.00';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private User $chainVerifierActor;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        // review round-2 IMPORTANT 15 — PG driver guard consistent with
        // this file's §12/§6 siblings: the real `/api/v1/pos/sync/fiscal-events`
        // ingestion pipeline this acceptance test drives goes through the
        // SAME `PosCoreReceiptProjection::assertRefundQuantityWithinCap()`
        // `FOR UPDATE` lock those siblings gate on, and the fiscal-events
        // hash chain's CHECK constraints are PG-only.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('§1.1 acceptance flow exercises the PG-only §12 FOR UPDATE lock + CHECK constraints; run via phpunit-pgsql.xml.');
        }

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('0', 64);

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'V3 Acceptance Cashier',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->cashier->givePermissionTo('pos.process_returns');
        $this->cashier->givePermissionTo('pos.void_receipts');

        $this->chainVerifierActor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Chain Verifier',
        ]);
        $this->chainVerifierActor->givePermissionTo('fiscal.events.verify_chain');

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($this->company);
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
    }

    // =====================================================================
    // §1.1 core acceptance — both current_sequence topologies
    // =====================================================================

    public function test_v3_sale_v4_refund_next_v3_sale_chain_verifies_green_starting_at_current_sequence_zero(): void
    {
        $this->runV3RefundChainAcceptance(startingCurrentSequence: 0);
    }

    public function test_v3_sale_v4_refund_next_v3_sale_chain_verifies_green_starting_at_current_sequence_one(): void
    {
        $this->runV3RefundChainAcceptance(startingCurrentSequence: 1);
    }

    /**
     * review round-2 IMPORTANT 15 — exercise `approval_references` NON-
     * EMPTY on the real envelope-ingestion path at least once (every
     * other case in this file signs an empty array, which never reaches
     * §4.2's `assertApprovalEvidenceResolved()` resolution loop at all).
     * The two cited evidence events (OPERATOR_APPROVAL_GRANTED,
     * OVERRIDE_VOID_OR_RETURN) are authored via direct `fiscal_events`
     * inserts -- mirroring `ReceiptReturnFlowTest::storeFiscalEvent()`'s
     * own established precedent for this exact fixture shape -- since
     * they are AUDIT_ONLY events outside the SALE_RECEIPT chain this file
     * verifies, not part of the operational hash chain itself.
     */
    public function test_a_refund_citing_non_empty_approval_references_still_projects_and_verifies(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
            'fiscal_schema_version' => 3,
            'current_sequence' => 1,
        ]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id]);

        Sanctum::actingAs($this->cashier);

        $baseEventTime = Carbon::now('UTC')->subMinutes(10);
        $businessDate = $baseEventTime->toDateString();

        $saleReceiptUuid = '00000000-0000-4000-8000-000000000001';
        $saleEventId = Str::uuid()->toString();
        $saleEnvelope = $this->sealedEnvelope(
            eventId: $saleEventId,
            terminal: $terminal,
            eventVersion: 3,
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $this->v3SalePayload($saleReceiptUuid, $businessDate, $baseEventTime->copy()->format('Y-m-d\TH:i:s.000\Z'), $product->id),
        );
        $saleResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$saleEnvelope]]);
        $saleResponse->assertOk();
        $saleEvent = DB::table('fiscal_events')->where('id', $saleEventId)->first();
        self::assertNotNull($saleEvent);

        $approvalId = (string) Str::uuid();
        $approvalEventId = (string) Str::uuid();
        $overrideEventId = (string) Str::uuid();
        $supervisorId = (string) Str::uuid();
        $targetReferenceId = (string) Str::uuid();

        // Sequenced BETWEEN the sale (1) and the refund (claimed below as
        // 4) -- the real ingestion endpoint's own sequence-gap detection
        // rejects a LOWER sequence_number arriving after a HIGHER one
        // already exists for the terminal+chain_context, so these two
        // AUDIT_ONLY events (which default to the SAME
        // chain_context='operational' as the SALE_RECEIPT chain) must be
        // inserted BEFORE the refund envelope is posted, at sequence
        // numbers below it. Their own current_hash is NOT a real
        // canonical-bytes SHA-256 (mirrors
        // ReceiptReturnFlowTest::storeFiscalEvent()'s existing precedent,
        // which never needs to survive fiscal:verify-event-chain either),
        // so this test intentionally does not call that command -- it
        // proves the refund's OWN evidence-resolution path, not these two
        // fixture rows' chain integrity.
        $this->storeAuxiliaryFiscalEvent($terminal, $approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, 2, [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => 'Acceptance test approval evidence',
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $supervisorId,
            'supervisor_user_snapshot' => ['name' => 'Manager', 'roles' => ['manager']],
            'target' => ['target_reference_id' => $targetReferenceId],
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ], eventTime: $baseEventTime->copy()->addSeconds(30));
        $this->storeAuxiliaryFiscalEvent($terminal, $overrideEventId, FiscalEventType::OVERRIDE_VOID_OR_RETURN, 3, [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'SALE_RECEIPT',
                'target_reference_id' => $targetReferenceId,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => 'Acceptance test approval evidence',
            'supervisor_user_id' => $supervisorId,
            'target' => [],
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ], eventTime: $baseEventTime->copy()->addMinute());

        $refundReceiptUuid = '00000000-0000-4000-8000-000000000002';
        $refundEventId = Str::uuid()->toString();
        $refundEnvelope = $this->sealedEnvelope(
            eventId: $refundEventId,
            terminal: $terminal,
            eventVersion: 4,
            sequenceNumber: 4,
            // Must chain off the IMMEDIATELY PRIOR row's real current_hash
            // per OutboxIngestor::verifyLinkage() -- that is the OVERRIDE
            // auxiliary event at sequence=3, not the sale, since the
            // ingestion endpoint's linkage check compares against
            // sequence_number - 1 regardless of event_type.
            previousHash: hash('sha256', $overrideEventId),
            payload: $this->v4RefundPayload(
                $refundReceiptUuid,
                $businessDate,
                $baseEventTime->copy()->addMinutes(2)->format('Y-m-d\TH:i:s.000\Z'),
                $saleEventId,
                $saleReceiptUuid,
                $product->id,
                approvalReferences: [[
                    'approval_event_id' => $approvalEventId,
                    'approval_id' => $approvalId,
                    'approval_scope' => 'void_or_return_override',
                    'override_event_id' => $overrideEventId,
                    'policy_version' => 'pos-void-return-policy-v1',
                    'supervisor_user_id' => $supervisorId,
                    'target_reference_id' => $targetReferenceId,
                ]],
            ),
        );

        $refundResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$refundEnvelope]]);
        $refundResponse->assertOk();
        $refundResponse->assertJsonPath('results.0.stored', true);
        $refundResponse->assertJsonPath('results.0.exception_class', null);

        $refundReceipt = DB::table('pos_receipts')->where('fiscal_event_id', $refundEventId)->first();
        self::assertNotNull($refundReceipt, 'the non-empty-approval_references refund must still project cleanly');

        $refundProjectionRow = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $refundEventId)
            ->where('projector_name', 'pos_core_receipt')
            ->first();
        self::assertNotNull($refundProjectionRow);
        self::assertSame(
            'applied',
            $refundProjectionRow->projection_status,
            'assertApprovalEvidenceResolved() must have resolved both cited events and let the projection apply',
        );
    }

    private function runV3RefundChainAcceptance(int $startingCurrentSequence): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
            'fiscal_schema_version' => 3,
            'current_sequence' => $startingCurrentSequence,
        ]);

        // review round-2 IMPORTANT 15 — a REAL product UUID (not the F-16
        // golden fixture's non-UUID "prod-default" sentinel) so
        // resolveProductFk() actually resolves pos_receipt_lines.product_id
        // on both the sale and the refund -- required both for the §10
        // restock stock-movement side effect to execute AND for the
        // CRITICAL-1 original_line trust check
        // (resolveOriginalLineForReference()) to have a real, matching,
        // non-null product_id to compare against.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Sanctum::actingAs($this->cashier);

        // ---- 1. Device-authored SALE at event_version=3. ----
        // Uses "now" (not a fixed historical date) so business_date always
        // lands inside the company's currently-open fiscal period --
        // ChartOfAccountsService::seedForCompany does not open a period for
        // an arbitrary hardcoded past date.
        $saleReceiptUuid = '00000000-0000-4000-8000-000000000001';
        $saleEventId = Str::uuid()->toString();
        $baseEventTime = Carbon::now('UTC')->subMinutes(10);
        $businessDate = $baseEventTime->toDateString();
        $saleEventTimeDevice = $baseEventTime->copy()->format('Y-m-d\TH:i:s.000\Z');
        $saleEnvelope = $this->sealedEnvelope(
            eventId: $saleEventId,
            terminal: $terminal,
            eventVersion: 3,
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $this->v3SalePayload($saleReceiptUuid, $businessDate, $saleEventTimeDevice, $product->id),
        );

        $saleResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$saleEnvelope]]);
        $saleResponse->assertOk();
        $saleResponse->assertJsonPath('results.0.stored', true);
        $saleResponse->assertJsonPath('results.0.exception_class', null);

        $saleEvent = DB::table('fiscal_events')->where('id', $saleEventId)->first();
        self::assertNotNull($saleEvent);
        self::assertSame($this->genesisSeed, $saleEvent->previous_hash);
        $saleCurrentHash = (string) $saleEvent->current_hash;

        // ---- 2. Device-authored REFUND at event_version=4, chained off
        // ---- the sale, citing it via original_receipt_reference. ----
        $refundReceiptUuid = '00000000-0000-4000-8000-000000000002';
        $refundEventId = Str::uuid()->toString();
        $refundEventTimeDevice = $baseEventTime->copy()->addMinutes(2)->format('Y-m-d\TH:i:s.000\Z');
        $refundEnvelope = $this->sealedEnvelope(
            eventId: $refundEventId,
            terminal: $terminal,
            eventVersion: 4,
            sequenceNumber: 2,
            previousHash: $saleCurrentHash,
            payload: $this->v4RefundPayload($refundReceiptUuid, $businessDate, $refundEventTimeDevice, $saleEventId, $saleReceiptUuid, $product->id),
        );

        $refundResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$refundEnvelope]]);
        $refundResponse->assertOk();
        $refundResponse->assertJsonPath('results.0.stored', true);
        $refundResponse->assertJsonPath('results.0.exception_class', null);

        $refundEvent = DB::table('fiscal_events')->where('id', $refundEventId)->first();
        self::assertNotNull($refundEvent);
        self::assertSame($saleCurrentHash, $refundEvent->previous_hash, 'refund must chain off the sale');
        $refundCurrentHash = (string) $refundEvent->current_hash;

        // review round-2 IMPORTANT 15 — projection assertions: the refund
        // must actually PROJECT (not merely chain-hash correctly) --
        // pos_receipts row, receipt_type=return,
        // original_receipt_id/original_line_id linkage back to the sale,
        // and the pos_core_receipt projector's own applied status.
        $saleReceipt = DB::table('pos_receipts')->where('fiscal_event_id', $saleEventId)->first();
        self::assertNotNull($saleReceipt, 'the sale must have projected a pos_receipts row');
        $saleLine = DB::table('pos_receipt_lines')->where('receipt_id', $saleReceipt->id)->first();
        self::assertNotNull($saleLine);

        $refundReceipt = DB::table('pos_receipts')->where('fiscal_event_id', $refundEventId)->first();
        self::assertNotNull($refundReceipt, 'the refund must have projected a pos_receipts row');
        self::assertSame('return', $refundReceipt->receipt_type);
        self::assertSame($saleReceipt->id, $refundReceipt->original_receipt_id);

        $refundLine = DB::table('pos_receipt_lines')->where('receipt_id', $refundReceipt->id)->first();
        self::assertNotNull($refundLine);
        self::assertSame(
            $saleLine->id,
            $refundLine->original_line_id,
            'the refund line must link back to the ORIGINAL sale line (CRITICAL-1 trust-hole closure)',
        );

        $refundProjectionRow = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $refundEventId)
            ->where('projector_name', 'pos_core_receipt')
            ->first();
        self::assertNotNull($refundProjectionRow, 'the refund must have a fiscal_event_projections row for pos_core_receipt');
        self::assertSame('applied', $refundProjectionRow->projection_status);

        // ---- 3. Device-authored NEXT SALE at event_version=3, chained off
        // ---- the refund -- the "next-sale-chains-off-refund" assertion. ----
        $nextSaleReceiptUuid = '00000000-0000-4000-8000-000000000003';
        $nextSaleEventId = Str::uuid()->toString();
        $nextSaleEventTimeDevice = $baseEventTime->copy()->addMinutes(4)->format('Y-m-d\TH:i:s.000\Z');
        $nextSaleEnvelope = $this->sealedEnvelope(
            eventId: $nextSaleEventId,
            terminal: $terminal,
            eventVersion: 3,
            sequenceNumber: 3,
            previousHash: $refundCurrentHash,
            payload: $this->v3SalePayload($nextSaleReceiptUuid, $businessDate, $nextSaleEventTimeDevice, $product->id),
        );

        $nextSaleResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$nextSaleEnvelope]]);
        $nextSaleResponse->assertOk();
        $nextSaleResponse->assertJsonPath('results.0.stored', true);
        $nextSaleResponse->assertJsonPath('results.0.exception_class', null);

        $nextSaleEvent = DB::table('fiscal_events')->where('id', $nextSaleEventId)->first();
        self::assertNotNull($nextSaleEvent);
        self::assertSame($refundCurrentHash, $nextSaleEvent->previous_hash, 'next sale must chain off the refund, not skip around it');

        // ---- 4. `pos_terminals.current_sequence` was never touched by any
        // ---- of the three fiscal-events envelopes -- proving the new path's
        // ---- independence from the legacy counter regardless of its
        // ---- starting topology. ----
        $terminal->refresh();
        self::assertSame($startingCurrentSequence, $terminal->current_sequence);

        // ---- 5. Both verify commands green. ----
        // review round-2 IMPORTANT 15 — non-vacuous verifier assertion:
        // asserting the fiscal-arm EVENT COUNT ("3 events walked") proves
        // the command actually walked all three authored events rather
        // than trivially passing on an empty/zero-row chain.
        // `pos:verify-chains` is asserted separately below purely for
        // exit-code green -- it structurally EXCLUDES every
        // fiscal_event_id-backed pos_receipts row (`whereNull('fiscal_event_id')`,
        // proven by PosCoreReceiptProjectionTest's own
        // "pos verify chains command does not break on projection rows"
        // regression test), so a non-vacuous row-count assertion against
        // it is not meaningful for a v3/v4-authored chain -- its green
        // exit code here is a "does not break" check, not a "verified N
        // rows" check.
        $this->artisanCommand('fiscal:verify-event-chain', [
            '--tenant' => $this->tenant->id,
            '--terminal' => $terminal->id,
            '--actor-id' => $this->chainVerifierActor->id,
        ])
            ->expectsOutputToContain('chain verified — terminal '.$terminal->id.', tenant '.$this->tenant->id.', context operational, 3 events walked from sequence 1, no quarantine incidents.')
            ->assertExitCode(0);

        $this->artisanCommand('pos:verify-chains', [
            '--terminal' => $terminal->id,
        ])
            ->assertExitCode(0);

        // ---- 6. Z CLOSE leg (wave 3 — the §1.1 leg wave 1 deferred). ----
        $this->runZCloseLeg(
            terminal: $terminal,
            businessDate: $businessDate,
            baseEventTime: $baseEventTime,
            lastOperationalHash: (string) $nextSaleEvent->current_hash,
            productId: $product->id,
            startingCurrentSequence: $startingCurrentSequence,
        );
    }

    // =====================================================================
    // Z CLOSE leg (wave 3) — §1.1's deferred close: the refund must survive
    // a Z close AND the next session's first sale must keep chaining.
    // =====================================================================

    /**
     * Drive the device-authored Z close over the sale → refund → sale window
     * this file just built, then re-open and sell again.
     *
     * **Why the Z is a z_session chain, not more operational events.** A v3+
     * terminal's Z is device-authored only — `ReportGenerationService`
     * refuses server-side Z authoring at `fiscal_schema_version >= 3`
     * (`Z_SESSION_DEVICE_AUTHORITY_REQUIRED`, pinned by
     * `ZReportServerAuthoringChokepointTest`) — and the device authors the
     * session lifecycle on the SEPARATE `z_session` chain context, which
     * carries its own monotonic `sequence_number` stream
     * (`OutboxIngestor::verifyZSessionLifecycle()`, and
     * `OutboxIngestorTest::test_chain_context_allows_parallel_sequence_streams_for_same_terminal`).
     * So the close is SESSION_OPEN → SESSION_CLOSE → Z_REPORT on `z_session`,
     * strictly parallel to the SALE_RECEIPT chain on `operational`, and the
     * two are verified independently by `fiscal:verify-event-chain
     * --chain-context=…`.
     *
     * **Sign convention under test (§7.4 / §3.1 / §7.7).** The refund's
     * SIGNED payload carries positive magnitudes with direction in
     * `invoice_type_code = 'REFUND'`, and `PosCoreReceiptProjection` stores
     * that positive `total` on a `receipt_type = 'return'` row. The Z's own
     * aggregates must therefore NET the refund explicitly (sale-only
     * gross/net/tax, its own `refunds_*` block, and a payment-method
     * breakdown the device already subtracted the refund from, §7.3) rather
     * than relying on a negative stored total to net itself — a
     * `SUM(pos_receipts.total)` with no `receipt_type` filter over this same
     * window is +2× the truth, which the last assertion block pins
     * explicitly so the hazard is evidence rather than folklore.
     */
    private function runZCloseLeg(
        Terminal $terminal,
        string $businessDate,
        Carbon $baseEventTime,
        string $lastOperationalHash,
        string $productId,
        int $startingCurrentSequence,
    ): void {
        // The Z window must span exactly the three operational events
        // authored above (base, +2m, +4m) — `ZReportProjection`'s
        // completeness gate refuses to project until EVERY verified v3+
        // SALE_RECEIPT inside it has a `pos_receipts` row, so a green Z here
        // is itself proof that the v4 refund projected.
        $periodStart = $baseEventTime->copy()->subMinute();
        $periodEnd = $baseEventTime->copy()->addMinutes(5);

        $sessionId = '44444444-4444-4444-8444-444444444441';
        $sessionCloseEventId = Str::uuid()->toString();
        $zEventId = Str::uuid()->toString();

        // ---- 6a. SESSION_OPEN (z_session sequence 1, off the genesis seed).
        // `ZSessionLifecycleProjection` projects this into the `pos_shifts`
        // row that `pos_z_reports.shift_id` is FK-constrained to, so the Z
        // below has a real shift to land on.
        $sessionOpenEventId = Str::uuid()->toString();
        $sessionOpenEnvelope = $this->sealedEnvelope(
            eventId: $sessionOpenEventId,
            terminal: $terminal,
            eventVersion: 1,
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $this->sessionOpenPayload(
                $terminal,
                $sessionId,
                self::SHIFT_ID,
                shiftNumber: 1,
                businessDate: $businessDate,
                openedAtDevice: $baseEventTime->copy()->subMinutes(2)->format('Y-m-d\TH:i:s.000\Z'),
            ),
            eventType: FiscalEventType::SESSION_OPEN,
            chainContext: 'z_session',
            eventTimeDevice: $baseEventTime->copy()->subMinutes(2)->format('Y-m-d\TH:i:s.000\Z'),
            // §z-session lifecycle: SESSION_OPEN must be sourced from
            // `pos_session` with `source_event_id` equal to the payload's own
            // `session_id`, or the ingestor quarantines it.
            sourceEventClass: 'pos_session',
            sourceEventId: $sessionId,
        );
        $sessionOpenResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$sessionOpenEnvelope]]);
        $sessionOpenResponse->assertOk();
        $sessionOpenResponse->assertJsonPath('results.0.stored', true);
        $sessionOpenResponse->assertJsonPath('results.0.exception_class', null);

        $sessionOpenEvent = DB::table('fiscal_events')->where('id', $sessionOpenEventId)->first();
        self::assertNotNull($sessionOpenEvent);
        self::assertSame('verified', $sessionOpenEvent->integrity_status);
        self::assertDatabaseHas('pos_shifts', ['id' => self::SHIFT_ID, 'terminal_id' => $terminal->id]);

        // ---- 6b. SESSION_CLOSE (z_session sequence 2). ----
        $sessionCloseEnvelope = $this->sealedEnvelope(
            eventId: $sessionCloseEventId,
            terminal: $terminal,
            eventVersion: 1,
            sequenceNumber: 2,
            previousHash: (string) $sessionOpenEvent->current_hash,
            payload: $this->sessionClosePayload(
                $terminal,
                $sessionId,
                self::SHIFT_ID,
                businessDate: $businessDate,
                generatedAtDevice: $baseEventTime->copy()->addMinutes(6)->format('Y-m-d\TH:i:s.000\Z'),
                periodStart: $periodStart->copy()->format('Y-m-d\TH:i:s.000\Z'),
                periodEnd: $periodEnd->copy()->format('Y-m-d\TH:i:s.000\Z'),
            ),
            eventType: FiscalEventType::SESSION_CLOSE,
            chainContext: 'z_session',
            eventTimeDevice: $baseEventTime->copy()->addMinutes(6)->format('Y-m-d\TH:i:s.000\Z'),
            // The device's own source pair for a close
            // (`zSessionAuthoring.ts:685-686`) — NOT `pos_session`/session_id,
            // which the SESSION_OPEN already claimed under the
            // `fiscal_events_source_event_unique` index.
            sourceEventClass: 'pos_session_close',
            sourceEventId: self::SESSION_CLOSE_UUID,
        );
        $sessionCloseResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$sessionCloseEnvelope]]);
        $sessionCloseResponse->assertOk();
        $sessionCloseResponse->assertJsonPath('results.0.stored', true);
        $sessionCloseResponse->assertJsonPath('results.0.exception_class', null);

        $sessionCloseEvent = DB::table('fiscal_events')->where('id', $sessionCloseEventId)->first();
        self::assertNotNull($sessionCloseEvent);
        self::assertSame('verified', $sessionCloseEvent->integrity_status);

        // ---- 6c. Z_REPORT (z_session sequence 3) — the close itself. ----
        $zEnvelope = $this->sealedEnvelope(
            eventId: $zEventId,
            terminal: $terminal,
            eventVersion: 1,
            sequenceNumber: 3,
            previousHash: (string) $sessionCloseEvent->current_hash,
            payload: $this->zReportPayload(
                $terminal,
                $sessionId,
                self::SHIFT_ID,
                $sessionCloseEventId,
                businessDate: $businessDate,
                closedAtDevice: $baseEventTime->copy()->addMinutes(7)->format('Y-m-d\TH:i:s.000\Z'),
                periodStart: $periodStart->copy()->format('Y-m-d\TH:i:s.000\Z'),
                periodEnd: $periodEnd->copy()->format('Y-m-d\TH:i:s.000\Z'),
            ),
            eventType: FiscalEventType::Z_REPORT,
            chainContext: 'z_session',
            eventTimeDevice: $baseEventTime->copy()->addMinutes(7)->format('Y-m-d\TH:i:s.000\Z'),
            sourceEventClass: 'z_report',
            sourceEventId: self::Z_REPORT_UUID,
        );
        $zResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$zEnvelope]]);
        $zResponse->assertOk();
        $zResponse->assertJsonPath('results.0.stored', true);
        $zResponse->assertJsonPath('results.0.exception_class', null);

        $zEvent = DB::table('fiscal_events')->where('id', $zEventId)->first();
        self::assertNotNull($zEvent);
        self::assertSame('verified', $zEvent->integrity_status);
        self::assertSame((string) $sessionCloseEvent->current_hash, $zEvent->previous_hash, 'the Z must chain off the session close');

        $zProjectionRow = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $zEventId)
            ->where('projector_name', 'pos_core_z_report')
            ->first();
        self::assertNotNull($zProjectionRow, 'the Z must have a fiscal_event_projections row for pos_core_z_report');
        self::assertSame(
            'applied',
            $zProjectionRow->projection_status,
            'a pending/failed Z here means ZReportProjection\'s completeness gate did not see the refund projected',
        );

        // ---- 7. Z aggregates reflect the refund with the spec's signs. ----
        $zReport = ZReport::query()->where('fiscal_event_id', $zEventId)->firstOrFail();
        /** @var array<string, mixed> $reportData */
        $reportData = $zReport->report_data;

        // Sale-only aggregates (§7.3): the refund contributes to NONE of
        // these — two sales at 12.00 TTC / 10.00 net / 2.00 VAT each.
        self::assertSame(2, $reportData['sales_count']);
        self::assertSame('24.00', $reportData['gross_sales']);
        self::assertSame('20.00', $reportData['net_sales']);
        self::assertSame('4.00', $reportData['tax_amount']);

        // Refunds tracked as their own POSITIVE-MAGNITUDE block (§3.1/§7.3):
        // never folded into gross/net sales, never sign-bearing.
        self::assertSame(1, $reportData['refunds_count']);
        self::assertSame('12.00', $reportData['refunds_amount']);
        self::assertSame(0, $reportData['voided_count']);

        // The payment-method breakdown is NET of the refund (§7.3's
        // subtraction branch): 24.00 cash in minus 12.00 paid out = 12.00.
        // If a consumer ever added instead of subtracting, this reads 36.00.
        /** @var list<array<string, mixed>> $paymentMethods */
        $paymentMethods = $reportData['payment_methods'];
        self::assertCount(1, $paymentMethods);
        self::assertSame('CASH', $paymentMethods[0]['payment_type']);
        self::assertSame('12.00', $paymentMethods[0]['total_amount'], 'Z cash must be net of the refund payout, not gross');

        // expected_cash = opening float 100.00 + net cash 12.00 (§7.3's
        // formula simplification — one netted cash figure, not a gross
        // figure with a separate refund-impact subtraction).
        self::assertSame('112.00', $reportData['expected_cash']);
        self::assertSame('112.00', $reportData['actual_cash']);
        self::assertSame('0.00', $reportData['variance']);

        // VAT buckets, cross-checked against an INDEPENDENT source.
        //
        // The expected figures below are NOT derived from the Z payload's
        // own aggregate fields — they are recomputed from
        // `pos_receipt_vat_details`, the per-receipt rows
        // `PosCoreReceiptProjection::writeVatBreakdown()` mirrors straight
        // out of each event's OWN signed `vat_breakdown[]`, split by
        // `receipt_type` so the refund is subtracted rather than blended.
        // (Wave-2 lesson: a fixture whose expected values come from the same
        // field the aggregator reads cannot catch a VAT aggregation bug.)
        /** @var list<array<string, mixed>> $zVatBreakdown */
        $zVatBreakdown = $reportData['vat_breakdown'];
        self::assertCount(1, $zVatBreakdown);

        $vatRows = DB::table('pos_receipt_vat_details')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_vat_details.receipt_id')
            ->where('pos_receipts.terminal_id', $terminal->id)
            ->whereBetween('pos_receipts.posted_at', [
                $periodStart->copy()->format('Y-m-d H:i:s'),
                $periodEnd->copy()->format('Y-m-d H:i:s'),
            ]);

        $independentNet = bcsub(
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'sale')->sum('pos_receipt_vat_details.net_amount'),
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'return')->sum('pos_receipt_vat_details.net_amount'),
            3,
        );
        $independentVat = bcsub(
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'sale')->sum('pos_receipt_vat_details.vat_amount'),
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'return')->sum('pos_receipt_vat_details.vat_amount'),
            3,
        );
        $independentGross = bcsub(
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'sale')->sum('pos_receipt_vat_details.gross_amount'),
            (string) $vatRows->clone()->where('pos_receipts.receipt_type', 'return')->sum('pos_receipt_vat_details.gross_amount'),
            3,
        );

        // Absolute pins first (2 sales − 1 refund, each 10.00 net / 2.00 VAT
        // / 12.00 gross at 20%), so a change on BOTH sides can't cancel out.
        self::assertSame(0, bccomp($independentNet, '10.000', 3));
        self::assertSame(0, bccomp($independentVat, '2.000', 3));
        self::assertSame(0, bccomp($independentGross, '12.000', 3));
        // …and the arithmetic identity itself: gross == net + VAT, and VAT is
        // the stated 20% of net. Neither is read off the Z payload.
        self::assertSame(0, bccomp($independentGross, bcadd($independentNet, $independentVat, 3), 3));
        self::assertSame(0, bccomp($independentVat, bcdiv(bcmul($independentNet, '20', 5), '100', 5), 3));

        self::assertSame(20, $zVatBreakdown[0]['tax_rate']);
        self::assertSame(
            0,
            bccomp($this->numericString($zVatBreakdown[0]['net_amount']), $independentNet, 3),
            'the Z VAT net must equal the receipt_type-aware per-receipt net, i.e. the refund was SUBTRACTED',
        );
        self::assertSame(0, bccomp($this->numericString($zVatBreakdown[0]['vat_amount']), $independentVat, 3));
        self::assertSame(0, bccomp($this->numericString($zVatBreakdown[0]['gross_amount']), $independentGross, 3));
        // The Z's own sale-only `tax_amount` is the GROSS-of-refunds figure —
        // stated separately so the two are never conflated: 4.00 collected on
        // two sales vs 2.00 net of the refund reversal.
        self::assertSame(
            0,
            bccomp($this->numericString($reportData['tax_amount']), bcadd($independentVat, '2.000', 3), 3),
            'sale-only tax_amount stays gross of the refund; only the VAT breakdown nets it',
        );

        // Server-DERIVED aggregate (not device-authored): the rounding
        // summary sums `pos_receipts.cash_rounding_adjustment` across the
        // window INCLUDING the refund row. Every receipt here is unrounded,
        // so it must be canonical zero with a zero count — a non-zero count
        // would mean the refund row leaked a phantom adjustment.
        /** @var array<string, mixed> $roundingSummary */
        $roundingSummary = $reportData['cash_rounding_summary'];
        self::assertSame('0.000', $roundingSummary['total_adjustment']);
        self::assertSame(0, $roundingSummary['receipt_count']);

        // ---- 8. None of the SUM aggregates go silently wrong. ----
        // §7.7: a v4 refund projects a POSITIVE `total` under
        // `receipt_type = 'return'` (legacy returns stored it negative), so
        // any consumer that blends `SUM(total)` without a `receipt_type`
        // filter now ADDS the refund. Pinned explicitly, both arms, so the
        // Z's own netted figure is provably the receipt_type-AWARE one.
        $windowReceipts = DB::table('pos_receipts')
            ->where('terminal_id', $terminal->id)
            ->whereBetween('posted_at', [
                $periodStart->copy()->format('Y-m-d H:i:s'),
                $periodEnd->copy()->format('Y-m-d H:i:s'),
            ]);

        $blendedSum = (string) ($windowReceipts->clone()->sum('total'));
        $saleSum = (string) ($windowReceipts->clone()->where('receipt_type', 'sale')->sum('total'));
        $returnSum = (string) ($windowReceipts->clone()->where('receipt_type', 'return')->sum('total'));

        self::assertSame(0, bccomp($returnSum, '12.000', 3), 'the v4 refund projects a POSITIVE total (§7.7)');
        self::assertSame(0, bccomp($saleSum, '24.000', 3));
        self::assertSame(
            0,
            bccomp($blendedSum, '36.000', 3),
            'an unfiltered SUM(pos_receipts.total) over this window is +2x the truth — receipt_type-blind consumers must be gated before v4 authoring is enabled',
        );
        self::assertSame(
            0,
            bccomp(bcsub($saleSum, $returnSum, 3), '12.000', 3),
            'the receipt_type-AWARE net is what the Z reports as cash',
        );
        self::assertSame(
            0,
            bccomp($this->numericString($paymentMethods[0]['total_amount']), bcsub($saleSum, $returnSum, 3), 3),
            'the Z cash figure must equal the receipt_type-aware server-side net, NOT the blended sum',
        );

        // ---- 9. After the Z close, the next session's first sale keeps
        // ---- chaining off the pre-Z operational head. ----
        $nextSessionId = '44444444-4444-4444-8444-444444444442';
        $reopenEventId = Str::uuid()->toString();
        $reopenEnvelope = $this->sealedEnvelope(
            eventId: $reopenEventId,
            terminal: $terminal,
            eventVersion: 1,
            sequenceNumber: 4,
            previousHash: (string) $zEvent->current_hash,
            payload: $this->sessionOpenPayload(
                $terminal,
                $nextSessionId,
                self::NEXT_SHIFT_ID,
                shiftNumber: 2,
                businessDate: $businessDate,
                openedAtDevice: $baseEventTime->copy()->addMinutes(8)->format('Y-m-d\TH:i:s.000\Z'),
            ),
            eventType: FiscalEventType::SESSION_OPEN,
            chainContext: 'z_session',
            eventTimeDevice: $baseEventTime->copy()->addMinutes(8)->format('Y-m-d\TH:i:s.000\Z'),
            sourceEventClass: 'pos_session',
            sourceEventId: $nextSessionId,
        );
        $reopenResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$reopenEnvelope]]);
        $reopenResponse->assertOk();
        $reopenResponse->assertJsonPath('results.0.stored', true);
        $reopenResponse->assertJsonPath('results.0.exception_class', null);

        $postZSaleUuid = '00000000-0000-4000-8000-000000000004';
        $postZSaleEventId = Str::uuid()->toString();
        $postZSaleEnvelope = $this->sealedEnvelope(
            eventId: $postZSaleEventId,
            terminal: $terminal,
            eventVersion: 3,
            // The operational chain is untouched by the Z — the post-Z sale
            // is sequence 4 there, chaining off the PRE-Z sale's hash, with
            // no restart, reseed or gap.
            sequenceNumber: 4,
            previousHash: $lastOperationalHash,
            payload: $this->v3SalePayload(
                $postZSaleUuid,
                $businessDate,
                $baseEventTime->copy()->addMinutes(9)->format('Y-m-d\TH:i:s.000\Z'),
                $productId,
            ),
        );
        $postZSaleResponse = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$postZSaleEnvelope]]);
        $postZSaleResponse->assertOk();
        $postZSaleResponse->assertJsonPath('results.0.stored', true);
        $postZSaleResponse->assertJsonPath('results.0.exception_class', null);

        $postZSaleEvent = DB::table('fiscal_events')->where('id', $postZSaleEventId)->first();
        self::assertNotNull($postZSaleEvent);
        self::assertSame(
            $lastOperationalHash,
            $postZSaleEvent->previous_hash,
            'the first sale after the Z close must continue the operational chain, not restart it',
        );
        self::assertNotNull(
            DB::table('pos_receipts')->where('fiscal_event_id', $postZSaleEventId)->first(),
            'the post-Z sale must still project',
        );

        // The Z is immutable and did NOT absorb the post-Z sale.
        $zReport->refresh();
        /** @var array<string, mixed> $reportDataAfter */
        $reportDataAfter = $zReport->report_data;
        self::assertSame(2, $reportDataAfter['sales_count']);
        self::assertSame('24.00', $reportDataAfter['gross_sales']);
        self::assertSame('12.00', $reportDataAfter['refunds_amount']);

        // ---- 10. `current_sequence` STILL never touched — the Z leg is as
        // ---- decoupled from the legacy counter as the refund leg. ----
        $terminal->refresh();
        self::assertSame($startingCurrentSequence, $terminal->current_sequence);

        // ---- 11. Both verify commands still green, both chain contexts. ----
        $this->artisanCommand('fiscal:verify-event-chain', [
            '--tenant' => $this->tenant->id,
            '--terminal' => $terminal->id,
            '--actor-id' => $this->chainVerifierActor->id,
        ])
            ->expectsOutputToContain('chain verified — terminal '.$terminal->id.', tenant '.$this->tenant->id.', context operational, 4 events walked from sequence 1, no quarantine incidents.')
            ->assertExitCode(0);

        $this->artisanCommand('fiscal:verify-event-chain', [
            '--tenant' => $this->tenant->id,
            '--terminal' => $terminal->id,
            '--chain-context' => 'z_session',
            '--actor-id' => $this->chainVerifierActor->id,
        ])
            ->expectsOutputToContain('chain verified — terminal '.$terminal->id.', tenant '.$this->tenant->id.', context z_session, 4 events walked from sequence 1, no quarantine incidents.')
            ->assertExitCode(0);

        // `pos:verify-chains` now also exercises its Z-report arm: the
        // projected `pos_z_reports` row is excluded from the legacy
        // pipe-string recomputation (`fiscal_event_id IS NOT NULL`) and
        // verified instead by `ZReportHashService::verifyFiscalEventsArm()`,
        // which walks the whole `z_session` chain off the genesis seed.
        $this->artisanCommand('pos:verify-chains', [
            '--terminal' => $terminal->id,
        ])
            ->assertExitCode(0);
    }

    // =====================================================================
    // Negative-409 companion (§9.4/§17: ReceiptController must map
    // LegacyCorrectionRetiredException to HTTP 409 LEGACY_CORRECTION_RETIRED
    // on BOTH void and return -- not just void).
    // =====================================================================

    public function test_legacy_return_http_endpoint_returns_409_on_a_v4_acknowledged_terminal(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
            'fiscal_schema_version' => 3,
            'current_sequence' => 1,
            'v4_refund_authoring_enabled' => true,
            'v4_refund_authoring_acknowledged_at' => now(),
        ]);

        $saleReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => 'sale',
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-409',
            'product_name' => 'Widget 409',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);

        Sanctum::actingAs($this->cashier);

        $requestData = [
            'terminal_id' => $terminal->id,
            'return_reason' => ReturnReason::CustomerChangedMind->value,
            'lines' => [['line_id' => $line->id, 'quantity' => '1']],
            'notes' => 'Acceptance-test negative case',
        ];

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, $requestData),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'LEGACY_CORRECTION_RETIRED');

        // No return receipt was created -- the guard fired before any
        // persistence.
        self::assertDatabaseMissing('pos_receipts', [
            'original_receipt_id' => $saleReceipt->id,
            'receipt_type' => 'return',
        ]);
    }

    /**
     * review round-2 MINOR — the VOID endpoint's own 409 companion.
     * `ReceiptController::void()` has no local `catch (\RuntimeException)`
     * (unlike `processReturn()`, which had the bug this file's RETURN 409
     * companion caught and this session fixed), so it was already
     * expected to correctly reach the global 409 handler -- this proves
     * it end-to-end rather than leaving it as an untested assumption.
     */
    public function test_legacy_void_http_endpoint_returns_409_on_a_v4_acknowledged_terminal(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
            'fiscal_schema_version' => 3,
            'current_sequence' => 1,
            'v4_refund_authoring_enabled' => true,
            'v4_refund_authoring_acknowledged_at' => now(),
        ]);

        $saleReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => 'sale',
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
        ]);

        Sanctum::actingAs($this->cashier);

        $reason = 'Acceptance-test void negative case';
        $target = [
            'receipt_id' => $saleReceipt->id,
            'receipt_number' => $saleReceipt->receipt_number,
            'reason' => $reason,
        ];

        $approvalId = (string) Str::uuid();
        $approvalEventId = (string) Str::uuid();
        $overrideEventId = (string) Str::uuid();

        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->cashier->id,
            'supervisor_user_snapshot' => ['name' => $this->cashier->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ], $terminal->id);

        $this->storeFiscalEvent($overrideEventId, FiscalEventType::OVERRIDE_VOID_OR_RETURN, [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'POS_RECEIPT_VOID',
                'target_reference_id' => $saleReceipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason,
            'supervisor_user_id' => $this->cashier->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ], $terminal->id, $approvalEventId);

        $response = $this->postJson("/api/v1/pos/receipts/{$saleReceipt->id}/void", [
            'reason' => $reason,
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->cashier->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->cashier->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'LEGACY_CORRECTION_RETIRED');

        self::assertDatabaseHas('pos_receipts', [
            'id' => $saleReceipt->id,
            'is_voided' => false,
        ]);
    }

    // =====================================================================
    // v2-non-regression companion (D1: default terminal creation changed,
    // so a v2 fixture must pin fiscal_schema_version explicitly, never rely
    // on any factory default).
    // =====================================================================

    public function test_v2_terminal_legacy_return_flow_is_unaffected(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'current_sequence' => 1,
            // v4_refund_authoring_acknowledged_at stays null (default) --
            // LegacyCorrectionGuard is gated on acknowledgement, not raw
            // schema version, so this v2 terminal proceeds normally.
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $saleReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => 'sale',
            'subtotal' => '30.000',
            'tax_amount' => '0.000',
            'total' => '30.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-V2',
            'product_name' => 'Widget V2',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        /** @var ReceiptReturnService $service */
        $service = $this->app->make(ReceiptReturnService::class);

        $returnReceipt = $service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '1']],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $terminal->id,
            notes: null,
        );

        self::assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $returnReceipt->fiscal_hash);
        self::assertGreaterThan(0, $returnReceipt->chain_sequence);

        $terminal->refresh();
        $refreshedShift = Shift::query()->findOrFail($shift->id);
        self::assertSame($terminal->id, $refreshedShift->terminal_id, 'the legacy shift/terminal linkage is unaffected by the v4 guard');
        self::assertSame(2, $terminal->fiscal_schema_version);
    }

    // =====================================================================
    // Command-runner helper (typed PendingCommand narrowing -- Laravel's
    // TestCase::artisan() return type is inferred as PendingCommand|int by
    // Larastan, so a raw fluent chain off it is ambiguous at level 8).
    // =====================================================================

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $result = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $result);

        return $result;
    }

    // =====================================================================
    // Envelope / payload helpers
    // =====================================================================

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sealedEnvelope(
        string $eventId,
        Terminal $terminal,
        int $eventVersion,
        int $sequenceNumber,
        string $previousHash,
        array $payload,
        FiscalEventType $eventType = FiscalEventType::SALE_RECEIPT,
        string $chainContext = 'operational',
        ?string $eventTimeDevice = null,
        ?string $sourceEventClass = null,
        ?string $sourceEventId = null,
    ): array {
        // A SALE_RECEIPT payload carries its own millisecond-precision
        // `event_time_device`. The z_session family names its device clock
        // differently per type (`opened_at_device` / `generated_at_device` /
        // `closed_at_device`) and has no `event_time_device` key at all — its
        // key set is exact-matched by `FiscalPayloadConstraintValidator`, so
        // those call sites pass the envelope's device time explicitly instead.
        $payloadEventTimeDevice = $eventTimeDevice ?? $payload['event_time_device'];
        self::assertIsString($payloadEventTimeDevice);
        $businessDate = $payload['business_date'];
        self::assertIsString($businessDate);

        // Envelope-level `event_time_device` is shape-validated to
        // ISO-8601 UTC SECONDS precision (`MALFORMED_ENVELOPE` on a
        // millisecond-precision string) -- a STRICTER format than the
        // payload's OWN `event_time_device` field, which carries
        // milliseconds. Derive the envelope-level value from the
        // payload's by dropping the fractional-seconds component.
        $envelopeEventTimeDevice = Carbon::parse($payloadEventTimeDevice, 'UTC')
            ->format('Y-m-d\TH:i:s\Z');

        // The payload's own `terminal_id` field (validated by
        // FiscalPayloadConstraintValidator) must match the envelope's
        // terminal -- the builder helpers below leave it blank as a
        // placeholder for this injection point.
        $payload['terminal_id'] = $terminal->id;

        $base = [
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => $eventType->value,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $envelopeEventTimeDevice,
            'business_date' => $businessDate,
            'chain_context' => $chainContext,
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => $sourceEventClass,
            'source_event_id' => $sourceEventId,
            'previous_hash' => $previousHash,
        ];

        $canonicalArray = [
            'business_date' => $base['business_date'],
            'chain_context' => $base['chain_context'],
            'company_id' => $base['company_id'],
            'event_time_device' => $base['event_time_device'],
            'event_type' => $base['event_type'],
            'event_version' => $base['event_version'],
            'operator_id' => $base['operator_id'],
            'payload' => $payload,
            'previous_hash' => $base['previous_hash'],
            'reference_document_id' => $base['reference_document_id'],
            'reference_event_id' => $base['reference_event_id'],
            'sequence_number' => $base['sequence_number'],
            'signature_version' => $base['signature_version'],
            'tenant_id' => $base['tenant_id'],
            'terminal_id' => $base['terminal_id'],
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $base['canonical_bytes'] = $canonicalBytes;
        $base['current_hash'] = hash('sha256', $canonicalBytes);

        return [
            'envelope_id' => Str::uuid()->toString(),
            'type' => 'FISCAL_EVENT',
            'payload_version' => 1,
            'idempotency_key' => $terminal->id.':'.$sequenceNumber,
            'payload' => $base,
        ];
    }

    /**
     * v3 SALE payload -- the F-16 golden fixture's own field values (a
     * PROVEN validator-passing v4 REFUND payload, §17's golden corpus)
     * stripped of the three v4-only keys and re-typed as invoice_type_code
     * = SALE, original_receipt_reference = null. Reusing F-16's numeric
     * values keeps the total/subtotal/vat arithmetic invariants intact
     * without re-deriving them by hand.
     *
     * @return array<string, mixed>
     */
    private function v3SalePayload(string $receiptUuid, string $businessDate, string $eventTimeDevice, string $productId): array
    {
        return [
            'business_date' => $businessDate,
            'approval_references' => [],
            'buyer' => null,
            'cash_rounding_adjustment' => '0.00',
            'cash_rounding_denomination' => '0.00',
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => $eventTimeDevice,
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '2.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => $productId,
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                // GROSS/TTC — see `v4RefundPayload()`'s note; the same
                // device contract governs the SALE side of this fixture.
                'unit_price' => '12.00',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '20.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '12.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => [
                    'city' => 'Paris',
                    'country_code' => 'FR',
                    'postal_code' => '75001',
                    'street' => '1 rue de la Paix',
                ],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => self::SHIFT_ID,
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '', // overwritten by sealedEnvelope's payload consumer -- see note below
            'total' => '12.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '12.00',
                'net_amount' => '10.00',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '2.00',
            ]],
            'vat_total' => '2.00',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * v4 REFUND payload -- the F-16 golden fixture verbatim (§17's proven
     * corpus entry `F-16-refund-v4-cash-eur`), with only the
     * event-instance-specific identifiers (receipt_uuid, original receipt
     * reference, business_date) substituted.
     *
     * @param  list<array<string, mixed>>  $approvalReferences
     * @return array<string, mixed>
     */
    private function v4RefundPayload(string $receiptUuid, string $businessDate, string $eventTimeDevice, string $originalFiscalEventId, string $originalReceiptUuid, string $productId, array $approvalReferences = []): array
    {
        return [
            'business_date' => $businessDate,
            'approval_references' => $approvalReferences,
            'buyer' => null,
            'cash_rounding_adjustment' => '0.00',
            'cash_rounding_denomination' => '0.00',
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => $eventTimeDevice,
            'invoice_type_code' => 'REFUND',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '2.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => $productId,
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                // GROSS/TTC, NOT net. The canonical SALE_RECEIPT
                // `line_items[].unit_price` is written verbatim from the POS
                // cart's tax-INCLUSIVE price; the NET figure lives in
                // `line_subtotal` (precision contract, "unit_price is
                // context-overloaded"). The F-16 golden this fixture mirrors
                // carried the same net-authored mistake and was corrected to
                // '12.00' in 9e0c5f755 (GoldenFixtureBuilder::f16RefundV4Cash);
                // this file hand-duplicated that mistake and is corrected here
                // to the same value. Only this one field moves — the aggregate
                // identity (subtotal 10.00 + vat_total 2.00 == total 12.00 +
                // transaction_discount_amount 0.00) is untouched.
                'unit_price' => '12.00',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '20.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_line_references' => [[
                'disposition' => 'restock',
                'original_line_index' => 0,
                'product_id' => $productId,
                'quantity' => '1.000',
            ]],
            'original_receipt_reference' => [
                'fiscal_event_id' => $originalFiscalEventId,
                'original_business_date' => $businessDate,
                'original_receipt_uuid' => $originalReceiptUuid,
                'refund_reason' => 'customer return',
            ],
            'payments' => [[
                'amount' => '12.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'refund_destination' => 'cash',
            'seller' => [
                'address' => [
                    'city' => 'Paris',
                    'country_code' => 'FR',
                    'postal_code' => '75001',
                    'street' => '1 rue de la Paix',
                ],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'settlement_allocation' => null,
            'shift_id' => self::SHIFT_ID,
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '', // overwritten by sealedEnvelope's payload consumer -- see note below
            'total' => '12.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '12.00',
                'net_amount' => '10.00',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '2.00',
            ]],
            'vat_total' => '2.00',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * SESSION_OPEN payload — the exact 13-key `SessionOpenPayload::PAYLOAD_KEYS`
     * set (`FiscalPayloadConstraintValidator` exact-matches it, so a single
     * extra or missing key quarantines the event).
     *
     * @return array<string, mixed>
     */
    private function sessionOpenPayload(
        Terminal $terminal,
        string $sessionId,
        string $shiftId,
        int $shiftNumber,
        string $businessDate,
        string $openedAtDevice,
    ): array {
        return [
            'business_date' => $businessDate,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'opened_at_device' => $openedAtDevice,
            'opening_float_amount' => self::OPENING_FLOAT,
            'operator_id' => $this->cashier->id,
            'operator_name' => $this->cashier->name,
            'session_id' => $sessionId,
            'shift_id' => $shiftId,
            'shift_number' => $shiftNumber,
            'terminal_id' => '', // overwritten by sealedEnvelope
            'terminal_label' => $terminal->code,
            'training_flag' => false,
        ];
    }

    /**
     * SESSION_CLOSE payload — the exact `SessionClosePayload::PAYLOAD_KEYS`
     * set. Its money block is the same closed-shift arithmetic the Z repeats:
     * opening float 100.00 + 24.00 cash in − 12.00 refund payout = 112.00.
     *
     * @return array<string, mixed>
     */
    private function sessionClosePayload(
        Terminal $terminal,
        string $sessionId,
        string $shiftId,
        string $businessDate,
        string $generatedAtDevice,
        string $periodStart,
        string $periodEnd,
    ): array {
        return [
            'business_date' => $businessDate,
            'cash_count_lines' => [],
            'cash_drawer_totals' => [
                'expected_cash' => self::EXPECTED_CASH,
                'opening_cash' => self::OPENING_FLOAT,
            ],
            'closure_status' => 'closed',
            'counted_cash' => self::EXPECTED_CASH,
            'expected_cash' => self::EXPECTED_CASH,
            'generated_at_device' => $generatedAtDevice,
            'manager_approval' => null,
            'operational_event_range' => ['receipt_count' => 3],
            'operator_id' => $this->cashier->id,
            'operator_name' => $this->cashier->name,
            'payment_method_totals' => $this->netCashPaymentMethodTotals(),
            'period_end' => $periodEnd,
            'period_start' => $periodStart,
            'receipt_count' => 3,
            'refunds_totals' => ['amount' => '12.00', 'count' => 1],
            'sales_totals' => ['gross_sales' => '24.00', 'net_sales' => '20.00', 'tax_amount' => '4.00'],
            'session_close_uuid' => self::SESSION_CLOSE_UUID,
            'session_id' => $sessionId,
            'shift_id' => $shiftId,
            'terminal_id' => '', // overwritten by sealedEnvelope
            'training_flag' => false,
            'variance_amount' => '0.00',
            'variance_direction' => 'balanced',
            'variance_reason' => null,
            'variance_severity' => 'balanced',
            'vat_breakdown' => $this->netVatBreakdown(),
            'voids_totals' => ['count' => 0],
        ];
    }

    /**
     * Z_REPORT payload — the exact `ZReportPayload::PAYLOAD_KEYS` set,
     * carrying the aggregates a §7.3-compliant device authors over a window
     * containing two sales and one v4 refund.
     *
     * @return array<string, mixed>
     */
    private function zReportPayload(
        Terminal $terminal,
        string $sessionId,
        string $shiftId,
        string $sessionCloseEventId,
        string $businessDate,
        string $closedAtDevice,
        string $periodStart,
        string $periodEnd,
    ): array {
        return [
            'business_date' => $businessDate,
            'cash_count' => [
                'counted_cash' => self::EXPECTED_CASH,
                'expected_cash' => self::EXPECTED_CASH,
                'lines' => [],
                'variance_amount' => '0.00',
                'variance_direction' => 'balanced',
                'variance_reason' => null,
                'variance_severity' => 'balanced',
            ],
            'cash_drawer_totals' => [
                'expected_cash' => self::EXPECTED_CASH,
                'opening_cash' => self::OPENING_FLOAT,
            ],
            'closed_at_device' => $closedAtDevice,
            'company_snapshot' => ['company_id' => $this->company->id],
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'formatted_z_number' => 'Z0001',
            'grand_totals_after' => [
                'cumulative_refunds' => '12.00',
                'cumulative_sales' => '24.00',
                'cumulative_tax' => '4.00',
                'perpetual_grand_total' => '24.00',
                'receipt_count_lifetime' => 3,
            ],
            'grand_totals_before' => [
                'cumulative_refunds' => '0.00',
                'cumulative_sales' => '0.00',
                'cumulative_tax' => '0.00',
                'perpetual_grand_total' => '0.00',
                'receipt_count_lifetime' => 0,
            ],
            'legacy_report_reference' => null,
            'operational_event_range' => ['receipt_count' => 3],
            'operator_id' => $this->cashier->id,
            'operator_name' => $this->cashier->name,
            'payment_method_totals' => $this->netCashPaymentMethodTotals(),
            'period_end' => $periodEnd,
            'period_start' => $periodStart,
            'period_type' => 'DAY',
            // Sale-only (§7.3): the refund never touches these three.
            'receipt_totals' => ['count' => 2, 'gross_sales' => '24.00', 'net_sales' => '20.00', 'tax_amount' => '4.00'],
            // Positive magnitude, its own tracked block (§3.1/§7.3).
            'refunds_totals' => ['amount' => '12.00', 'count' => 1],
            'seller' => null,
            'session_event_range' => [
                'first_sequence' => 1,
                'last_sequence' => 2,
                'session_close_event_id' => $sessionCloseEventId,
            ],
            'session_id' => $sessionId,
            'shift_id' => $shiftId,
            'terminal_id' => '', // overwritten by sealedEnvelope
            'terminal_label' => $terminal->code,
            'tolerance_summary' => null,
            'training_flag' => false,
            'vat_breakdown' => $this->netVatBreakdown(),
            'voids_totals' => ['count' => 0],
            'z_number' => 1,
            'z_report_uuid' => self::Z_REPORT_UUID,
        ];
    }

    /**
     * The payment-method block a §7.3-compliant device authors: the refund's
     * cash leg is SUBTRACTED from the CASH bucket (24.00 in − 12.00 out) while
     * `transaction_count` still counts all three transactions. Deliberately
     * NOT derived from any figure the assertions read back.
     *
     * @return list<array<string, mixed>>
     */
    private function netCashPaymentMethodTotals(): array
    {
        return [[
            'payment_type' => 'CASH',
            'total_amount' => '12.00',
            'transaction_count' => 3,
        ]];
    }

    /**
     * The VAT block a §7.3-compliant device authors: the refund's per-rate
     * net/VAT/gross is SUBTRACTED from the running totals. Two sales at
     * 10.00 net + 2.00 VAT = 12.00 gross each, minus one refund of the same
     * shape ⇒ 10.00 / 2.00 / 12.00 at the single 20% rate.
     *
     * @return list<array<string, mixed>>
     */
    private function netVatBreakdown(): array
    {
        return [[
            'gross_amount' => '12.00',
            'net_amount' => '10.00',
            'tax_rate' => 20,
            'vat_amount' => '2.00',
        ]];
    }

    /**
     * Narrow a value read out of the projected Z `report_data` JSON (typed
     * `mixed` by construction) to a real `numeric-string` before it reaches
     * `bccomp`. A fail-loud guard, not a cast-to-silence: a Z whose money
     * field is not a numeric string is itself the defect being reported.
     *
     * @return numeric-string
     */
    private function numericString(mixed $value): string
    {
        self::assertIsString($value);
        if (! is_numeric($value)) {
            throw new RuntimeException('Z report_data money field is not a numeric string: '.$value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed in ReceiptReturnRefactorV3Test fixture');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
        }

        ksort($value);

        return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
    }

    /**
     * Same 7-field OPERATOR_APPROVAL_GRANTED + OVERRIDE_VOID_OR_RETURN
     * evidence-authoring pattern as `ReceiptReturnFlowTest::withVoidReturnApproval()`
     * -- mirrored here (not extracted to a shared trait) to keep this
     * acceptance test file self-contained per its own scope.
     *
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function withVoidReturnApproval(Receipt $receipt, array $requestData): array
    {
        /** @var list<array<string, mixed>> $requestLines */
        $requestLines = $requestData['lines'] ?? [];
        $lineIds = collect($requestLines)
            ->pluck('line_id')
            ->map(static fn (mixed $lineId): string => (string) $lineId)
            ->sort()
            ->values()
            ->all();
        $reason = (string) ($requestData['notes'] ?? '');
        $target = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'line_ids' => $lineIds,
            'reason' => $reason,
        ];

        $approvalId = Str::uuid()->toString();
        $approvalEventId = Str::uuid()->toString();
        $overrideEventId = Str::uuid()->toString();

        $approvalPayload = [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->cashier->id,
            'supervisor_user_snapshot' => ['name' => $this->cashier->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => (string) $requestData['terminal_id'],
            'training_flag' => false,
        ];
        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, $approvalPayload, (string) $requestData['terminal_id']);

        $overridePayload = [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'POS_RECEIPT_RETURN',
                'target_reference_id' => $receipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'supervisor_user_id' => $this->cashier->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => (string) $requestData['terminal_id'],
            'training_flag' => false,
        ];
        $this->storeFiscalEvent(
            $overrideEventId,
            FiscalEventType::OVERRIDE_VOID_OR_RETURN,
            $overridePayload,
            (string) $requestData['terminal_id'],
            $approvalEventId,
        );

        return $requestData + [
            'refund_request_id' => Str::uuid()->toString(),
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->cashier->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->cashier->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeFiscalEvent(
        string $id,
        FiscalEventType $eventType,
        array $payload,
        string $terminalId,
        ?string $referenceEventId = null,
    ): void {
        DB::table('fiscal_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminalId,
            'operator_id' => $this->cashier->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => (int) DB::table('fiscal_events')->where('terminal_id', $terminalId)->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'reference_event_id' => $referenceEventId,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => 'parsed',
        ]);
    }

    /**
     * Direct `fiscal_events` insert for an AUDIT_ONLY approval/override
     * event at an EXPLICIT sequence_number (the caller is responsible for
     * choosing one that doesn't collide with the operational SALE_RECEIPT
     * chain's own sequence numbers in the same tenant+company+terminal+
     * chain_context UNIQUE index).
     *
     * @param  array<string, mixed>  $payload
     */
    private function storeAuxiliaryFiscalEvent(
        Terminal $terminal,
        string $id,
        FiscalEventType $eventType,
        int $sequenceNumber,
        array $payload,
        Carbon $eventTime,
    ): void {
        DB::table('fiscal_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $eventTime->toDateString(),
            'server_received_at' => $eventTime,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => 'parsed',
        ]);
    }
}
