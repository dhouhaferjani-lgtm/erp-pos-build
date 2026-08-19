<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\Services\QuarantineIncidentResolutionService;
use App\Modules\Fiscal\Domain\Exceptions\QuarantineAlreadyResolvedException;
use App\Modules\Fiscal\Domain\Exceptions\QuarantineIncidentNotFoundException;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ES-17 — `POST /api/v1/fiscal/quarantine/{id}/resolve-incident`.
 *
 * Records that an authorised operator has ADJUDICATED a
 * `fiscal_event_quarantine` incident, stamping `resolved_at` / `resolved_by` so
 * `fiscal:verify-event-chain` stops reporting it
 * (`VerifyEventChainCommand.php:856`, `whereNull('resolved_at')`). Before this
 * endpoint existed, those two columns had no writer anywhere in `app/`, so one
 * `sequence_conflict` envelope made the chain verifier permanently non-green for
 * that terminal.
 *
 * **The endpoint takes no body.** There is nothing to supply: the stamp is a
 * record that a human adjudicated the incident, not a claim about the
 * envelope's contents. That is also why it is `resolve-incident` and not
 * `verify`/`accept` — `sequence_conflict` means two different events claimed one
 * sequence slot, and recording that someone looked at it says nothing about
 * which was right.
 *
 * Gated by the EXISTING seeded `fiscal.events.resolve_quarantine`, the same
 * permission that gates the sibling parse-failure resolver and the best-effort
 * parse endpoint. No new permission, no role-seeder change, and no
 * `permission:cache-reset` is required to deploy this.
 */
final class QuarantineIncidentResolutionController extends Controller
{
    public function __construct(
        private readonly QuarantineIncidentResolutionService $resolver,
    ) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authenticated user could not be resolved.',
                ],
            ], 401);
        }

        // Tenant scoping happens HERE and again inside the service's locked
        // read. A row belonging to another tenant is reported as 404, never as
        // "already resolved" — the response must not confirm the existence of
        // another tenant's fiscal incident.
        $incident = FiscalEventQuarantine::query()
            ->where('id', $id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $incident instanceof FiscalEventQuarantine) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'No quarantine incident was found for this tenant.',
                ],
            ], 404);
        }

        try {
            $this->resolver->resolve($id, $user);
        } catch (QuarantineIncidentNotFoundException $e) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()],
            ], 404);
        } catch (QuarantineAlreadyResolvedException $e) {
            return response()->json([
                'error' => ['code' => 'QUARANTINE_ALREADY_RESOLVED', 'message' => $e->getMessage()],
            ], 409);
        }

        $incident->refresh();

        return response()->json([
            'data' => [
                'id' => $incident->id,
                'status' => 'resolved',
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'resolved_by' => $incident->resolved_by,
            ],
        ]);
    }
}
