<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\Services\RefundCompensationService;
use App\Modules\Fiscal\Presentation\Requests\RefundCompensationRequest;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;

/**
 * `POST /fiscal/refund-compensations` — v3-refund-chain-integration spec
 * §5.2. Re-keyed to `fiscal_event_id` (not a `fiscal_event_projections` row
 * id) so both a dead-lettered projection and a quarantined ingress row —
 * which may have no projection row at all — are reachable through the
 * same endpoint.
 *
 * Idempotent: a repeated POST for the same `fiscal_event_id` returns the
 * EXISTING record (200), never a duplicate.
 */
final class RefundCompensationController extends Controller
{
    public function __construct(
        private readonly RefundCompensationService $service,
    ) {}

    public function store(RefundCompensationRequest $request): JsonResponse
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

        /** @var array{fiscal_event_id: string, compensation_class: string, operator_attestation: string} $validated */
        $validated = $request->validated();

        $result = $this->service->compensate(
            fiscalEventId: $validated['fiscal_event_id'],
            compensationClass: $validated['compensation_class'],
            operatorId: (string) $user->id,
            operatorAttestation: $validated['operator_attestation'],
        );

        return response()->json([
            'data' => $result['compensation'],
        ], $result['wasIdempotentHit'] ? 200 : 201);
    }
}
