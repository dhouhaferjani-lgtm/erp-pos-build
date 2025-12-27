<?php

declare(strict_types=1);

namespace App\Modules\Service\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Service\Application\DTOs\ServiceData;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Presentation\Requests\CreateServiceRequest;
use App\Modules\Service\Presentation\Requests\UpdateServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ServiceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ServiceCatalogService $serviceCatalog,
    ) {}

    /**
     * List all services for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $filters = [];

        // Filter by active status
        if ($request->has('active')) {
            $filters['active_only'] = $request->boolean('active');
        }

        // Filter by category
        if ($request->has('category_id')) {
            $filters['category_id'] = $request->input('category_id');
        }

        // Filter by pricing type
        if ($request->has('pricing_type')) {
            $filters['pricing_type'] = $request->input('pricing_type');
        }

        // Search by name or code
        if ($request->has('search')) {
            $filters['search'] = $request->input('search');
        }

        $services = $this->serviceCatalog->listServices($companyId, $filters);

        return response()->json([
            'data' => $services->values()->all(),
            'meta' => [
                'current_page' => 1,
                'per_page' => $services->count(),
                'total' => $services->count(),
                'last_page' => 1,
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single service.
     */
    public function show(Request $request, string $service): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $serviceModel = Service::where('company_id', $companyId)
            ->where('id', $service)
            ->with('category')
            ->first();

        if (! $serviceModel) {
            return response()->json([
                'error' => [
                    'code' => 'SERVICE_NOT_FOUND',
                    'message' => 'Service not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => ServiceData::fromModel($serviceModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new service.
     */
    public function store(CreateServiceRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $serviceData = $this->serviceCatalog->createService($companyId, $validated);

        return response()->json([
            'data' => $serviceData,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing service.
     */
    public function update(UpdateServiceRequest $request, string $service): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $serviceModel = Service::where('company_id', $companyId)
            ->where('id', $service)
            ->first();

        if (! $serviceModel) {
            return response()->json([
                'error' => [
                    'code' => 'SERVICE_NOT_FOUND',
                    'message' => 'Service not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $serviceData = $this->serviceCatalog->updateService($service, $validated);

        return response()->json([
            'data' => $serviceData,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a service (soft delete).
     */
    public function destroy(Request $request, string $service): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $serviceModel = Service::where('company_id', $companyId)
            ->where('id', $service)
            ->first();

        if (! $serviceModel) {
            return response()->json([
                'error' => [
                    'code' => 'SERVICE_NOT_FOUND',
                    'message' => 'Service not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $this->serviceCatalog->deleteService($service);

        return response()->json(null, 204);
    }
}
