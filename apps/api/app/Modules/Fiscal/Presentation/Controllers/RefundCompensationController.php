<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Services\RefundCompensationService;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
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
 *
 * **review round-2 CRITICAL 3 — tenant + company scope.** The tenant +
 * company scoped lookup happens HERE, in the controller (mirrors
 * `ParseFailureResolutionController`'s + `RepositoryAdjustmentController`'s
 * established pattern) — a `fiscal_event_id` belonging to another
 * tenant/company 404s (resource not found in THIS caller's scope) rather
 * than leaking existence via a 422/403 from inside the service.
 */
final class RefundCompensationController extends Controller
{
    public function __construct(
        private readonly RefundCompensationService $service,
        private readonly CompanyContext $companyContext,
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

        $company = $this->companyContext->requireCompany();

        $event = FiscalEvent::query()
            ->where('id', $validated['fiscal_event_id'])
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->first();

        if ($event === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'No fiscal event was found for this company.',
                ],
            ], 404);
        }

        $result = $this->service->compensate(
            event: $event,
            compensationClass: $validated['compensation_class'],
            operatorId: (string) $user->id,
            operatorAttestation: $validated['operator_attestation'],
        );

        return response()->json([
            'data' => $result['compensation'],
        ], $result['wasIdempotentHit'] ? 200 : 201);
    }
}
