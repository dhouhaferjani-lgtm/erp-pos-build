<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §5.2/§17 —
 * `POST /api/v1/fiscal/refund-compensations`.
 *
 * Covers both classes, idempotency-replay, posting/atomicity, the
 * permission gate, and — review round-2 CRITICALS 3/4 + IMPORTANTS 5-11 —
 * the tenant+company scope, the dead-lettered/quarantined state-guard
 * (double-cash-out refusal), the non-refund refusal, the repository's own
 * GL account being credited (not the company-wide Cash-purpose account),
 * and the treasury-half write (repository_movements + balance decrement).
 */
final class RefundCompensationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $operator;

    private FiscalEvent $rejectedEvent;

    /** The repository's OWN GL account -- deliberately DIFFERENT from the
     *  company-wide SystemAccountPurpose::Cash account, so a test can
     *  prove the credited leg is the repository's account, not the
     *  purpose-based one. */
    private Account $tillAccount;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'US', // no dedicated seeder -> Generic chart
        ]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->tillAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TILL-1',
            'name' => 'Till #1',
            'type' => AccountType::Asset,
        ]);

        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Register',
            'type' => RepositoryType::CashRegister,
            'currency' => 'EUR',
            'is_active' => true,
            'gl_account_id' => $this->tillAccount->id,
        ]);

        $this->operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->operator->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->operator->givePermissionTo('fiscal.refunds.manage_dead_letters');

        $this->rejectedEvent = $this->storeRejectedRefundEvent($this->tenant, $this->company);
    }

    public function test_invalid_refund_class_posts_write_off_entry_and_persists_compensation(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.compensation_class', 'invalid_refund');
        $response->assertJsonPath('data.fiscal_event_id', $this->rejectedEvent->id);
        $response->assertJsonPath('data.shift_id', '22222222-2222-4222-8222-222222222222');

        $this->assertDatabaseHas('fiscal_refund_compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
        ]);

        $journalEntryId = $response->json('data.journal_entry_id');
        $this->assertDatabaseHas('journal_entries', [
            'id' => $journalEntryId,
            'status' => 'posted',
            'source_type' => 'fiscal_refund_compensation',
        ]);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_valid_unbooked_class_posts_sales_return_entry(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'valid_unbooked',
            'operator_attestation' => 'Genuine refund; purpose-account was missing at booking time.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.compensation_class', 'valid_unbooked');
    }

    // =================================================================
    // review round-2 CRITICAL 4 — the repository's OWN GL account is
    // credited, never the company-wide Cash-purpose account.
    // =================================================================

    public function test_credits_the_repositorys_own_gl_account_not_the_cash_purpose_account(): void
    {
        $cashPurposeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        self::assertNotSame(
            $this->tillAccount->id,
            $cashPurposeAccount->id,
            'fixture sanity: the till account must differ from the Cash-purpose account for this test to be meaningful',
        );

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);
        $response->assertStatus(201);

        $journalEntryId = $response->json('data.journal_entry_id');
        $creditLine = DB::table('journal_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->where('credit', '>', 0)
            ->first();
        self::assertNotNull($creditLine);
        self::assertSame($this->tillAccount->id, $creditLine->account_id);
        self::assertNotSame($cashPurposeAccount->id, $creditLine->account_id);
    }

    // =================================================================
    // review round-2 IMPORTANT 9/10 — amount = cash tender leg
    // (payments[0].amount), treasury-half writes: repository_movements
    // count 1, balance decremented EXACTLY once (happy path + replay).
    // =================================================================

    public function test_treasury_half_writes_exactly_one_movement_and_decrements_balance_once_including_on_replay(): void
    {
        Sanctum::actingAs($this->operator);
        $balanceBefore = (string) $this->repository->balance;

        $first = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);
        $first->assertStatus(201);

        $this->assertDatabaseCount('repository_movements', 1);
        $this->repository->refresh();
        $balanceAfterFirst = (string) $this->repository->balance;
        self::assertSame(
            bcsub($balanceBefore, '20.00', 3), // precision-ok: payment_repositories.balance is decimal(N,3)
            $balanceAfterFirst,
        );

        // treasury re-verification MINOR item 3 -- the movement port's own
        // derived idempotency key is structurally distinct from
        // TreasuryReceiptBridge's per-payment-leg keys
        // (fiscal_event:{id}:payment:{i}); pin the 'refund_writeoff' leg
        // discriminator so it can never collide with that same event's own
        // payment-leg movement.
        $this->assertDatabaseHas('repository_movements', [
            'idempotency_key' => "fiscal_event:{$this->rejectedEvent->id}:refund_writeoff",
        ]);

        // Replay -- must NOT write a second movement or decrement again.
        $second = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);
        $second->assertStatus(200);

        $this->assertDatabaseCount('repository_movements', 1);
        $this->repository->refresh();
        self::assertSame($balanceAfterFirst, (string) $this->repository->balance);
    }

    // =================================================================
    // W-5b Option B (gate IMPORTANT #4, orchestrator ruling, 2026-08-07):
    // compensate() is dead-letter/quarantine REMEDIATION of a refund the
    // device already paid out in cash — it belongs to the replay class, so
    // an outflow that would take the drawer negative RECORDS and ALERTS
    // instead of a hard 422 that would dead-end an already-attested
    // operator remediation.
    // =================================================================

    public function test_compensation_on_a_would_go_negative_repository_records_and_warns_not_422(): void
    {
        Log::spy();
        Sanctum::actingAs($this->operator);

        // The fixture repository opens at balance 0.00 (factory default) and
        // the fiscal event's refund amount is 20.00 — this compensation
        // write-off would take it to -20.00.
        $this->assertSame('0.000', (string) $this->repository->fresh()?->balance);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        // No 422 — the write-off is recorded, not blocked.
        $response->assertStatus(201);

        $this->repository->refresh();
        $this->assertSame('-20.000', (string) $this->repository->balance);
        $this->assertDatabaseCount('repository_movements', 1);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Treasury movement recorded a negative repository balance',
                // EUR's ISO 4217 scale is 2 (the default), unlike the
                // model's `decimal:3` display cast used above — the raw
                // bcmath result the port computes (and logs) is '-20.00'.
                \Mockery::on(fn (array $context): bool => $context['repository_id'] === $this->repository->id
                    && $context['balance_after'] === '-20.00'
                    && $context['source_type'] === 'fiscal_event'),
            );
    }

    // =================================================================
    // Idempotency — the mandatory RED/GREEN contract for this scope item.
    // =================================================================

    public function test_repeated_post_for_the_same_fiscal_event_id_returns_the_existing_record_not_a_duplicate(): void
    {
        Sanctum::actingAs($this->operator);

        $first = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);
        $first->assertStatus(201);
        $firstCompensationId = $first->json('data.id');

        $second = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            // Deliberately a DIFFERENT class/attestation on replay — the
            // idempotent-hit short-circuit must return the ORIGINAL record
            // unmodified, never re-evaluate against the new input.
            'compensation_class' => 'valid_unbooked',
            'operator_attestation' => 'A different attestation text on replay.',
        ]);

        $second->assertStatus(200);
        $second->assertJsonPath('data.id', $firstCompensationId);
        $second->assertJsonPath('data.compensation_class', 'invalid_refund');

        $this->assertDatabaseCount('fiscal_refund_compensations', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_permission_gate_rejects_a_user_without_the_permission(): void
    {
        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($unauthorized);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
    }

    public function test_unknown_compensation_class_is_rejected(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'bogus_class',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
    }

    // =================================================================
    // review round-2 CRITICAL 3 — scope + state-guard.
    // =================================================================

    public function test_a_projected_event_that_already_applied_is_refused_the_double_cash_out_case(): void
    {
        // treasury re-verification IMPORTANT (guard-precision fix) --
        // dead-lettered (admits the state-guard) but cash ALREADY moved,
        // e.g. treasury_receipt_bridge applied and recorded its payment
        // leg(s) before pos_core_receipt (a SEPARATE projector for the
        // SAME fiscal_event_id) dead-lettered for an unrelated reason.
        // Writing off a "rejected" compensation for it would
        // double-cash-out the drawer. Keyed on repository_movements, NOT
        // on any projector's Applied projection_status -- an "any Applied
        // projection" check would wrongly ALSO refuse the valid_unbooked
        // case below (pos_core_receipt Applied + bridge DeadLettered +
        // zero movements), which must proceed.
        $appliedEvent = $this->storeRejectedRefundEvent($this->tenant, $this->company, deadLettered: true);
        $this->seedCashAlreadyMovedFor($appliedEvent);

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $appliedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
        $this->assertDatabaseCount('repository_movements', 1); // only the pre-seeded movement -- no new write
    }

    // =================================================================
    // treasury re-verification CRITICAL — `integrity_exception_class` is
    // WRITE-ONCE (Task-8 trigger): it stays 'canonical_parse_failure'
    // FOREVER even after `ParseFailureResolutionService` resolves the
    // parse failure and the event's projections DISPATCH+APPLY through
    // the NORMAL path. The state-guard above (dead-lettered OR
    // ingress-quarantined) alone is therefore an UNRELIABLE "still
    // rejected" signal for a RESOLVED-and-since-applied quarantine event
    // -- reachable double-cash-out. `storeRejectedRefundEvent()` never
    // exercises the quarantine arm at all (hardcodes
    // integrity_exception_class=null), so these two tests are the only
    // coverage of that arm.
    // =================================================================

    public function test_a_resolved_quarantine_event_with_an_applied_projection_is_refused_the_double_cash_out_case(): void
    {
        // Mirrors the exact post-ParseFailureResolutionService::resolve()
        // shape: integrity_exception_class stays 'canonical_parse_failure'
        // (write-once), integrity_status flips to Verified,
        // payload_parse_status flips to Parsed, payload is populated --
        // and cash already moved (guard-precision fix: keyed on
        // repository_movements, not on any projector's Applied
        // projection_status).
        $resolvedEvent = $this->storeIngressQuarantinedRefundEvent($this->tenant, $this->company, resolved: true);
        $this->seedCashAlreadyMovedFor($resolvedEvent);

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $resolvedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
        $this->assertDatabaseCount('repository_movements', 1); // only the pre-seeded movement -- no new write
    }

    public function test_an_unresolved_quarantine_event_with_a_null_payload_is_refused_as_not_a_refund(): void
    {
        // integrity_exception_class='canonical_parse_failure' but the
        // parse failure was NEVER resolved -- payload is still NULL, so
        // there is no invoice_type_code/amount/shift_id to safely act on.
        // This deliberately hits the SAME not_a_refund throw a non-refund
        // SALE_RECEIPT would, documented explicitly in
        // RefundCompensationService rather than inventing a new reason
        // code for a case that is already refused for the right
        // underlying cause (no readable payload).
        $unresolvedEvent = $this->storeIngressQuarantinedRefundEvent($this->tenant, $this->company, resolved: false);

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $unresolvedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
        $this->assertDatabaseCount('repository_movements', 0);
    }

    // =================================================================
    // treasury re-verification IMPORTANT (guard-precision fix) — the
    // spec's PRIMARY valid_unbooked partition, finally reachable:
    // pos_core_receipt Applied (the receipt itself projected fine) +
    // treasury_receipt_bridge DeadLettered (e.g. a missing purpose-
    // account at booking time) + ZERO repository_movements (cash never
    // moved -- the bridge never got to record a leg). This must PROCEED
    // to 201. Before the fix, the over-broad "any Applied projection"
    // check refused this exact partition, making SalesReturn seeding,
    // the backfill's second purpose, and the §5.3 both-purpose precheck
    // unreachable dead code, and permanently 422ing
    // DeadLetteredProjectionsController's own advertised
    // write_off_action_url for it.
    // =================================================================

    public function test_pos_core_receipt_applied_and_treasury_bridge_dead_lettered_with_no_movements_proceeds_the_valid_unbooked_happy_path(): void
    {
        $event = $this->storeRejectedRefundEvent($this->tenant, $this->company, deadLettered: false);
        DB::table('fiscal_event_projections')->insert([
            [
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $event->id,
                'projector_name' => 'pos_core_receipt',
                'projection_status' => ProjectionStatus::Applied->value,
                'attempts' => 1,
                'dead_lettered_at' => null,
            ],
            [
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $event->id,
                'projector_name' => 'treasury_receipt_bridge',
                'projection_status' => ProjectionStatus::DeadLettered->value,
                'attempts' => 5,
                'dead_lettered_at' => now(),
            ],
        ]);
        $this->assertDatabaseCount('repository_movements', 0);

        Sanctum::actingAs($this->operator);
        $balanceBefore = (string) $this->repository->balance;

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $event->id,
            'compensation_class' => 'valid_unbooked',
            'operator_attestation' => 'Genuine refund; purpose-account was missing at booking time.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.compensation_class', 'valid_unbooked');

        $this->assertDatabaseCount('repository_movements', 1);
        $this->repository->refresh();
        self::assertSame(
            bcsub($balanceBefore, '20.00', 3), // precision-ok: payment_repositories.balance is decimal(N,3)
            (string) $this->repository->balance,
        );
    }

    public function test_a_fiscal_event_belonging_to_another_company_is_refused_not_found(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id, 'country_code' => 'US']);
        $otherEvent = $this->storeRejectedRefundEvent($this->tenant, $otherCompany);

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $otherEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
    }

    public function test_a_non_refund_event_is_refused(): void
    {
        // event_type=SALE_RECEIPT but invoice_type_code=SALE, dead-lettered
        // (structurally possible -- any projection can dead-letter).
        $saleEvent = $this->storeRejectedRefundEvent($this->tenant, $this->company, invoiceTypeCode: 'SALE');

        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $saleEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function storeRejectedRefundEvent(
        Tenant $tenant,
        Company $company,
        bool $deadLettered = true,
        string $invoiceTypeCode = 'REFUND',
    ): FiscalEvent {
        $payload = [
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'invoice_type_code' => $invoiceTypeCode,
            'total' => '20.00',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'payments' => [[
                'amount' => '20.00',
                'method_code' => 'CASH',
            ]],
        ];

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operator->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            // fiscal re-verification (CI PG-filter expansion) —
            // `fiscal_events_source_event_paired_null` CHECK requires
            // BOTH null or BOTH non-null (PG-only; SQLite never enforced
            // it, which is how this fixture passed there while failing
            // under real PG). No assertion in this file reads
            // source_event_id's value.
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', (string) Str::uuid()),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();

        if ($deadLettered) {
            DB::table('fiscal_event_projections')->insert([
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $event->id,
                'projector_name' => 'pos_core_receipt',
                'projection_status' => ProjectionStatus::DeadLettered->value,
                'attempts' => 5,
                'dead_lettered_at' => now(),
            ]);
        }

        return $event;
    }

    /**
     * Builds an ingress-quarantine fiscal_events row --
     * `integrity_exception_class='canonical_parse_failure'` -- in EITHER
     * the still-unresolved shape (payload NULL, `payload_parse_status`
     * Failed, `integrity_status` Quarantined) or the
     * `ParseFailureResolutionService::resolve()`-post shape (payload
     * populated, `payload_parse_status` Parsed, `integrity_status`
     * Verified, `integrity_exception_class` UNCHANGED -- it is write-once
     * per the Task-8 trigger). `storeRejectedRefundEvent()` above never
     * sets `integrity_exception_class`, so this is the only fixture that
     * exercises the quarantine arm of the state-guard.
     */
    private function storeIngressQuarantinedRefundEvent(
        Tenant $tenant,
        Company $company,
        bool $resolved,
    ): FiscalEvent {
        $payload = $resolved ? [
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'invoice_type_code' => 'REFUND',
            'total' => '20.00',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'payments' => [[
                'amount' => '20.00',
                'method_code' => 'CASH',
            ]],
        ] : null;

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operator->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            // fiscal re-verification (CI PG-filter expansion) —
            // `fiscal_events_source_event_paired_null` CHECK requires
            // BOTH null or BOTH non-null (PG-only; SQLite never enforced
            // it, which is how this fixture passed there while failing
            // under real PG). No assertion in this file reads
            // source_event_id's value.
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            // Unresolved: the canonical bytes are exactly what genuinely
            // FAILED to parse at ingest (opaque to StrictCanonicalParser).
            // Resolved: mirrors the original (pre-resolution) bytes --
            // ParseFailureResolutionService never rewrites canonical_bytes
            // (write-once/frozen per the Task-8 trigger), only `payload`.
            'canonical_bytes' => 'not-parseable-as-canonical-bytes',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', (string) Str::uuid()),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => $resolved ? IntegrityStatus::Verified : IntegrityStatus::Quarantined,
            'integrity_exception_class' => 'canonical_parse_failure',
            'integrity_exception_reason' => 'canonical bytes failed to parse',
            'payload' => $payload,
            'payload_parse_status' => $resolved ? PayloadParseStatus::Parsed : PayloadParseStatus::Failed,
        ])->refresh();
    }

    /**
     * Raw-inserts a `repository_movements` row for `$event` on
     * `$this->repository` -- simulates the cash-moving leg
     * `TreasuryReceiptBridge::recordPaymentLeg()` would have written,
     * without running the full bridge. The guard-precision fix keys
     * `already_applied` on THIS table (`source_type`/`source_id`), never
     * on any `fiscal_event_projections` row's `projector_name`/
     * `projection_status` -- see `RefundCompensationService`'s own
     * docblock at the check site.
     *
     * @param  numeric-string  $amount
     */
    private function seedCashAlreadyMovedFor(FiscalEvent $event, string $amount = '20.00'): void
    {
        DB::table('repository_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'payment_repository_id' => $this->repository->id,
            'direction' => 'out',
            'amount' => $amount,
            'currency' => 'EUR',
            'balance_after' => bcsub((string) $this->repository->balance, $amount, 3), // precision-ok: payment_repositories.balance is decimal(N,3)
            'ordinal' => 1,
            'source_type' => MovementSourceType::FiscalEvent->value,
            'source_id' => $event->id,
            'idempotency_key' => "fiscal_event:{$event->id}:payment:0",
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
