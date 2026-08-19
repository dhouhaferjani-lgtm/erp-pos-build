<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `GET /fiscal/dead-lettered-projections` + `GET /fiscal/dead-lettered-
 * projections/{fiscal_event_id}` — v3-refund-chain-integration spec §5.1/
 * §5.2. Read-only operator visibility (list + detail), unchanged from
 * Revision 3 — the write-off ACTION itself lives at the separate,
 * re-keyed `POST /fiscal/refund-compensations` endpoint (§5.2's ⚖️
 * ruling), never nested under this controller.
 *
 * **Three addressable classes, all keyed by `fiscal_events.id` (§5.2's
 * gap-(2) closure; the third added by ES-16, below):**
 *   - `dead_lettered_projection` — a `fiscal_event_projections` row with
 *     `projection_status = DeadLettered`. Filterable by `projector_name`
 *     (`?projector=pos_core_receipt` / `?projector=treasury_receipt_bridge`
 *     — "POS-applied/Treasury-dead-lettered").
 *   - `ingress_quarantine` — a `fiscal_events` row whose payload never
 *     parsed (`payload_parse_status = failed`,
 *     `integrity_exception_class = canonical_parse_failure`, the SAME
 *     partition `QuarantineBestEffortParseController` reads) and which
 *     therefore has NO `fiscal_event_projections` row at all — unlike the
 *     sequence_conflict `fiscal_event_quarantine` TABLE (a structurally
 *     different, pre-`fiscal_events`-insertion rejection class with no
 *     `fiscal_events.id` to key by at all), this class always has a
 *     stable `fiscal_events.id`.
 *   - `z_session_lifecycle_quarantine` — **ES-16.** A `fiscal_events` row
 *     that tripped one of the seven `z_session_lifecycle` rules. Its
 *     `integrity_exception_class` is `sequence_gap` (the lifecycle verdict
 *     is folded into the linkage verdict at `OutboxIngestor.php:182-186`,
 *     so the discriminator survives only inside
 *     `integrity_exception_reason`), and `dispatchProjections()` suppresses
 *     every projection for it at `OutboxIngestor.php:922-924`. It therefore
 *     landed in NEITHER class above — zero projection rows for the first,
 *     wrong exception class for the second — while a suppressed
 *     `SESSION_CLOSE` / `Z_REPORT` means the day's Z aggregates silently
 *     never project. This class is READ-ONLY VISIBILITY: it surfaces the
 *     incident and names no action (see `formatZSessionLifecycleRow()`).
 *     The suppression itself is CORRECT and is not touched — projecting a
 *     lifecycle-invalid Z session would write wrong aggregates.
 *
 * Rows of the first two classes surface `write_off_action_url` — the URL of
 * the write-off endpoint already built behind this read surface — so an
 * operator UI never has to hardcode it. The ES-16 class deliberately does
 * NOT: see `formatZSessionLifecycleRow()` for why naming any action on such
 * a row is the failure mode, not the feature.
 *
 * **review round-2 IMPORTANT 14 (§5.2 evidence (a)).** Both row formats
 * ALSO surface `shift_id` and `refund_amount`, read from the event's own
 * parsed payload (`shift_id`, `payments[0].amount` — the same cash-tender
 * leg `RefundCompensationService` books, per review round-2 IMPORTANT 9).
 * On an `ingress_quarantine` row the payload never parsed, so both are
 * best-effort null when unavailable rather than a hard failure.
 */
