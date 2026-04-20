<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\Commands\AddComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\RemoveComponentCommand;
use App\Modules\Workshop\Bundle\Application\DTOs\ServiceBundleComponentData;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Presentation\Requests\AddComponentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BundleComponentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BundleAuthoringService $authoring,
        private readonly BundleRepositoryInterface $bundles,
    ) {}

    public function store(AddComponentRequest $request, string $bundleId): JsonResponse
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

        $component = $this->authoring->addComponent(new AddComponentCommand(
            bundle_id: $bundleId,
            component_type: BundleComponentType::from($data['component_type']),
            component_id: $data['component_id'],
            quantity: (string) $data['quantity'],
            unit_id: $data['unit_id'],
            override_unit_price: isset($data['override_unit_price']) ? (string) $data['override_unit_price'] : null,
            is_optional: (bool) ($data['is_optional'] ?? false),
            display_order: (int) ($data['display_order'] ?? 0),
            notes: $data['notes'] ?? null,
        ));

        return response()->json([
            'data' => ServiceBundleComponentData::fromModel($component->load(['product', 'service', 'nestedBundle', 'unit'])),
        ], 201);
    }

    public function destroy(Request $request, string $bundleId, string $componentId): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.manage')) {
            abort(403);
        }
        if (! Str::isUuid($bundleId) || ! Str::isUuid($componentId)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findById($bundleId);
        if ($bundle === null || $bundle->company_id !== $companyId) {
            abort(404);
        }

        $this->authoring->removeComponent(new RemoveComponentCommand($bundleId, $componentId));

        return response()->json(null, 204);
    }
}
