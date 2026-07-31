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
 * **Two addressable classes, both keyed by `fiscal_events.id` (§5.2's
 * gap-(2) closure):**
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
 *
 * Each list/detail row surfaces `write_off_action_url` — the URL of the
 * write-off endpoint already built behind this read surface — so an
 * operator UI never has to hardcode it.
 */
final class DeadLetteredProjectionsController extends Controller
{
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
                'fiscal_event_projections.projector_name',
                'fiscal_event_projections.attempts',
                'fiscal_event_projections.last_error',
                'fiscal_event_projections.dead_lettered_at',
            ]) as $row) {
            $rows[] = $this->formatDeadLetteredRow(
                fiscalEventId: (string) $row->fiscal_event_id,
                eventType: (string) $row->event_type,
                terminalId: (string) $row->terminal_id,
                projectorName: (string) $row->projector_name,
                attempts: (int) $row->attempts,
                lastError: $row->last_error === null ? null : (string) $row->last_error,
                deadLetteredAt: $row->dead_lettered_at === null ? null : (string) $row->dead_lettered_at,
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
                ->get(['id', 'event_type', 'terminal_id', 'server_received_at', 'integrity_exception_reason']);

            foreach ($quarantined as $event) {
                $rows[] = $this->formatQuarantineRow($event);
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
            return response()->json([
                'data' => $this->formatDeadLetteredRow(
                    fiscalEventId: (string) $event->id,
                    eventType: $event->event_type->value,
                    terminalId: (string) $event->terminal_id,
                    projectorName: (string) $projectionRow->projector_name,
                    attempts: (int) $projectionRow->attempts,
                    lastError: $projectionRow->last_error === null ? null : (string) $projectionRow->last_error,
                    deadLetteredAt: $projectionRow->dead_lettered_at === null ? null : (string) $projectionRow->dead_lettered_at,
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
            'write_off_action_url' => route('fiscal.refund-compensations.store'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQuarantineRow(FiscalEvent $event): array
    {
        return [
            'source' => 'ingress_quarantine',
            'fiscal_event_id' => $event->id,
            'event_type' => $event->event_type->value,
            'terminal_id' => $event->terminal_id,
            'projector_name' => null,
            'integrity_exception_reason' => $event->integrity_exception_reason,
            'server_received_at' => $event->server_received_at->toIso8601String(),
            'write_off_action_url' => route('fiscal.refund-compensations.store'),
        ];
    }
}