final class DeadLetteredProjectionsController extends Controller
{
    /**
     * The verdict prefix `OutboxIngestor::verifyZSessionLifecycle()` emits and
     * `OutboxIngestor::dispatchProjections()` suppresses on
     * (`OutboxIngestor.php:922-924`,
     * `str_contains($exceptionReason, 'z_session_lifecycle:')`).
     *
     * The ES-16 partition below matches on exactly this prefix, so the rows it
     * surfaces are BY CONSTRUCTION the rows the ingestor's own suppression
     * hid — not an approximation of them.
     */
    private const Z_SESSION_LIFECYCLE_PREFIX = 'z_session_lifecycle:';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authenticated user could not be resolved.'],
            ], 401);
        }

        $projectorFilter = $request->query('projector');
        $projectorFilter = is_string($projectorFilter) && $projectorFilter !== '' ? $projectorFilter : null;

        $rows = [];

        $deadLetteredQuery = DB::table('fiscal_event_projections')
            ->join('fiscal_events', 'fiscal_events.id', '=', 'fiscal_event_projections.fiscal_event_id')
            ->where('fiscal_events.tenant_id', $user->tenant_id)
            ->where('fiscal_event_projections.projection_status', ProjectionStatus::DeadLettered->value);

        if ($projectorFilter !== null) {
            $deadLetteredQuery->where('fiscal_event_projections.projector_name', $projectorFilter);
        }

        foreach ($deadLetteredQuery
            ->orderByDesc('fiscal_event_projections.dead_lettered_at')
            ->get([
                'fiscal_events.id as fiscal_event_id',
                'fiscal_events.event_type',
                'fiscal_events.terminal_id',
                'fiscal_events.payload',
                'fiscal_event_projections.projector_name',
                'fiscal_event_projections.attempts',
                'fiscal_event_projections.last_error',
                'fiscal_event_projections.dead_lettered_at',
            ]) as $row) {
            $payload = $this->decodePayload($row->payload);
            $rows[] = $this->formatDeadLetteredRow(
                fiscalEventId: (string) $row->fiscal_event_id,
                eventType: (string) $row->event_type,
                terminalId: (string) $row->terminal_id,
                projectorName: (string) $row->projector_name,
                attempts: (int) $row->attempts,
                lastError: $row->last_error === null ? null : (string) $row->last_error,
                deadLetteredAt: $row->dead_lettered_at === null ? null : (string) $row->dead_lettered_at,
                shiftId: $this->extractShiftId($payload),
                refundAmount: $this->extractRefundAmount($payload),
            );
        }

        // ingress-quarantine — only when NOT projector-filtered (this class
        // has no projector_name at all, so a filtered query would never
        // legitimately include it).
        if ($projectorFilter === null) {
            $quarantined = FiscalEvent::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('integrity_exception_class', 'canonical_parse_failure')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('fiscal_event_projections')
                        ->whereColumn('fiscal_event_projections.fiscal_event_id', 'fiscal_events.id');
                })
                ->orderByDesc('server_received_at')
                ->get(['id', 'event_type', 'terminal_id', 'server_received_at', 'integrity_exception_reason', 'payload']);

            foreach ($quarantined as $event) {
                $rows[] = $this->formatQuarantineRow($event);
            }

            // ES-16 — the z_session_lifecycle blind spot. Same reasoning as
            // the ingress-quarantine class above: these rows have no
            // projector_name at all, so a projector-filtered query would
            // never legitimately include them.
            $lifecycleQuarantined = FiscalEvent::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('integrity_exception_reason', 'like', '%'.self::Z_SESSION_LIFECYCLE_PREFIX.'%')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('fiscal_event_projections')
                        ->whereColumn('fiscal_event_projections.fiscal_event_id', 'fiscal_events.id');
                })
                ->orderByDesc('server_received_at')
                ->get([
                    'id', 'event_type', 'terminal_id', 'chain_context', 'server_received_at',
                    'integrity_exception_class', 'integrity_exception_reason', 'payload',
                ]);

            foreach ($lifecycleQuarantined as $event) {
                $rows[] = $this->formatZSessionLifecycleRow($event);
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $fiscalEventId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authenticated user could not be resolved.'],
            ], 401);
        }

        /** @var FiscalEvent|null $event */
        $event = FiscalEvent::query()
            ->where('id', $fiscalEventId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if ($event === null) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'No fiscal event was found for this tenant.'],
            ], 404);
        }

        $projectionRow = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->where('projection_status', ProjectionStatus::DeadLettered->value)
            ->first();

        if ($projectionRow !== null) {
            $payload = is_array($event->payload) ? $event->payload : null;

            return response()->json([
                'data' => $this->formatDeadLetteredRow(
                    fiscalEventId: (string) $event->id,
                    eventType: $event->event_type->value,
                    terminalId: (string) $event->terminal_id,
                    projectorName: (string) $projectionRow->projector_name,
                    attempts: (int) $projectionRow->attempts,
                    lastError: $projectionRow->last_error === null ? null : (string) $projectionRow->last_error,
                    deadLetteredAt: $projectionRow->dead_lettered_at === null ? null : (string) $projectionRow->dead_lettered_at,
                    shiftId: $this->extractShiftId($payload),
                    refundAmount: $this->extractRefundAmount($payload),
                ),
            ]);
        }

        if ($event->integrity_exception_class === 'canonical_parse_failure') {
            return response()->json([
                'data' => $this->formatQuarantineRow($event),
            ]);
        }

        return response()->json([
            'error' => [
                'code' => 'NOT_DEAD_LETTERED',
                'message' => 'This fiscal event is neither dead-lettered nor ingress-quarantined.',
            ],
        ], 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDeadLetteredRow(
        string $fiscalEventId,
        string $eventType,
        string $terminalId,
        string $projectorName,
        int $attempts,
        ?string $lastError,
        ?string $deadLetteredAt,
        ?string $shiftId,
        ?string $refundAmount,
    ): array {
        return [
            'source' => 'dead_lettered_projection',
            'fiscal_event_id' => $fiscalEventId,
            'event_type' => $eventType,
            'terminal_id' => $terminalId,
            'projector_name' => $projectorName,
            'attempts' => $attempts,
            'last_error' => $lastError,
            'dead_lettered_at' => $deadLetteredAt,
            'shift_id' => $shiftId,
            'refund_amount' => $refundAmount,
            'write_off_action_url' => route('fiscal.refund-compensations.store'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQuarantineRow(FiscalEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : null;

        return [
            'source' => 'ingress_quarantine',
            'fiscal_event_id' => $event->id,
            'event_type' => $event->event_type->value,
            'terminal_id' => $event->terminal_id,
            'projector_name' => null,
            'integrity_exception_reason' => $event->integrity_exception_reason,
            'server_received_at' => $event->server_received_at->toIso8601String(),
            'shift_id' => $this->extractShiftId($payload),
            'refund_amount' => $this->extractRefundAmount($payload),
            'write_off_action_url' => route('fiscal.refund-compensations.store'),
        ];
    }

    /**
     * ES-16 — a `z_session_lifecycle`-quarantined `fiscal_events` row.
     *
     * **This row deliberately carries NO action affordance** — no
     * `write_off_action_url`, no remediation/recovery/next-action field, no
     * command name anywhere (contract clause 16-C as amended in revision 2,
     * falsifier F16-7). It reports the incident and stops.
     *
     * The reason is not squeamishness about UI copy. The one command that
     * would act on such a row, `fiscal:enqueue-resolved-event-projections`,
     * filters only on `payload_parse_status = parsed`
     * (`EnqueueResolvedEventProjectionsCommand.php:244-245`) and its
     * `createMissingPendingRows()` (`:340-365`) inserts a pending row per
     * active projector without re-checking the suppression at
     * `OutboxIngestor.php:922-924`. Running it here would create precisely the
     * projections the ingestor refused — i.e. write the wrong Z aggregates.
     * An operator who can SEE the row can escalate; an operator who is told to
     * run the command corrupts the day's Z totals. Adjudicating the underlying
     * lifecycle violation is a decision on sealed data (owner gate D-8) and is
     * not in this wave.
     *
     * `refund_amount` and `write_off_action_url` are omitted for the same
     * reason they exist on the other two formats: they belong to the refund
     * write-off surface, and a lifecycle-invalid Z session has no write-off.
     *
     * @return array<string, mixed>
     */
    private function formatZSessionLifecycleRow(FiscalEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : null;

        return [
            'source' => 'z_session_lifecycle_quarantine',
            'fiscal_event_id' => $event->id,
            'event_type' => $event->event_type->value,
            'terminal_id' => $event->terminal_id,
            'chain_context' => $event->chain_context,
            'projector_name' => null,
            'integrity_exception_class' => $event->integrity_exception_class,
            'integrity_exception_reason' => $event->integrity_exception_reason,
            'lifecycle_violation' => $this->extractLifecycleViolation($event->integrity_exception_reason),
            'server_received_at' => $event->server_received_at->toIso8601String(),
            'session_id' => $this->extractSessionId($payload),
            'shift_id' => $this->extractShiftId($payload),
        ];
    }

    /**
     * Pull the `z_session_lifecycle:<reason>` discriminator out of the
     * free-form `integrity_exception_reason` (contract clause 16-B).
     *
     * The column is a `;`-joined list of verdicts, and the lifecycle verdict
     * itself may be `|`-appended to a linkage verdict when BOTH fired
     * (`OutboxIngestor.php:182-186` — e.g.
     * `sequence_gap:no_prior_row_but_sequence=2_must_be_1|z_session_lifecycle:missing_session_open`).
     * So the discriminator is read out of the string rather than assumed to be
     * the whole of it, and the full reason is surfaced alongside it — this is
     * an extraction, never a replacement.
     *
     * Returns null rather than guessing if the prefix is present but no
     * recognisable reason token follows it: a wrong violation name is worse
     * than an absent one when the operator is deciding what to escalate.
     */
    private function extractLifecycleViolation(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $matched = preg_match(
            '/'.preg_quote(self::Z_SESSION_LIFECYCLE_PREFIX, '/').'([a-z0-9_]+)/',
            $reason,
            $matches,
        );

        return $matched === 1 ? self::Z_SESSION_LIFECYCLE_PREFIX.$matches[1] : null;
    }

    /**
     * The Z session the suppressed event belongs to — the coordinate an
     * operator needs to find the session whose aggregates never projected.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function extractSessionId(?array $payload): ?string
    {
        $sessionId = $payload['session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * The raw dead-lettered index query reads `fiscal_events.payload` via
     * `DB::table()` (not the Eloquent model, so no automatic JSON cast).
     * PG returns a JSONB column as a string through the raw query builder;
     * decode it defensively -- a row whose payload never parsed at all
     * (mid-ingestion column state) yields null rather than throwing.
     *
     * @return array<string, mixed>|null
     */
    private function decodePayload(mixed $rawPayload): ?array
    {
        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        if (! is_string($rawPayload) || $rawPayload === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractShiftId(?array $payload): ?string
    {
        $shiftId = $payload['shift_id'] ?? null;

        return is_string($shiftId) && $shiftId !== '' ? $shiftId : null;
    }

    /**
     * Mirrors `RefundCompensationService`'s own amount source (review
     * round-2 IMPORTANT 9) — the cash TENDER leg (`payments[0].amount`),
     * not `payload.total`, so an operator glancing at this list sees the
     * exact amount the write-off action would book.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function extractRefundAmount(?array $payload): ?string
    {
        $payments = $payload['payments'] ?? null;
        if (! is_array($payments) || ! isset($payments[0]) || ! is_array($payments[0])) {
            return null;
        }

        $amount = $payments[0]['amount'] ?? null;

        return is_string($amount) && $amount !== '' ? $amount : null;
    }
}
