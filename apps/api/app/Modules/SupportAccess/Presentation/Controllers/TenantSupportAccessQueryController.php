<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\Services\SupportAccessQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TenantSupportAccessQueryController extends Controller
{
    public function __construct(private readonly SupportAccessQueryService $queries) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => $this->queries->tenantOverview($user->tenant_id)->toArray(),
        ]);
    }
}
