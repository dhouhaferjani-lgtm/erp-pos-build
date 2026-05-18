<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use App\Modules\Fiscal\Domain\Exceptions\InvalidCorrectedPayloadException;
use App\Modules\Fiscal\Domain\Exceptions\ParseFailureResolutionPreconditionException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atomic parse-failure resolution — spec v7 §7.5 + §15.2 + Task 24 plan.
 *
 * Resolves a quarantined `canonical_parse_failure` `fiscal_events` row in
 * ONE database transaction so the §3.3 write-once `payload` hazard cannot
 * strand the row. The transaction does ALL of:
 *
 *   1. write the corrected `payload` (was NULL)
 *   2. flip `payload_parse_status` `failed → parsed`
 *   3. flip `integrity_status` `quarantined → verified`
 *   4. stamp `integrity_resolved_at` + `integrity_resolved_by`
 *   5. insert one `pending` `fiscal_event_projections` row per currently-
 *      active projector (via `FiscalEventProjectionRegistry::activeProjectorsFor`)
 *
 * The Task 8 immutability trigger gates steps 1–4 atomically — see
 * `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php`
 * lines 154–177 ("the gated `failed → parsed` resume transition") and
 * lines 215–230 (the matching atomic-resolution gate from the
 * `integrity_status` side). If any one of those columns is missed in the
 * same UPDATE, the trigger raises an `integrity_constraint_violation` —
 * the service builds the full update bundle so PG accepts it.
 *
 * After the transaction commits, one `ApplyFiscalEventProjectionJob` is
 * dispatched per pending projection row, via `DB::afterCommit()` so a
 * roll-back of T1 produces no spurious jobs. Mirrors the
 * `OutboxIngestor::dispatchProjections` pattern (Task 19 standing
 * pattern F4 round-2).
 *
 * **Why `integrity_exception_class` stays at `canonical_parse_failure`.**
 * Per the Task 8 trigger source (lines 128–136), this column is write-once:
 * once set on a quarantined row it cannot be changed nor unset. The class
 * is forensic metadata that explains WHY the row was originally
 * quarantined; the resolved-at/by stamps + `integrity_status='verified'`
 * tell the operator the row was subsequently resolved. The trigger's own
 * docblock makes this explicit: "the class is forensic metadata: it
 * describes WHY the row was quarantined and that fact is permanent (spec
 * §3.3, §7.5)".
 *
 * **Why the corrected payload is validated against the event-type DTO
 * (not against new canonical_bytes).** Per spec §3.3 + the Task 8
 * trigger's frozen-column whitelist (lines 73–106), `canonical_bytes` is
 * IMMUTABLE — the device's authoritative bytes are chain truth and cannot
 * be rewritten. So the resolver cannot re-derive `payload` via
 * `StrictCanonicalParser::parse($newBytes, $type)`. Instead, it validates
 * the operator-supplied corrected `payload` array directly against the
 * event-type DTO (the same schema gate StrictCanonicalParser delegates to
 * after envelope-shape validation). The DTO's `fromArray()` throw on
 * mismatch is rewrapped as `InvalidCorrectedPayloadException` so the
 * caller gets a typed boundary error.
 *
 * **Crash recovery.** A crash AFTER T1 commits but BEFORE the after-commit
 * enqueue runs leaves `payload_parse_status='parsed'` + pending projection
 * rows that nothing is dispatching. The named recovery path is
 * `fiscal:enqueue-resolved-event-projections` (spec §15.2) —
 * idempotently runnable, safe to invoke any number of times.
 */
