<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\DTOs\BundleExpansionLineData;
use App\Modules\Workshop\Bundle\Application\Services\BundleExpansionService;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BundleExpansionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BundleExpansionService $expansion,
        private readonly BundleRepositoryInterface $bundles,
    ) {}

    public function show(Request $request, string $bundleId): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.view')) {
            abort(403);
        }
        if (! Str::isUuid($bundleId)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findById($bundleId);
        if ($bundle === null || $bundle->company_id !== $companyId) {
            abort(404);
        }

        $quantity = (string) $request->input('qty', '1');
        $vehicleId = $request->input('vehicle_id');
        $vehicleIdStr = is_string($vehicleId) && Str::isUuid($vehicleId) ? $vehicleId : null;

        $lines = $this->expansion->expandForWorkOrder($bundleId, $quantity, $vehicleIdStr);

        return response()->json([
            'data' => $lines->map(fn ($line): BundleExpansionLineData => BundleExpansionLineData::fromValueObject($line))->values(),
        ]);
    }
}
