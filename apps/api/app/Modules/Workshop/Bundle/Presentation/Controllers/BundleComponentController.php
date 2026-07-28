<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\Commands\AddComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\RemoveComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\UpdateComponentCommand;
use App\Modules\Workshop\Bundle\Application\DTOs\ServiceBundleComponentData;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Exceptions\BundleCycleException;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Bundle\Presentation\Requests\AddComponentRequest;
use App\Modules\Workshop\Bundle\Presentation\Requests\PatchComponentRequest;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findByIdForScope($company->tenant_id, $companyId, $bundleId);
        if ($bundle === null) {
            abort(404);
        }

        $data = $request->validated();

        try {
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
                tenant_id: $bundle->tenant_id,
                company_id: $bundle->company_id,
            ));
        } catch (BundleCycleException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUNDLE_CYCLE',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'data' => ServiceBundleComponentData::fromModel(
                $component->load(['product', 'service', 'nestedBundle', 'unit']),
                CurrencyScale::for($bundle->currency),
            ),
        ], 201);
    }

    public function update(PatchComponentRequest $request, string $bundleId, string $componentId): JsonResponse
    {
        if (! Str::isUuid($bundleId) || ! Str::isUuid($componentId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findByIdForScope($company->tenant_id, $companyId, $bundleId);
        if ($bundle === null) {
            abort(404);
        }

        $exists = ServiceBundleComponent::query()
            ->where('tenant_id', $bundle->tenant_id)
            ->where('bundle_id', $bundleId)
            ->where('id', $componentId)
            ->exists();
        if (! $exists) {
            abort(404);
        }

        $data = $request->validated();

        $componentType = isset($data['component_type'])
            ? BundleComponentType::from($data['component_type'])
            : null;

        $command = new UpdateComponentCommand(
            bundle_id: $bundleId,
            component_id: $componentId,
            component_type: $componentType,
            new_component_reference_id: $data['component_id'] ?? null,
            quantity: isset($data['quantity']) ? (string) $data['quantity'] : null,
            unit_id: $data['unit_id'] ?? null,
            override_unit_price: array_key_exists('override_unit_price', $data) && $data['override_unit_price'] !== null
                ? (string) $data['override_unit_price']
                : null,
            override_unit_price_provided: array_key_exists('override_unit_price', $data),
            is_optional: array_key_exists('is_optional', $data) ? (bool) $data['is_optional'] : null,
            display_order: array_key_exists('display_order', $data) ? (int) $data['display_order'] : null,
            notes: $data['notes'] ?? null,
            notes_provided: array_key_exists('notes', $data),
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        );

        try {
            $component = $this->authoring->updateComponent($command);
        } catch (BundleCycleException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUNDLE_CYCLE',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'data' => ServiceBundleComponentData::fromModel(
                $component->load(['product', 'service', 'nestedBundle', 'unit']),
                CurrencyScale::for($bundle->currency),
            ),
        ]);
    }

    public function destroy(Request $request, string $bundleId, string $componentId): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.manage')) {
            abort(403);
        }
        if (! Str::isUuid($bundleId) || ! Str::isUuid($componentId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findByIdForScope($company->tenant_id, $companyId, $bundleId);
        if ($bundle === null) {
            abort(404);
        }

        $this->authoring->removeComponent(new RemoveComponentCommand(
            bundle_id: $bundleId,
            component_id: $componentId,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));

        return response()->json(null, 204);
    }
}
