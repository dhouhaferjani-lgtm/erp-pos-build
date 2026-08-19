<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\ParseFailureResolutionService;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
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
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ReadsCanonicalBytes;

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
    use ReadsCanonicalBytes;
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

    public function test_payload_rewrite_tamper_is_self_asserting(): void
    {
        $this->seedPayloadRewriteTamper();
    }

    public function test_event_chain_verifier_rejects_the_real_parse_resolution_payload_divergence(): void
    {
        $this->seedPayloadRewriteTamper();
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->resolverUser->id,
        ])
            ->expectsOutputToContain('payload does not semantically match canonical_bytes')
            ->assertExitCode(1);
    }

    // =================================================================
    // ES-06 (M3) — DETECTION half only. R-9: "divergence is detectable",
    // NOT "the workflow is fixed". The second-approver / correcting-event
    // design is owner-gated D-8 and is deliberately absent here.
    //
    // The blind spot these three tests close:
    //
    //   `ParseFailureResolutionService` is the ONLY post-seal payload-write
    //   surface the immutability trigger permits (the gated `failed ->
    //   parsed` resume, `2026_05_14_100002_create_fiscal_events_immutability.php:155-199`).
    //   Every row it can touch is by definition a `canonical_parse_failure`,
    //   i.e. a row whose `canonical_bytes` the strict parser ALREADY
    //   rejected. `VerifyEventChainCommand::parseCanonicalForVerification()`
    //   re-runs that SAME parser, so on this surface it always fails, and the
    //   verifier takes the `! $parsed->ok` branch — which emitted one FIXED
    //   sentence for every such row, whether the operator's correction was
    //   faithful to the sealed bytes or a wholesale money rewrite.
    //
    //   So the verifier had zero discriminating power on exactly the ES-06
    //   surface: it re-reported the pre-existing parse failure and called
    //   that "divergence". The sealed payload is still recoverable from the
    //   frozen bytes at the JSON level whenever the envelope itself is
    //   well-formed — the fixture below is the realistic shape (a device
    //   firmware adds one unknown envelope field; the payload inside is
    //   perfectly valid), and that is what makes real detection possible.
    // =================================================================

    public function test_recoverable_sealed_payload_parse_failure_fixture_is_self_asserting(): void
    {
        $this->seedRecoverableSealedPayloadResolution($this->divergentCorrectedPayload());
    }

    public function test_event_chain_verifier_names_a_divergent_correction_against_the_sealed_payload(): void
    {
        $this->seedRecoverableSealedPayloadResolution($this->divergentCorrectedPayload());
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->resolverUser->id,
        ])
            ->expectsOutputToContain(
                'payload does not semantically match canonical_bytes — the sealed payload was recovered from the frozen envelope and disagrees with the stored payload',
            )
            ->assertExitCode(1);
    }

    public function test_event_chain_verifier_does_not_claim_divergence_when_the_correction_matches_the_sealed_payload(): void
    {
        // The SAME fixture shape, resolved FAITHFULLY: the operator supplied
        // exactly the payload the frozen envelope carries. The row is still an
        // incident — its sealed coordinates cannot be derived, so exit code 1
        // and the coordinate incident are both preserved — but the verifier
        // must not assert a payload divergence that provably did not happen.
        $this->seedRecoverableSealedPayloadResolution($this->correctedPayload());
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $run = $this->runVerifierCapturingOutput();

        $this->assertSame(
            1,
            $run['exitCode'],
            'Suppressing the false payload claim must not soften the verdict — the coordinate incident still fails the run.',
        );
        $this->assertStringContainsString(
            'sealed coordinates could not be derived from canonical_bytes',
            $run['output'],
            'The coordinate incident must survive — a faithful correction does not make unparseable bytes parseable.',
        );
        $this->assertStringNotContainsString(
            'payload does not semantically match canonical_bytes',
            $run['output'],
            'The sealed payload WAS recovered and it matches the stored payload; claiming divergence here is the false half of the ES-06 blind spot.',
        );
    }

    // -----------------------------------------------------------------
    // ES-06 (M3 fix round 1, register finding F-1) — the recovery guard
    // must agree with the CANONICAL byte form, not with PHP's default.
    //
    // `CanonicalJsonEncoder::encodeString()` emits raw UTF-8 for U+0080+
    // per RFC 8785 §3.2.3 (`CanonicalJsonEncoder.php:106-108`). The guard's
    // re-encode originally omitted `JSON_UNESCAPED_UNICODE`, so it produced
    // `\uXXXX` for the same characters and the byte-identity check
    // (`$roundTrip !== $canonicalBytes` inside
    // `VerifyEventChainCommand::recoverSealedPayloadFromFrozenBytes()` — cited
    // by SYMBOL, not by line, because that file's line numbers have drifted
    // three times in this wave) could never hold for a receipt
    // carrying one accented or Arabic character. On France/Tunisia data
    // that is most receipts, so the discriminating branch was effectively
    // dead — fail-closed (the coordinate incident and exit 1 both survive)
    // but indiscriminate, which is precisely what R-9 forbids.
    //
    // The three tests below pin both directions of the flag: recovery
    // FIRES on canonical non-ASCII bytes (faithful and divergent), and
    // still REFUSES bytes that carry `\uXXXX` escapes OF U+0080+ — the
    // specific escape form the canonical encoder cannot emit (RFC 8785
    // §3.2.3 seals those raw), so refusing it is correct and the widening
    // is bounded.
    //
    // M3 round-2 finding N-2 — do NOT read that as "every `\uXXXX` escape
    // is non-canonical", which is what the original wording here implied.
    // PHP escapes U+2028/U+2029 even under `JSON_UNESCAPED_UNICODE`, so
    // the real encoder emits those two escaped and the guard RECOVERS
    // them. The guard's actual rule is narrower and is stated where it
    // belongs, on `recoverSealedPayloadFromFrozenBytes()` itself: byte
    // identity against this PHP re-encode, nothing more.
    // -----------------------------------------------------------------

    public function test_event_chain_verifier_does_not_claim_divergence_for_a_faithful_non_ascii_correction(): void
    {
        $nonAscii = $this->nonAsciiCorrectedPayload();
        $this->seedRecoverableSealedPayloadResolution($nonAscii, $nonAscii);
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $run = $this->runVerifierCapturingOutput();

        $this->assertSame(
            1,
            $run['exitCode'],
            'Recovering the sealed payload must never soften the verdict — the coordinate incident still fails the run.',
        );
        $this->assertStringContainsString(
            'sealed coordinates could not be derived from canonical_bytes',
            $run['output'],
            'The coordinate incident must survive — non-ASCII free text does not make unparseable bytes parseable.',
        );
        $this->assertStringNotContainsString(
            'canonical payload could not be derived',
            $run['output'],
            'Recovery must FIRE on canonical raw-UTF-8 bytes. This is the F-1 regression: a re-encode without JSON_UNESCAPED_UNICODE refuses every non-ASCII envelope.',
        );
        $this->assertStringNotContainsString(
            'payload does not semantically match canonical_bytes',
            $run['output'],
            'The sealed payload WAS recovered and it matches the stored payload; claiming divergence here is the false half of the ES-06 blind spot.',
        );
    }

    public function test_event_chain_verifier_names_a_divergent_correction_against_a_non_ascii_sealed_payload(): void
    {
        $this->seedRecoverableSealedPayloadResolution(
            $this->divergentNonAsciiCorrectedPayload(),
            $this->nonAsciiCorrectedPayload(),
        );
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $run = $this->runVerifierCapturingOutput();

        $this->assertSame(1, $run['exitCode']);
        $this->assertStringContainsString(
            'payload does not semantically match canonical_bytes — the sealed payload was recovered from the frozen envelope and disagrees with the stored payload',
            $run['output'],
            'A money rewrite on a non-ASCII receipt must be named as a divergence, not hidden behind the generic "could not be derived" sentence.',
        );
        $this->assertStringNotContainsString(
            'canonical payload could not be derived',
            $run['output'],
            'Recovery must reach the comparison — refusing here would leave the ES-06 attack indistinguishable from an ordinary parse failure.',
        );
    }

    public function test_sealed_payload_recovery_refuses_unicode_escaped_non_canonical_bytes(): void
    {
        // The SAME non-ASCII payload, sealed in a byte form the canonical encoder does not
        // produce for THESE code points: `\uXXXX` escapes of U+0080+ instead of raw UTF-8.
        // (F-7: written as a universal this would be false — PHP escapes U+2028/U+2029 even
        // under JSON_UNESCAPED_UNICODE, so the canonical encoder DOES emit those two escaped
        // and the guard recovers them. The fixture's characters are ordinary accented/Arabic
        // ones, for which the escape form is genuinely non-canonical.) Recovery must refuse — those bytes
        // are not canonical JSON, so nothing about them licenses a claim about the sealed
        // payload. This bounds the F-1 widening: adding JSON_UNESCAPED_UNICODE makes the
        // guard agree with the canonical encoder, it does not make it lenient.
        $nonAscii = $this->nonAsciiCorrectedPayload();
        $this->seedRecoverableSealedPayloadResolution($nonAscii, $nonAscii, escapeUnicodeInCanonicalBytes: true);
        $this->resolverUser->givePermissionTo('fiscal.events.verify_chain');

        $run = $this->runVerifierCapturingOutput();

        $this->assertSame(1, $run['exitCode']);
        $this->assertStringContainsString(
            'payload does not semantically match canonical_bytes — canonical payload could not be derived',
            $run['output'],
            'Non-canonical bytes must fall back to the fail-closed sentence; recovering from them would be a claim the bytes do not support.',
        );
        $this->assertStringNotContainsString(
            'the sealed payload was recovered from the frozen envelope',
            $run['output'],
            'Recovery must not fire on a byte form CanonicalJsonEncoder does not emit for these code points '
            .'(F-7: `\uXXXX` of U+0080+ — NOT a universal claim; U+2028/U+2029 stay escaped and ARE canonical).',
        );
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
            '--tenant' => $this->tenantId,
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
            '--tenant' => $this->tenantId,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--fiscal-event-id' => $event->id,
            '--tenant' => $this->tenantId,
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
            '--tenant' => $this->tenantId,
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
            '--tenant' => $this->tenantId,
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
            '--tenant' => $this->tenantId,
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
            '--tenant' => $this->tenantId,
            '--actor-id' => $this->resolverUser->id,
        ])->assertExitCode(0);

        // Actor B: in tenant B, lacks the permission. The command must
        // re-scope to tenant B and reject (exit 1). Without the fix the
        // result is sensitive to whatever team id was set before
        // invocation — either both succeed or both fail.
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--tenant' => $tenantB->id,
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
            '--tenant' => $this->tenantId,
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
    // Tenancy binding (cat-(b) wave 2, 2026-08-05)
    //
    // `--tenant` used to be an optional WHERE predicate on a command that
    // never left the console's CENTRAL connection. It is now REQUIRED and it
    // BINDS tenancy — without it there is no database in which to look for the
    // fiscal_events rows, the projection rows, or the actor.
    // =================================================================

    public function test_command_requires_the_tenant_option(): void
    {
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--actor-id' => $this->resolverUser->id,
        ])
            ->expectsOutputToContain('Missing --tenant option')
            ->assertExitCode(1);
    }

    public function test_command_fails_loudly_for_a_tenant_absent_from_the_directory(): void
    {
        $this->artisan('fiscal:enqueue-resolved-event-projections', [
            '--tenant' => Str::uuid()->toString(),
            '--actor-id' => $this->resolverUser->id,
        ])
            ->expectsOutputToContain('not found in the central tenant directory')
            ->doesntExpectOutputToContain('nothing to do')
            ->assertExitCode(1);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Seed T-a through the real parse-resolution path: the sealed event keeps
     * its canonical bytes/hash while its formerly failed payload is replaced.
     */
    private function seedPayloadRewriteTamper(): void
    {
        $event = $this->storeParseFailedFiscalEvent(eventVersion: 3);
        $canonicalBytesBefore = $this->stringifyCanonicalBytes($event->canonical_bytes);
        $currentHashBefore = (string) $event->current_hash;

        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $this->correctedPayloadV3(), $this->resolverUser);

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertNotNull($row->payload);
        $canonicalBytesAfter = $this->stringifyCanonicalBytes($row->canonical_bytes);
        $this->assertSame($canonicalBytesBefore, $canonicalBytesAfter);
        $this->assertSame($currentHashBefore, $row->current_hash);
        $this->assertSame(hash('sha256', $canonicalBytesAfter), $row->current_hash);

        /** @var array<string, mixed> $frozenPayload */
        $frozenPayload = json_decode($canonicalBytesAfter, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $correctedPayload */
        $correctedPayload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['a' => 2], $frozenPayload);
        $this->assertSame('10.02', $correctedPayload['subtotal']);
        $this->assertSame('-0.02', $correctedPayload['cash_rounding_adjustment']);
        $this->assertSame('0.05', $correctedPayload['cash_rounding_denomination']);
        $this->assertSame('prod-default', $correctedPayload['line_items'][0]['product_id']);
        $this->assertArrayNotHasKey('a', $correctedPayload);
        $this->assertNotSame($frozenPayload, $correctedPayload);
    }

    /**
     * Insert a quarantined `canonical_parse_failure` event into
     * `fiscal_events`. payload is NULL, payload_parse_status is 'failed',
     * integrity_status is 'quarantined' — the exact preconditions the Task
     * 8 trigger's gated `failed → parsed` resume requires.
     */
    /**
     * ES-06 (M3) fixture — the RECOVERABLE-sealed-payload shape, driven end
     * to end through the production resolution path.
     *
     * Difference from `seedPayloadRewriteTamper()` (M0's T-a): there the
     * frozen bytes are `{"a":1,"a":2}` — not an envelope at all, so nothing
     * about the sealed payload can ever be recovered and the verifier can
     * only re-report the parse failure. Here the frozen bytes are a
     * well-formed canonical envelope carrying the device's real sealed
     * payload, rejected by the strict parser for exactly ONE reason: an
     * unknown envelope-level field. That is the realistic
     * `canonical_parse_failure` (a firmware adds a field the server grammar
     * does not know) and it is the shape on which divergence is genuinely
     * decidable.
     *
     * The helper asserts its own shape so a later "red" run cannot be red
     * for the wrong reason (M0 toolkit rule).
     *
     * @param  array<string, mixed>  $correctedPayload  what the operator supplies to the resolver
     * @param  array<string, mixed>|null  $sealedPayload  what the device sealed; defaults to the faithful ASCII payload
     * @param  bool  $escapeUnicodeInCanonicalBytes  seal a NON-canonical byte form (`\uXXXX` escapes)
     *                                               instead of the RFC 8785 raw-UTF-8 form
     * @return array{event: FiscalEvent, sealed: array<string, mixed>}
     */
    private function seedRecoverableSealedPayloadResolution(
        array $correctedPayload,
        ?array $sealedPayload = null,
        bool $escapeUnicodeInCanonicalBytes = false,
    ): array {
        $sealedPayload ??= $this->correctedPayload();
        $event = $this->storeSealedEnvelopeParseFailure($sealedPayload, $escapeUnicodeInCanonicalBytes);
        $canonicalBytesBefore = $this->stringifyCanonicalBytes($event->canonical_bytes);
        $currentHashBefore = (string) $event->current_hash;

        // (a) The strict parser rejects these bytes — this really is a
        //     canonical_parse_failure row, not a clean row in disguise — and
        //     it rejects them for exactly the one envelope-level reason.
        $parse = $this->app->make(StrictCanonicalParser::class)
            ->parse($canonicalBytesBefore, FiscalEventType::SALE_RECEIPT);
        $this->assertFalse($parse->ok);
        $this->assertSame('envelope_extra_field:device_firmware_note', $parse->failureReason);
        $this->assertNull($parse->payload);

        // (b) ... yet the sealed payload IS recoverable from the frozen bytes
        //     at the JSON level. This is the fact the detection stands on.
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($canonicalBytesBefore, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('payload', $envelope);
        $this->assertEqualsCanonicalizing(array_keys($sealedPayload), array_keys($envelope['payload']));
        $this->assertEquals($sealedPayload, $envelope['payload']);

        // (c) Drive the REAL production path — no hand-written UPDATE.
        $this->app->make(ParseFailureResolutionService::class)
            ->resolve($event->id, $correctedPayload, $this->resolverUser);

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertNotNull($row->payload);

        // (d) The seal held: bytes and hash are byte-identical afterwards, so
        //     any divergence is between the payload and the SEALED bytes and
        //     cannot be blamed on a rewritten chain coordinate.
        $canonicalBytesAfter = $this->stringifyCanonicalBytes($row->canonical_bytes);
        $this->assertSame($canonicalBytesBefore, $canonicalBytesAfter);
        $this->assertSame($currentHashBefore, $row->current_hash);
        $this->assertSame(hash('sha256', $canonicalBytesAfter), $row->current_hash);

        /** @var array<string, mixed> $storedPayload */
        $storedPayload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($correctedPayload, $storedPayload);

        return ['event' => $event->refresh(), 'sealed' => $sealedPayload];
    }

    /**
     * A quarantined `canonical_parse_failure` row whose frozen bytes are a
     * WELL-FORMED canonical envelope (all 15 `ENVELOPE_KEYS`, carrying the
     * sealed payload) plus one unknown device field — the single grammar
     * violation that quarantined it.
     *
     * @param  array<string, mixed>  $sealedPayload
     * @param  bool  $escapeUnicode  when true the frozen bytes carry `\uXXXX` escapes — a byte form
     *                               `CanonicalJsonEncoder` does not emit for U+0080+ (RFC 8785
     *                               §3.2.3 seals those raw), used to prove recovery REFUSES
     *                               non-canonical bytes. F-7: NOT a universal — PHP escapes
     *                               U+2028/U+2029 even under JSON_UNESCAPED_UNICODE, so those two
     *                               escapes ARE canonical and the guard recovers them.
     */
    private function storeSealedEnvelopeParseFailure(array $sealedPayload, bool $escapeUnicode = false): FiscalEvent
    {
        $sequenceNumber = $this->nextSequenceFor($this->terminalId);
        $eventTimeDevice = '2026-05-20T14:30:00Z';
        $businessDate = '2026-05-20';

        $fields = [
            'business_date' => $businessDate,
            'chain_context' => 'operational',
            'company_id' => $this->companyId,
            'event_time_device' => $eventTimeDevice,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $sealedPayload,
            'previous_hash' => $this->genesisSeed,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
            // THE single grammar violation. Everything else in the envelope
            // is exactly what the server grammar expects.
            'device_firmware_note' => 'fw-2.7.1',
        ];
        ksort($fields);

        // Production canonical bytes are RFC 8785: `CanonicalJsonEncoder::encodeString()`
        // (`CanonicalJsonEncoder.php:106-108`) emits raw UTF-8 for U+0080+ via
        // `JSON_UNESCAPED_UNICODE`. The fixture must seal the SAME byte form or it is
        // not modelling a device envelope at all. `$escapeUnicode` deliberately seals the
        // wrong form so the recovery guard can be shown to refuse it.
        $canonicalBytes = json_encode(
            $fields,
            $escapeUnicode
                ? JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                : JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTimeDevice,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => now()->utc(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->byteaBinding($canonicalBytes),
            'previous_hash' => $this->genesisSeed,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Quarantined,
            'integrity_exception_class' => IntegrityExceptionClass::CanonicalParseFailure->value,
            'integrity_exception_reason' => 'envelope_extra_field:device_firmware_note',
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Failed,
        ]);

        return $event->refresh();
    }

    /**
     * The sealed payload with every money field rewritten 10.00 -> 25.00,
     * consistently, so the correction passes the resolver's DTO + per-event
     * constraint validation and the ONLY thing wrong with it is that it is
     * not what the device sealed. This is the ES-06 attack in its most
     * consequential form: the projectors read `payload`, so the receipt the
     * business sees becomes 25.00 while the frozen bytes say 10.00.
     *
     * @return array<string, mixed>
     */
    private function divergentCorrectedPayload(): array
    {
        $payload = $this->correctedPayload();

        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['line_subtotal'] = '25.00';
        $lines[0]['unit_price'] = '25.00';
        $payload['line_items'] = $lines;

        /** @var list<array<string, mixed>> $payments */
        $payments = $payload['payments'];
        $payments[0]['amount'] = '25.00';
        $payload['payments'] = $payments;

        $payload['subtotal'] = '25.00';
        $payload['total'] = '25.00';
        $payload['vat_breakdown'] = [[
            'gross_amount' => '25.00',
            'net_amount' => '25.00',
            'rate' => '0.00',
            'tax_category_code' => 'Z',
            'vat_amount' => '0.00',
        ]];

        return $payload;
    }

    /**
     * The faithful sealed payload with its FREE-TEXT fields carrying non-ASCII characters —
     * a French product/cashier name and an Arabic product name.
     *
     * This is not an exotic case: the wave's target countries are France and Tunisia, and the
     * canonical SALE_RECEIPT grammar carries operator-typed free text in exactly these fields
     * (`FiscalPayloadConstraintValidator.php` `line_items[].name`, `cashier_name`,
     * `seller.name`, `seller.address.*`). RFC 8785 seals them as raw UTF-8
     * (`CanonicalJsonEncoder.php:106-108`), so a recovery guard whose re-encode escapes
     * non-ASCII can never match the frozen bytes for any such receipt.
     *
     * @return array<string, mixed>
     */
    private function nonAsciiCorrectedPayload(): array
    {
        $payload = $this->correctedPayload();

        $payload['cashier_name'] = 'Amélie Dupont';

        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['name'] = 'Café crème — قهوة عربية';
        $payload['line_items'] = $lines;

        /** @var array<string, mixed> $seller */
        $seller = $payload['seller'];
        $seller['name'] = 'Boulangerie Crémerie S.à.r.l.';
        /** @var array<string, mixed> $address */
        $address = $seller['address'];
        $address['street'] = "12 rue de l'Église";
        $seller['address'] = $address;
        $payload['seller'] = $seller;

        return $payload;
    }

    /**
     * The non-ASCII sealed payload with every money field rewritten 10.00 -> 25.00 — the ES-06
     * attack carried on a receipt whose free text is non-ASCII. Recovery must still FIRE here
     * (the bytes are canonical) and the comparison must still name the divergence.
     *
     * @return array<string, mixed>
     */
    private function divergentNonAsciiCorrectedPayload(): array
    {
        $payload = $this->divergentCorrectedPayload();
        $nonAscii = $this->nonAsciiCorrectedPayload();

        $payload['cashier_name'] = $nonAscii['cashier_name'];
        $payload['seller'] = $nonAscii['seller'];

        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        /** @var list<array<string, mixed>> $nonAsciiLines */
        $nonAsciiLines = $nonAscii['line_items'];
        $lines[0]['name'] = $nonAsciiLines[0]['name'];
        $payload['line_items'] = $lines;

        return $payload;
    }

    /**
     * Bind a `canonical_bytes` value in a way that survives ANY byte.
     *
     * `canonical_bytes` is `bytea` on PostgreSQL (`\d fiscal_events`) and `blob` on SQLite.
     * `Illuminate\Database\Connection::bindValues()` binds a plain PHP string as
     * `PDO::PARAM_STR` and only a **resource** as `PDO::PARAM_LOB`. A `PARAM_STR` bind is
     * transmitted as a text literal, so PostgreSQL parses it with the bytea *escape* input
     * rules — and every backslash that is not `\\` or `\NNN` dies with
     * `SQLSTATE[22P02] invalid input syntax for type bytea`. Canonical JSON carrying
     * `\uXXXX` escapes (or an escaped quote inside free text) is exactly that shape.
     *
     * Binding a stream instead routes the value through `PDO::PARAM_LOB`, which is sent as
     * binary and round-trips byte-identically on both drivers. This is a TEST-harness
     * concern only — nothing about the production write path changes here.
     *
     * @return resource
     */
    private function byteaBinding(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            self::fail('Unable to open an in-memory stream for the canonical_bytes binding.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Run the chain verifier and return its exit code plus rendered output,
     * so a test can assert on the ABSENCE of a claim
     * (`expectsOutputToContain` can only assert presence).
     *
     * @return array{exitCode: int, output: string}
     */
    private function runVerifierCapturingOutput(): array
    {
        $exitCode = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->resolverUser->id,
        ]);

        return ['exitCode' => $exitCode, 'output' => Artisan::output()];
    }

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
                $app->make(CompanyContext::class),
                $app->make(DatabaseManager::class),
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