final class ParseFailureResolutionService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalEventPayloadRegistry $payloadRegistry,
        private readonly FiscalEventProjectionRegistry $projectionRegistry,
    ) {}

    /**
     * Atomically resolve a quarantined `canonical_parse_failure` event.
     *
     * @param  string  $fiscalEventId  the target row's UUID
     * @param  array<string, mixed>  $correctedPayload  operator-supplied
     *                                                  structured payload — validated against the event-type DTO
     *                                                  before the UPDATE is attempted
     * @param  User  $resolverUser  authenticated user performing the
     *                              resolution; their id is stamped on `integrity_resolved_by`
     *
     * @throws ParseFailureResolutionPreconditionException when the row does not
     *                                                     satisfy the resume preconditions (missing, not failed, not
     *                                                     quarantined, not canonical_parse_failure, payload not NULL)
     * @throws InvalidCorrectedPayloadException when the supplied corrected
     *                                          payload fails event-type schema validation
     * @throws FiscalEventTypeNotImplemented when the event type has no DTO
     *                                       in the Phase 1 registry (propagated from `dtoClassFor`)
     */
    public function resolve(string $fiscalEventId, array $correctedPayload, User $resolverUser): void
    {
        // Capture row ids dispatched after commit. The closure is `static`
        // (no `$this` capture) so the queue payload doesn't drag the
        // service instance in — mirror of the OutboxIngestor pattern.
        $pendingRowIds = [];

        $this->db->transaction(function () use ($fiscalEventId, $correctedPayload, $resolverUser, &$pendingRowIds): void {
            // ---- Step 1: load + lock the target row ----
            // `lockForUpdate` serializes concurrent resolution attempts on
            // the same row — without it, two operators racing on the same
            // quarantined event could both pass the precondition check
            // and the second UPDATE would raise the PG payload-write-once
            // trigger.
            $event = FiscalEvent::query()
                ->lockForUpdate()
                ->find($fiscalEventId);

            if ($event === null) {
                throw new ParseFailureResolutionPreconditionException(sprintf(
                    'fiscal_events row %s not found.',
                    $fiscalEventId,
                ));
            }

            $this->assertResumePreconditions($event);

            // ---- Step 2: validate corrected payload against the
            // event-type DTO (the same schema gate StrictCanonicalParser
            // delegates to). Failure rewraps as a typed boundary error;
            // the transaction rolls back so the row stays parse-failed.
            $this->assertPayloadValidatesAgainstDto($event, $correctedPayload);

            // ---- Step 3: build the atomic resolution UPDATE bundle
            // exactly as the Task 8 trigger requires (lines 154–230). All
            // four columns + the two stamps in one UPDATE.
            $resolvedAt = Carbon::now('UTC');

            DB::table('fiscal_events')
                ->where('id', $event->id)
                ->update([
                    'payload' => json_encode(
                        $correctedPayload,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                    'payload_parse_status' => PayloadParseStatus::Parsed->value,
                    'integrity_status' => IntegrityStatus::Verified->value,
                    'integrity_resolved_at' => $resolvedAt,
                    'integrity_resolved_by' => $resolverUser->id,
                    // integrity_exception_class STAYS at
                    // 'canonical_parse_failure' — write-once forensic
                    // metadata per the Task 8 trigger lines 128–136.
                ]);

            // Refresh model state so registry resolution sees the new
            // integrity_status / payload_parse_status / payload values.
            // The registry's `activeProjectorsFor()` only consults
            // event_type + tenant_id + company_id, but freshness is the
            // safer default — the cost is one round trip.
            $event->refresh();

            // ---- Step 4: insert one pending fiscal_event_projections
            // row per currently-active projector. Mirrors
            // `OutboxIngestor::dispatchProjections` — same registry call,
            // same row shape, same UNIQUE constraint.
            $activeProjectors = $this->projectionRegistry->activeProjectorsFor($event);

            $now = Carbon::now('UTC')->toDateTimeString();
            $pendingRows = [];
            foreach ($activeProjectors as $projector) {
                $pendingRows[] = [
                    'id' => $rowId = (string) Str::uuid(),
                    'fiscal_event_id' => $event->id,
                    'projector_name' => $projector->name(),
                    'projection_status' => ProjectionStatus::Pending->value,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $pendingRowIds[] = $rowId;
            }

            if ($pendingRows !== []) {
                // The UNIQUE (fiscal_event_id, projector_name) constraint
                // is the idempotency key. Within a single transaction
                // this is just an INSERT; the same-row idempotency check
                // is the named-command recovery path
                // (`EnqueueResolvedEventProjectionsCommand`).
                $this->db->table('fiscal_event_projections')->insert($pendingRows);
            }
        });

        // ---- Step 5: dispatch jobs AFTER commit. The closure is `static`
        // so the serialized queue payload doesn't drag the service
        // instance in. `DB::afterCommit()` honors the surrounding
        // transaction's rollback — jobs only fire if T1 actually
        // committed. Mirrors OutboxIngestor F4 round-2.
        if ($pendingRowIds === []) {
            return;
        }

        $rowsForDispatch = $pendingRowIds;
        DB::afterCommit(static function () use ($rowsForDispatch): void {
            foreach ($rowsForDispatch as $rowId) {
                ApplyFiscalEventProjectionJob::dispatch($rowId);
            }
        });
    }

    /**
     * Assert the row satisfies the resume preconditions. Raises a typed
     * boundary error so the caller sees a single failure mode instead of
     * a raw PG `integrity_constraint_violation` from the trigger.
     */
    private function assertResumePreconditions(FiscalEvent $event): void
    {
        if ($event->payload_parse_status !== PayloadParseStatus::Failed) {
            throw new ParseFailureResolutionPreconditionException(sprintf(
                'fiscal_events row %s: payload_parse_status is %s, expected failed.',
                $event->id,
                $event->payload_parse_status->value,
            ));
        }
        if ($event->payload !== null) {
            throw new ParseFailureResolutionPreconditionException(sprintf(
                'fiscal_events row %s: payload is not NULL (write-once column already set).',
                $event->id,
            ));
        }
        if ($event->integrity_status !== IntegrityStatus::Quarantined) {
            throw new ParseFailureResolutionPreconditionException(sprintf(
                'fiscal_events row %s: integrity_status is %s, expected quarantined.',
                $event->id,
                $event->integrity_status->value,
            ));
        }
        if ($event->integrity_exception_class !== IntegrityExceptionClass::CanonicalParseFailure->value) {
            throw new ParseFailureResolutionPreconditionException(sprintf(
                'fiscal_events row %s: integrity_exception_class is %s, expected canonical_parse_failure.',
                $event->id,
                $event->integrity_exception_class ?? 'NULL',
            ));
        }
    }

    /**
     * Validate the corrected payload against the event-type DTO. The DTO's
     * `fromArray()` is the same schema gate StrictCanonicalParser delegates
     * to (post envelope-shape validation), so the resolved row's payload
     * meets the same grammar bar as a payload that came in cleanly at
     * ingest time. A throw rewraps as `InvalidCorrectedPayloadException`.
     *
     * @param  array<string, mixed>  $correctedPayload
     */
    private function assertPayloadValidatesAgainstDto(FiscalEvent $event, array $correctedPayload): void
    {
        $dtoClass = $this->payloadRegistry->dtoClassFor($event->event_type);

        try {
            $dtoClass::fromArray($correctedPayload);
        } catch (Throwable $e) {
            throw new InvalidCorrectedPayloadException(
                sprintf(
                    'Corrected payload failed %s schema validation: %s',
                    $event->event_type->value,
                    $e->getMessage(),
                ),
                $e,
            );
        }
    }
}
