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
 * **Scope note (review round-2 IMPORTANT 15).** The "sale -> refund ->
 * next sale" flow this file exercises deliberately stops short of a Z
 * close/report leg -- closing the shift/day is a SEPARATE, later
 * concern (wave 3, not yet scheduled) layered on top of this same
 * fiscal-events chain, not part of §1.1's own acceptance-test text
 * (which specifies exactly the three-event flow this file drives). Not
 * silently omitted: recorded here explicitly as deferred, not forgotten.
 */
final class ReceiptReturnRefactorV3Test extends TestCase
{
    use RefreshDatabase;

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
    ): array {
        $payloadEventTimeDevice = $payload['event_time_device'];
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
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $envelopeEventTimeDevice,
            'business_date' => $businessDate,
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
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
                'unit_price' => '10.00',
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
            'shift_id' => '22222222-2222-4222-8222-222222222222',
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
                'unit_price' => '10.00',
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
            'shift_id' => '22222222-2222-4222-8222-222222222222',
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
