<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\Services\TenantSensitivityService;
use App\Modules\SupportAccess\Presentation\Requests\UpdateTenantSensitivityRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class AdminTenantSensitivityController
{
    public function __construct(private readonly TenantSensitivityService $sensitivity) {}

    #[CrossTenantRoute(reason: 'A super-admin marks a central tenant as requiring four-eyes support access; the old and new sensitivity state are centrally audit-logged.')]
    public function update(UpdateTenantSensitivityRequest $request, string $tenant): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof SuperAdmin) {
            throw new AuthenticationException('Super-admin authentication is required.');
        }

        $updated = $this->sensitivity->change(
            admin: $admin,
            tenantId: $tenant,
            isSensitive: (bool) $request->validated('is_sensitive'),
            reason: (string) $request->validated('reason'),
        );

        return response()->json(['data' => [
            'id' => $updated->id,
            'is_sensitive' => (bool) $updated->is_sensitive,
        ]]);
    }
}
