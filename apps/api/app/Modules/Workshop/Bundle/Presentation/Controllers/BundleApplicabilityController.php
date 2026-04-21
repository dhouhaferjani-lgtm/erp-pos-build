<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\Commands\SetVehicleApplicabilitiesCommand;
use App\Modules\Workshop\Bundle\Application\DTOs\ServiceBundleVehicleApplicabilityData;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Presentation\Requests\SetApplicabilitiesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BundleApplicabilityController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BundleAuthoringService $authoring,
        private readonly BundleRepositoryInterface $bundles,
    ) {}

    public function replace(SetApplicabilitiesRequest $request, string $bundleId): JsonResponse
    {
        if (! Str::isUuid($bundleId)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findById($bundleId);
        if ($bundle === null || $bundle->company_id !== $companyId) {
            abort(404);
        }

        $data = $request->validated();
        $rows = [];
        foreach ($data['applicabilities'] as $row) {
            $rows[] = [
                'platform_vehicle_id' => $row['platform_vehicle_id'] ?? null,
                'vehicle_type' => $row['vehicle_type'] ?? null,
                'vehicle_display' => $row['vehicle_display'] ?? null,
                'year_from' => isset($row['year_from']) ? (int) $row['year_from'] : null,
                'year_to' => isset($row['year_to']) ? (int) $row['year_to'] : null,
            ];
        }

        $this->authoring->setVehicleApplicabilities(new SetVehicleApplicabilitiesCommand(
            bundle_id: $bundleId,
            applicabilities: $rows,
        ));

        $fresh = $this->bundles->findWithComponentsAndApplicabilities($bundleId);

        return response()->json([
            'data' => $fresh !== null
                ? $fresh->vehicleApplicabilities->map(fn ($a): ServiceBundleVehicleApplicabilityData => ServiceBundleVehicleApplicabilityData::fromModel($a))->values()->all()
                : [],
        ]);
    }
}
