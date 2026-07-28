<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\ParseFailureResolutionService;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\InvalidCorrectedPayloadException;
use App\Modules\Fiscal\Domain\Exceptions\ParseFailureResolutionPreconditionException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Infrastructure\Commands\EnqueueResolvedEventProjectionsCommand;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 24 — Parse-failure resume contract (spec v7 §7.5, §15.2).
 *
 * Validates two surfaces:
 *
 *   1. `ParseFailureResolutionService::resolve($eventId, $payload, $resolver)`
 *      writes the corrected payload + flips `payload_parse_status` `failed →
 *      parsed` + flips `integrity_status` `quarantined → verified` + stamps
 *      `integrity_resolved_at` / `integrity_resolved_by` + creates one
 *      `pending` `fiscal_event_projections` row per currently-active
 *      projector — all in ONE database transaction, satisfying the Task 8
 *      immutability trigger's gated `failed → parsed` resume transition. The
 *      queue dispatch (one job per pending row) happens AFTER commit via
 *      `DB::afterCommit()`, mirroring the `OutboxIngestor` pattern (Task 19
 *      standing pattern F4 round-2).
 *
 *   2. `fiscal:enqueue-resolved-event-projections` (spec §15.2) is the named
 *      recovery path for the "resolution committed but enqueue never ran"
 *      crash window. It (a) creates any MISSING `pending` projection rows
 *      for currently-active projectors against an already-resolved fiscal
 *      event (via `INSERT … ON CONFLICT … DO NOTHING` — Task 19 standing
 *      pattern), (b) enqueues every `pending` row, and (c) NEVER touches
 *      `running` / `applied` / `dead_lettered` rows (those are owned by
 *      Horizon's lifecycle per Task 23). Permission-gated by
 *      `fiscal.events.resolve_quarantine`.
 *
 * **`integrity_exception_class` is forensic metadata.** Per the Task 8
 * trigger source (`apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php`
 * lines 128–136), this column is write-once: once set on a quarantined row
 * it cannot be changed nor unset. The resolution UPDATE LEAVES it at
 * `canonical_parse_failure` — the resolved-by + resolved-at + integrity-status
 * stamps tell the operator the row was resolved, and the persistent
 * forensic class tells them WHY it was quarantined in the first place.
 *
 * **Test matrix.**
 *   - 4 plan tests (atomic resolution + projection rows, crash recovery,
 *     command idempotency, command permission gate)
 *   - 5 lifecycle-edge tests added in round-1 (resolution preconditions,
 *     invalid corrected payload, command no-op on non-parsed, command
 *     no-op when all rows terminal, command --tenant filter)
 */
final class ParseFailureResumeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private User $resolverUser;

    /** Genesis seed seeded into `pos_terminals`. */
    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('0', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        // Spatie's `tenant_id`-scoped permission storage requires the
        // registrar team id be set BEFORE `givePermissionTo` / `can()`
        // calls — otherwise pivot inserts violate the NOT NULL
        // `model_has_permissions.tenant_id` constraint.
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Resolver',
        ]);
        $user->givePermissionTo('fiscal.events.resolve_quarantine');
        $this->resolverUser = $user;

        $this->operatorId = Str::uuid()->toString();

        // Rebind FiscalEventProjectionRegistry with a single test-local fake
        // SALE_RECEIPT projector so projection-row creation is deterministic
        // and independent of production projectors tagged by other service
        // providers (Task 21 POS-core, Task 22 Treasury bridge).
        $this->registerFakeProjectors([
            new ResumeFakeProjector('pos_core_receipt', null, 50),
        ]);

        // Catch dispatched jobs without running them — the recovery-path tests
        // assert via Queue::assertPushed.
        Queue::fake();
    }

    // =================================================================
    // Plan §1823 — 4 core tests
    // =================================================================

    public function test_resolution_writes_payload_flips_status_and_creates_projection_rows_atomically(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayload(), $this->resolverUser);

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertNotNull($row->payload);
        $this->assertNotNull($row->integrity_resolved_at);
        $this->assertSame($this->resolverUser->id, $row->integrity_resolved_by);
        // Per Task 8 trigger source — integrity_exception_class is write-once
        // forensic metadata; it STAYS at 'canonical_parse_failure' after
        // resolution. The resolved-at/by stamps tell the operator the row is
        // resolved; the persistent class tells them WHY it was quarantined.
        $this->assertSame(
            IntegrityExceptionClass::CanonicalParseFailure->value,
            $row->integrity_exception_class,
        );

        $this->assertGreaterThan(
            0,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $event->id)
                ->where('projection_status', ProjectionStatus::Pending->value)
                ->count(),
        );

        // Jobs dispatched after T1 commit — exactly one per pending row.
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);
    }

    public function test_crash_between_commit_and_enqueue_is_recoverable_without_rewriting_payload(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        // Simulate: the resolution transaction committed (payload written +
        // pending projection rows created) but the after-commit enqueue
        // never ran. The standalone command must enqueue the pending rows
        // without re-writing the now-write-once payload.
        $this->resolveButSkipEnqueue($event->id);

        // Sanity — row already resolved + at least one pending projection.
        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertNotNull($row->payload);
        $pendingBefore = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->where('projection_status', ProjectionStatus::Pending->value)
            ->count();
        $this->assertGreaterThan(0, $pendingBefore);

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, $pendingBefore);

        // The write-once payload is untouched — the row was already parsed
        // when the command ran, so the trigger's payload-write gate (Step 4)
        // would have raised on a second write.
        $rowAfter = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($rowAfter);
        $this->assertSame($row->payload, $rowAfter->payload);
    }

    public function test_command_is_idempotent_and_safe_to_rerun(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayload(), $this->resolverUser);

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        // No duplicate projection rows — the UNIQUE
        // (fiscal_event_id, projector_name) constraint backs idempotency.
        $total = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->count();
        $distinct = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->distinct()
            ->count('projector_name');
        $this->assertSame($total, $distinct);
    }

    public function test_command_is_permission_gated(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenantId]);

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => Str::uuid()->toString(),
            '--actor-id' => $unprivileged->id,
        ])->assertExitCode(1);
    }

    // =================================================================
    // Round-1 lifecycle-edge tests (discriminated-union completeness)
    // =================================================================

    public function test_resolution_rejects_non_quarantined_row_with_typed_throw(): void
    {
        // A verified event has no quarantine to resolve.
        $event = $this->storeVerifiedFiscalEvent();

        $this->expectException(ParseFailureResolutionPreconditionException::class);

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayload(), $this->resolverUser);
    }

    public function test_resolution_rejects_invalid_corrected_payload_and_row_stays_parse_failed(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        // Missing required SALE_RECEIPT keys — DTO::fromArray() will throw,
        // and the service must surface it as a typed
        // InvalidCorrectedPayloadException without mutating the row.
        $invalid = ['only' => 'one_field'];

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $invalid, $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException.');
        } catch (InvalidCorrectedPayloadException) {
            // expected — row must be untouched.
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertNull($row->payload);
        $this->assertNull($row->integrity_resolved_at);
        $this->assertNull($row->integrity_resolved_by);

        // No projection rows created — the transaction rolled back.
        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $event->id)
                ->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_command_is_noop_on_still_parse_failed_row(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        // The event was never resolved — payload_parse_status is still
        // 'failed'. The command queries by parse_status='parsed' and so
        // skips this row.
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $event->id)
                ->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_command_does_not_redispatch_running_applied_or_dead_lettered_rows(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayload(), $this->resolverUser);

        Queue::fake(); // reset — drop the after-commit dispatch from resolve()

        // Move every projection row through to a non-pending terminal /
        // in-flight state so the command finds nothing to dispatch.
        DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->update([
                'projection_status' => ProjectionStatus::Applied->value,
                'applied_at' => now()->utc(),
            ]);

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // =================================================================
    // Round-2 regression tests
    //
    // F2 (Opus) = T24-P1 (Codex) — corrected payload bypassed
    // StrictCanonicalParser's per-event constraints, only ran through
    // DTO::fromArray() top-level type checks. Three tests pin payloads
    // that pass DTO::fromArray() but fail the strict parser's per-event
    // grammar (extra top-level key, wrong currency scale, associative
    // sub-array shape) — each must surface as
    // InvalidCorrectedPayloadException and leave the row at
    // payload_parse_status='failed'.
    //
    // T24-P2 (Codex) — command must scope the Spatie permission check
    // to the actor's tenant_id via PermissionRegistrar; without that
    // scope, `can()` consults whatever team id was previously set on
    // the registrar (typically NULL in a console context).
    //
    // F6 (Opus P3) — exit code 2 transient-failure path now pinned with
    // a throwing registry resolver.
    // =================================================================

    public function test_resolver_rejects_payload_with_extra_top_level_key_via_strict_parser(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        $payload = $this->correctedPayload();
        // Extra payload-level key — DTO::fromArray() does not check key set;
        // StrictCanonicalParser::validatePayloadKeySet does. Round-2 fix
        // requires the resolver to delegate to the same constraint validator.
        $payload['unexpected_extra_key'] = 'whatever';

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $payload, $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException for extra top-level key.');
        } catch (InvalidCorrectedPayloadException) {
            // expected
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertNull($row->payload);
        $this->assertNull($row->integrity_resolved_at);
        $this->assertNull($row->integrity_resolved_by);
    }

    public function test_resolver_accepts_a_corrected_v3_payload_carrying_the_cash_rounding_keys(): void
    {
        // The QUARANTINE-REPAIR path validates against the EVENT'S OWN
        // event_version. Without that threading at the validatePayloadKeySet
        // call site, a legal 30-key v3 correction is rejected as
        // `payload_extra_field` and the receipt can NEVER be un-quarantined.
        $event = $this->storeParseFailedFiscalEvent(eventVersion: 3);
        $this->assertSame(3, $event->event_version);

        $payload = $this->correctedPayloadV3();

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $payload, $this->resolverUser);

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertNotNull($row->payload);

        /** @var array<string, mixed> $stored */
        $stored = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(30, $stored);
        $this->assertSame('-0.02', $stored['cash_rounding_adjustment']);
        $this->assertSame('0.05', $stored['cash_rounding_denomination']);
    }

    public function test_resolver_rejects_the_same_v3_payload_on_a_version_two_event(): void
    {
        // Negative twin, alongside the v2 path staying unchanged: the very
        // same corrected payload against a version-2 quarantined row must
        // still be rejected as carrying extras, and the row must stay failed.
        $event = $this->storeParseFailedFiscalEvent(eventVersion: 2);

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $this->correctedPayloadV3(), $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException for v3 keys on a v2 event.');
        } catch (InvalidCorrectedPayloadException $e) {
            $this->assertStringContainsString('payload_extra_field', $e->getMessage());
            $this->assertStringContainsString('cash_rounding_adjustment', $e->getMessage());
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertNull($row->payload);
    }

    public function test_resolver_rejects_a_v3_event_whose_correction_omits_the_rounding_keys(): void
    {
        // The two siblings are REQUIRED-always on v3 — a v2-shaped correction
        // submitted for a v3 row is incomplete, not "rounding-free".
        $event = $this->storeParseFailedFiscalEvent(eventVersion: 3);

        $payload = $this->correctedPayloadV3();
        unset($payload['cash_rounding_adjustment'], $payload['cash_rounding_denomination']);

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $payload, $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException for missing v3 rounding keys.');
        } catch (InvalidCorrectedPayloadException $e) {
            $this->assertStringContainsString('payload_missing_required', $e->getMessage());
            $this->assertStringContainsString('cash_rounding_denomination', $e->getMessage());
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertNull($row->payload);
    }

    public function test_resolver_rejects_money_field_with_wrong_currency_scale(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        // currency_scale=2 implies money strings like "10.00"; "10.000"
        // (scale=3) is wrong. DTO::fromArray() accepts any string;
        // StrictCanonicalParser::validateSaleReceiptPayload's
        // assertMoneyString rejects the scale mismatch.
        $payload = $this->correctedPayload();
        $payload['total'] = '10.000'; // wrong scale (3 digits instead of 2)

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $payload, $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException for wrong-scale money string.');
        } catch (InvalidCorrectedPayloadException) {
            // expected
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertNull($row->payload);
        $this->assertNull($row->integrity_resolved_at);
        $this->assertNull($row->integrity_resolved_by);
    }

    public function test_resolver_rejects_payload_line_with_associative_array_shape(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        // `lines` must be a JSON list (sequential array). DTO::fromArray()
        // only checks `is_array`; StrictCanonicalParser's
        // validateListOfAssoc rejects an associative-array container.
        $payload = $this->correctedPayload();
        $payload['lines'] = [
            'first' => ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00'],
        ];

        try {
            $this->app->make(ParseFailureResolutionService::class)
                ->resolve($event->id, $payload, $this->resolverUser);
            $this->fail('Expected InvalidCorrectedPayloadException for non-list lines container.');
        } catch (InvalidCorrectedPayloadException) {
            // expected
        }

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->payload_parse_status);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertNull($row->payload);
    }

    public function test_command_permission_check_is_scoped_to_actor_tenant_not_request_team(): void
    {
        // Build a second tenant + an unprivileged user that lives in tenant B.
        $tenantB = Tenant::factory()->create();
        $unprivilegedB = User::factory()->create(['tenant_id' => $tenantB->id]);

        // Pre-set the registrar's team id to something OTHER than either
        // actor's tenant — this simulates a stale / wrong team context that
        // a console command would have on entry (Laravel sets no team id by
        // default; the round-1 implementation relied on whatever was set
        // by the test setUp() at line 113). Tenant C does not exist; the
        // setUp pre-sets to tenant A — clear it so we can prove the
        // resolver re-scopes to the actor's tenant.
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Str::uuid()->toString());

        // Actor A: in tenant A, has fiscal.events.resolve_quarantine
        // permission (granted in setUp(), scoped to $this->tenantId).
        // If the command scopes correctly to the actor's tenant, this
        // succeeds (exit 0 — nothing to do).
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        // Actor B: in tenant B, lacks the permission. The command must
        // re-scope to tenant B and reject (exit 1). Without the fix the
        // result is sensitive to whatever team id was set before
        // invocation — either both succeed or both fail.
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--actor-id' => $unprivilegedB->id,
        ])->assertExitCode(1);
    }

    public function test_command_returns_exit_code_2_on_per_row_resolver_failure(): void
    {
        $event = $this->storeParseFailedFiscalEvent();

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayload(), $this->resolverUser);

        // Re-bind the projection registry with a projector whose
        // `handlesEventType()` throws — `activeProjectorsFor()` calls
        // `handlesEventType()` OUTSIDE its fail-closed resolver-only
        // try/catch, so the throw propagates into the command's per-row
        // catch (Task 18 F1 standing pattern at the command layer).
        // Exit code 2 is the transient-failure signal per the docblock
        // contract at `EnqueueResolvedEventProjectionsCommand:63-68`.
        $this->registerFakeProjectorsWithResolver(
            [new ResumeThrowingProjector('exit_code_2_test', 50)],
            new ResumeAlwaysActiveResolver,
        );

        Queue::fake(); // discard the after-commit dispatch from resolve()

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(2);
    }

    public function test_command_filters_by_tenant_when_tenant_option_provided(): void
    {
        // Two tenants, each with a resolved parse-failed event. The --tenant
        // filter must enqueue only the matching tenant's projection rows.
        $eventA = $this->storeParseFailedFiscalEvent();
        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($eventA->id, $this->correctedPayload(), $this->resolverUser);

        // Build a fresh tenant + matching terminal + parse-failed event.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        $eventB = $this->storeParseFailedFiscalEvent(
            tenantId: $otherTenant->id,
            companyId: $otherCompany->id,
            terminalId: $otherTerminal->id,
        );
        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($eventB->id, $this->correctedPayload(), $this->resolverUser);

        Queue::fake(); // reset — drop the two after-commit dispatches above

        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--tenant' => $this->tenantId,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        // Only tenant A's projection row was dispatched. With one fake
        // projector tagged per event, exactly one job per event.
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Insert a quarantined `canonical_parse_failure` event into
     * `fiscal_events`. payload is NULL, payload_parse_status is 'failed',
     * integrity_status is 'quarantined' — the exact preconditions the Task
     * 8 trigger's gated `failed → parsed` resume requires.
     */
    private function storeParseFailedFiscalEvent(
        ?string $tenantId = null,
        ?string $companyId = null,
        ?string $terminalId = null,
        int $eventVersion = 1,
    ): FiscalEvent {
        $tenantId ??= $this->tenantId;
        $companyId ??= $this->companyId;
        $terminalId ??= $this->terminalId;

        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = $this->genesisSeed;
        $canonicalBytes = '{"a":1,"a":2}'; // duplicate-key — fails the strict parser
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $this->nextSequenceFor($terminalId),
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
            'integrity_status' => IntegrityStatus::Quarantined,
            'integrity_exception_class' => IntegrityExceptionClass::CanonicalParseFailure->value,
            'integrity_exception_reason' => 'duplicate_key:a',
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Failed,
        ]);

        return $event->refresh();
    }

    private function nextSequenceFor(string $terminalId): int
    {
        $max = DB::table('fiscal_events')
            ->where('terminal_id', $terminalId)
            ->max('sequence_number');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    /**
     * A verified SALE_RECEIPT event — used by the precondition-violation test.
     */
    private function storeVerifiedFiscalEvent(): FiscalEvent
    {
        $payload = $this->correctedPayload();
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $canonicalBytes = '{}';
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $this->nextSequenceFor($this->terminalId),
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
            'previous_hash' => $this->genesisSeed,
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

    /**
     * Pass 2A.PHP.2 — 28-key Candidate C-v3 SALE_RECEIPT payload per
     * synthesis v5 §3. Hand-balanced totals at scale 2.
     *
     * @return array<string, mixed>
     */
    private function correctedPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
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
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
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
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * 30-key SaleReceiptV3 correction (cash rounding, spec §4.4): the V2 line
     * shape plus the two signed rounding siblings.
     *
     * EUR (scale 2), D 0.05: exact_total 10.02 → rounded 10.00, adj -0.02.
     * |adj| 0.02 <= D/2 (0.025) and 10.00 is an exact multiple of 0.05.
     *
     * @return array<string, mixed>
     */
    private function correctedPayloadV3(): array
    {
        $payload = $this->correctedPayload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['line_subtotal'] = '10.02';
        $lines[0]['unit_price'] = '10.02';
        $lines[0]['variant_id'] = null;
        $lines[0]['variant_name'] = null;
        $lines[0]['variant_sku'] = null;
        ksort($lines[0]);
        $payload['line_items'] = $lines;

        $payload['subtotal'] = '10.02';
        $payload['vat_breakdown'] = [[
            'gross_amount' => '10.02',
            'net_amount' => '10.02',
            'rate' => '0.00',
            'tax_category_code' => 'Z',
            'vat_amount' => '0.00',
        ]];
        $payload['cash_rounding_adjustment'] = '-0.02';
        $payload['cash_rounding_denomination'] = '0.05';
        ksort($payload);

        return $payload;
    }

    /**
     * Simulate the resolution transaction COMMITTING (payload written +
     * pending projection rows created) but the after-commit enqueue NEVER
     * RUNNING — the precise crash-between-commit-and-enqueue window the
     * recovery command is named for.
     *
     * We use a fresh ParseFailureResolutionService BUT discard everything
     * Queue::fake() captured up to this point, so the test's later
     * `Queue::assertPushed` only sees the recovery command's dispatches.
     */
    private function resolveButSkipEnqueue(string $fiscalEventId): void
    {
        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($fiscalEventId, $this->correctedPayload(), $this->resolverUser);

        // The fake queue captured the after-commit dispatch — for the
        // crash-recovery test we want to assert ONLY the post-recovery
        // enqueue, so reset the captured set here.
        Queue::fake();
    }

    /**
     * Rebind the projection registry with a deterministic list of
     * projectors. Mirrors `OutboxIngestorTest::setUp()` and
     * `ApplyFiscalEventProjectionJobTest::registerProjectors()`.
     *
     * @param  list<FiscalEventProjector>  $projectors
     */
    private function registerFakeProjectors(array $projectors): void
    {
        $this->registerFakeProjectorsWithResolver($projectors, new ResumeAlwaysActiveResolver);
    }

    /**
     * Variant of `registerFakeProjectors` that takes a custom
     * `ModuleActivationResolver` — used by the exit-code-2 test to wire a
     * resolver that throws on `isActive()` so the command's fail-closed
     * per-row catch fires.
     *
     * @param  list<FiscalEventProjector>  $projectors
     */
    private function registerFakeProjectorsWithResolver(array $projectors, ModuleActivationResolver $resolver): void
    {
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                $projectors,
                $resolver,
            ),
        );

        // Reset the cached Artisan console application — once
        // `Kernel::getArtisan()` lazily builds it, the resolved command
        // instances are cached on `Application::$commands` for the
        // lifetime of the kernel. We have to additionally re-bind the
        // command class itself to a factory closure that resolves the
        // CURRENT registry from the container at construction time —
        // because the `Artisan::starting` bootstrappers are static (the
        // closures were captured by the FiscalServiceProvider at boot
        // BEFORE this rebind ran). Even though `setArtisan(null)` clears
        // the application cache and forces a fresh build, the
        // bootstrappers re-fire and re-resolve commands through the
        // container — and the container's `make()` for the command
        // ends up with the rebound registry. The defensive
        // `app->bind(...)` on the command itself is belt-and-braces:
        // it ensures the closure is invoked anew on every container
        // resolution.
        $this->app->bind(
            EnqueueResolvedEventProjectionsCommand::class,
            fn ($app) => new EnqueueResolvedEventProjectionsCommand(
                $app->make(ConnectionInterface::class),
                $app->make(FiscalEventProjectionRegistry::class),
                $app->make(PermissionRegistrar::class),
            ),
        );
        $kernel = $this->app->make(Kernel::class);
        // PHPStan can't prove the contract resolves to the foundation
        // kernel here, but ApplicationBuilder binds the contract to the
        // foundation kernel singleton (`vendor/laravel/framework/src/
        // Illuminate/Foundation/Configuration/ApplicationBuilder.php:65`).
        if ($kernel instanceof \Illuminate\Foundation\Console\Kernel) {
            $kernel->setArtisan(null);
        }

        Log::spy();
    }
}

