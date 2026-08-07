<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\DTOs\GrantRequestData;
use App\Modules\SupportAccess\Application\Services\GrantLifecycleService;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Presentation\Requests\RequestIncidentAccessRequest;
use App\Modules\SupportAccess\Presentation\Requests\RevokeGrantRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminGrantController extends Controller
{
    public function __construct(private readonly GrantLifecycleService $grants) {}

    #[CrossTenantRoute(reason: 'A support operator requests a consent-gated, time-boxed grant for one tenant and subject from the central admin surface.')]
    public function store(RequestIncidentAccessRequest $request): JsonResponse
    {
        $operator = $this->operator($request);
        $startsAt = CarbonImmutable::parse((string) ($request->validated('starts_at') ?? 'now'));
        $data = new GrantRequestData(
            tenant_id: (string) $request->validated('tenant_id'),
            subject_user_id: (string) $request->validated('subject_user_id'),
            type: GrantType::PerIncident,
            reason: (string) $request->validated('reason'),
            ticket_ref: (string) $request->validated('ticket_ref'),
            starts_at: $startsAt,
            expires_at: $startsAt->addMinutes((int) $request->validated('duration_minutes')),
        );

        return response()->json(['data' => $this->grants->requestIncident($operator, $data)], 201);
    }

    #[CrossTenantRoute(reason: 'A configured business-partner super-admin supplies the distinct second approval required for sensitive support access.')]
    public function approveSecond(Request $request, string $grant): JsonResponse
    {
        return response()->json(['data' => $this->grants->approveSecond($this->operator($request), $grant)]);
    }

    #[CrossTenantRoute(reason: 'The requesting operator or configured approver revokes a central support grant, which is enforced by the session kill-switch.')]
    public function revoke(RevokeGrantRequest $request, string $grant): JsonResponse
    {
        return response()->json([
            'data' => $this->grants->revoke(
                $this->operator($request),
                $grant,
                (string) $request->validated('reason'),
            ),
        ]);
    }

    private function operator(Request $request): SuperAdmin
    {
        $operator = $request->user();
        if (! $operator instanceof SuperAdmin) {
            throw new AuthenticationException('Support operator authentication is required.');
        }

        return $operator;
    }
}
