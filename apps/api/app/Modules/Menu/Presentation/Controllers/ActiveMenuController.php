<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Controllers;

use App\Modules\Menu\Application\DTOs\ActiveMenuData;
use App\Modules\Menu\Application\Services\MenuResolutionService;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ActiveMenuController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly MenuResolutionService $menuResolutionService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $menu = $this->menuResolutionService->resolve($companyId);

        if ($menu === null) {
            return response()->json(['message' => 'No active menu found'], 404);
        }

        return response()->json(['data' => ActiveMenuData::fromModel($menu)]);
    }
}