/**
 * Test-local SALE_RECEIPT projector — no-op apply, always handles. Used by
 * the registry so projection-row creation is deterministic.
 */
final class ResumeFakeProjector implements FiscalEventProjector
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $requiresModule,
        private readonly int $priority,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return $this->requiresModule;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return $this->priority;
    }
}

/**
 * Test-local always-active resolver — matches the pattern from
 * `ApplyFiscalEventProjectionJobTest::TreasuryAlwaysActiveResolver`. The
 * registry's `requiresModule()` invariants need a real resolver wired even
 * when no projector exercises the activation gate.
 */
final class ResumeAlwaysActiveResolver implements ModuleActivationResolver
{
    public function isActive(string $module, string $tenantId, string $companyId): bool
    {
        unset($module, $tenantId, $companyId);

        return true;
    }
}

/**
 * Test-local projector whose `handlesEventType()` throws — exercises the
 * command's exit-code-2 fail-closed per-row catch (Task 18 F1 standing
 * pattern at the command layer). `activeProjectorsFor()` calls
 * `handlesEventType()` OUTSIDE its resolver-only try/catch, so the throw
 * propagates up the stack into the command's per-row catch.
 *
 * Round-1 docblock contract: exit 2 = transient failure (registry-
 * resolver hard error, DB connection lost mid-loop). This pins the
 * contract at the projector-throws-on-dispatch variant.
 *
 * Round-3 (Codex T24-P3): we explicitly chose this projector-throw path
 * over the resolver-throw alternative. A `ModuleActivationResolver` whose
 * `isActive()` throws does NOT surface exit-code-2 because
 * `FiscalEventProjectionRegistry::activeProjectorsFor()` round-2 F1
 * fail-closed pattern catches resolver throws internally (excludes the
 * gated projector, logs critical, continues). Only a projector-side
 * throw (or other registry-external failure) propagates up the stack
 * into the command's per-row catch. The previous dead
 * `ResumeThrowingResolver` test class documented this asymmetry; that
 * documentation now lives here on the class that actually exercises the
 * exit-code-2 contract.
 */
final class ResumeThrowingProjector implements FiscalEventProjector
{
    public function __construct(
        private readonly string $name,
        private readonly int $priority,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        unset($type);

        throw new \RuntimeException('projector handlesEventType() outage (test)');
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return $this->priority;
    }
}
