<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\Services\SupportAccessQueryService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminSupportAccessQueryController extends Controller
{
    public function __construct(private readonly SupportAccessQueryService $queries) {}

    #[CrossTenantRoute(reason: 'Internal support-access work queue is intentionally central and spans tenant grants.')]
    public function index(Request $request): JsonResponse
    {
        $operator = $request->user();
        if (! $operator instanceof SuperAdmin) {
            throw new AuthenticationException('Central administrator authentication is required.');
        }

        $result = $this->queries->adminOverview(
            operatorId: $operator->id,
            isApprover: $operator->role === 'support_approver',
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 20),
        );

        return response()->json([
            'data' => $result['overview']->toArray(),
            'meta' => [
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
                'from' => $result['from'],
                'to' => $result['to'],
            ],
        ]);
    }
}
