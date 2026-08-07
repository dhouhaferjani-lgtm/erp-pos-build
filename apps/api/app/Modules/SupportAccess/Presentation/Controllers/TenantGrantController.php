<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\DTOs\GrantRequestData;
use App\Modules\SupportAccess\Application\Services\GrantLifecycleService;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Presentation\Requests\CreateSupportWindowRequest;
use App\Modules\SupportAccess\Presentation\Requests\DecideGrantRequest;
use App\Modules\SupportAccess\Presentation\Requests\RevokeGrantRequest;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TenantGrantController extends Controller
{
    public function __construct(private readonly GrantLifecycleService $grants) {}

    public function store(CreateSupportWindowRequest $request): JsonResponse
    {
        $actor = $this->tenantActor($request);
        $data = new GrantRequestData(
            tenant_id: $actor->tenant_id,
            subject_user_id: $request->validated('subject_user_id'),
            type: GrantType::PreGrantedWindow,
            reason: (string) $request->validated('reason'),
            ticket_ref: (string) $request->validated('ticket_ref'),
            starts_at: CarbonImmutable::parse((string) $request->validated('starts_at')),
            expires_at: CarbonImmutable::parse((string) $request->validated('expires_at')),
        );

        return response()->json(['data' => $this->grants->createPreGrantedWindow($actor, $data)], 201);
    }

    public function approve(Request $request, string $grant): JsonResponse
    {
        return response()->json(['data' => $this->grants->approveByTenant($this->tenantActor($request), $grant)]);
    }

    public function reject(DecideGrantRequest $request, string $grant): JsonResponse
    {
        return response()->json([
            'data' => $this->grants->rejectByTenant(
                $this->tenantActor($request),
                $grant,
                (string) $request->validated('reason'),
            ),
        ]);
    }

    public function revoke(RevokeGrantRequest $request, string $grant): JsonResponse
    {
        return response()->json([
            'data' => $this->grants->revoke(
                $this->tenantActor($request),
                $grant,
                (string) $request->validated('reason'),
            ),
        ]);
    }

    private function tenantActor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException('Tenant user authentication is required.');
        }

        return $actor;
    }
}
