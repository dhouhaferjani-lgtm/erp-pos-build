<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\Commands\CreateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\UpdateBundleCommand;
use App\Modules\Workshop\Bundle\Application\DTOs\ServiceBundleData;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Presentation\Requests\StoreBundleRequest;
use App\Modules\Workshop\Bundle\Presentation\Requests\UpdateBundleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BundleController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BundleAuthoringService $authoring,
        private readonly BundleRepositoryInterface $bundles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.view')) {
            abort(403);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        $perPage = min((int) $request->input('per_page', 25), 100);

        $activeInput = $request->input('active');
        $filters = [];
        if ($activeInput !== null) {
            $filters['active'] = filter_var($activeInput, FILTER_VALIDATE_BOOLEAN);
        }
        $search = $request->input('search');
        if (is_string($search) && $search !== '') {
            $filters['search'] = $search;
        }

        $page = $this->bundles->paginateForCompany(
            tenantId: $company->tenant_id,
            companyId: $companyId,
            filters: $filters,
            perPage: $perPage,
        );

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn ($b): ServiceBundleData => ServiceBundleData::fromModel($b->load(['components.unit', 'vehicleApplicabilities'])))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreBundleRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        $data = $request->validated();

        $bundle = $this->authoring->create(new CreateBundleCommand(
            tenant_id: $company->tenant_id,
            company_id: $companyId,
            code: $data['code'],
            name: $data['name'],
            description: $data['description'] ?? null,
            pricing_mode: BundlePricingMode::from($data['pricing_mode']),
            base_price: isset($data['base_price']) ? (string) $data['base_price'] : null,
            currency: $data['currency'],
            tax_rate: isset($data['tax_rate']) ? (string) $data['tax_rate'] : null,
            estimated_labor_hours: isset($data['estimated_labor_hours']) ? (string) $data['estimated_labor_hours'] : null,
            service_interval_km: isset($data['service_interval_km']) ? (int) $data['service_interval_km'] : null,
            service_interval_months: isset($data['service_interval_months']) ? (int) $data['service_interval_months'] : null,
        ));

        return response()->json([
            'data' => ServiceBundleData::fromModel($bundle->load(['components.product', 'components.service', 'components.nestedBundle', 'components.unit', 'vehicleApplicabilities'])),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.view')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findWithComponentsAndApplicabilitiesForScope($company->tenant_id, $companyId, $id);
        if ($bundle === null) {
            abort(404);
        }

        return response()->json([
            'data' => ServiceBundleData::fromModel($bundle),
        ]);
    }

    public function update(UpdateBundleRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findByIdForScope($company->tenant_id, $companyId, $id);
        if ($bundle === null) {
            abort(404);
        }

        $data = $request->validated();

        $bundle = $this->authoring->update(new UpdateBundleCommand(
            bundle_id: $id,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            pricing_mode: isset($data['pricing_mode']) ? BundlePricingMode::from($data['pricing_mode']) : null,
            base_price: isset($data['base_price']) ? (string) $data['base_price'] : null,
            currency: $data['currency'] ?? null,
            tax_rate: isset($data['tax_rate']) ? (string) $data['tax_rate'] : null,
            estimated_labor_hours: isset($data['estimated_labor_hours']) ? (string) $data['estimated_labor_hours'] : null,
            service_interval_km: isset($data['service_interval_km']) ? (int) $data['service_interval_km'] : null,
            service_interval_months: isset($data['service_interval_months']) ? (int) $data['service_interval_months'] : null,
            is_active: isset($data['is_active']) ? (bool) $data['is_active'] : null,
            tenant_id: $company->tenant_id,
            company_id: $companyId,
        ));

        return response()->json([
            'data' => ServiceBundleData::fromModel($bundle->load(['components.product', 'components.service', 'components.nestedBundle', 'components.unit', 'vehicleApplicabilities'])),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.manage')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $bundle = $this->bundles->findByIdForScope($company->tenant_id, $companyId, $id);
        if ($bundle === null) {
            abort(404);
        }

        $this->bundles->softDelete($bundle);

        return response()->json(null, 204);
    }
}
