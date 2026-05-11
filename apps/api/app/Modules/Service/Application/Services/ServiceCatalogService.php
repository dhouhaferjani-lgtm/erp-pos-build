<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Service\Application\DTOs\ServiceCategoryData;
use App\Modules\Service\Application\DTOs\ServiceData;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ServiceCatalogService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    // ============================================
    // Service CRUD Operations
    // ============================================

    /**
     * Create a new service.
     *
     * @param  array<string, mixed>  $data
     */
    public function createService(string $companyId, array $data): ServiceData
    {
        $company = $this->companyContext->requireCompany();
        $this->assertCompanyMatchesContext($companyId, $company->id);

        $existingCode = Service::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('code', $data['code'])
            ->exists();

        if ($existingCode) {
            throw new InvalidArgumentException('Service code already exists');
        }

        $service = Service::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'pricing_type' => $data['pricing_type'] ?? 'flat_rate',
            'base_price' => $data['base_price'] ?? '0.00',
            'currency' => $data['currency'] ?? 'TND',
            'default_duration_minutes' => $data['default_duration_minutes'] ?? null,
            'hourly_rate' => $data['hourly_rate'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return ServiceData::fromModel($service);
    }

    /**
     * Update an existing service.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateService(string $serviceId, array $data): ServiceData
    {
        $company = $this->companyContext->requireCompany();

        // api.service.001 — tenant+company scoped findOrFail.
        $service = Service::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $serviceId)
            ->firstOrFail();

        if (isset($data['code']) && $data['code'] !== $service->code) {
            $existingCode = Service::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->where('code', $data['code'])
                ->where('id', '!=', $serviceId)
                ->exists();

            if ($existingCode) {
                throw new InvalidArgumentException('Service code already exists');
            }
        }

        $service->update($data);
        $service->refresh();

        return ServiceData::fromModel($service);
    }

    /**
     * Delete a service (soft delete).
     */
    public function deleteService(string $serviceId): bool
    {
        $company = $this->companyContext->requireCompany();

        // api.service.002 — tenant+company scoped findOrFail.
        $service = Service::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $serviceId)
            ->firstOrFail();

        $service->delete();

        return true;
    }

    /**
     * Get a single service by ID.
     */
    public function getService(string $serviceId): ServiceData
    {
        $company = $this->companyContext->requireCompany();

        // api.service.003 — tenant+company scoped findOrFail.
        $service = Service::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $serviceId)
            ->with('category')
            ->firstOrFail();

        return ServiceData::fromModel($service);
    }

    /**
     * List services for a company with optional filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ServiceData>
     */
    public function listServices(string $companyId, array $filters = []): Collection
    {
        $company = $this->companyContext->requireCompany();
        $this->assertCompanyMatchesContext($companyId, $company->id);

        $query = Service::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with('category');

        // Filter by active status
        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->active();
        }

        // Filter by category
        if (isset($filters['category_id'])) {
            $query->inCategory($filters['category_id']);
        }

        // Filter by pricing type
        if (isset($filters['pricing_type'])) {
            $pricingType = $filters['pricing_type'] instanceof PricingType
                ? $filters['pricing_type']
                : PricingType::from($filters['pricing_type']);
            $query->byPricingType($pricingType);
        }

        // Search by name or code (use LIKE for SQLite compatibility)
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = strtolower($filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
            });
        }

        // Order by name by default
        $query->orderBy('name');

        return $query->get()->map(fn (Service $service) => ServiceData::fromModel($service));
    }

    // ============================================
    // Category CRUD Operations
    // ============================================

    /**
     * Create a new service category.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCategory(string $companyId, array $data): ServiceCategoryData
    {
        $company = $this->companyContext->requireCompany();
        $this->assertCompanyMatchesContext($companyId, $company->id);

        $existingName = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('name', $data['name'])
            ->exists();

        if ($existingName) {
            throw new InvalidArgumentException('Category name already exists');
        }

        $category = ServiceCategory::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return ServiceCategoryData::fromModel($category);
    }

    /**
     * Update an existing category.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(string $categoryId, array $data): ServiceCategoryData
    {
        $company = $this->companyContext->requireCompany();

        $category = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $categoryId)
            ->firstOrFail();

        if (isset($data['name']) && $data['name'] !== $category->name) {
            $existingName = ServiceCategory::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->where('name', $data['name'])
                ->where('id', '!=', $categoryId)
                ->exists();

            if ($existingName) {
                throw new InvalidArgumentException('Category name already exists');
            }
        }

        $category->update($data);
        $category->refresh();

        return ServiceCategoryData::fromModel($category);
    }

    /**
     * Delete a category (only if no services use it).
     */
    public function deleteCategory(string $categoryId): bool
    {
        $company = $this->companyContext->requireCompany();

        $category = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $categoryId)
            ->withCount('services')
            ->firstOrFail();

        if ($category->services_count > 0) {
            throw new InvalidArgumentException('Cannot delete category with existing services');
        }

        // Child-categories check is anchored on $categoryId, which has just
        // been verified to belong to the active tenant+company above —
        // structurally protected by the upstream guard.
        $hasChildren = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('parent_id', $categoryId)
            ->exists();
        if ($hasChildren) {
            throw new InvalidArgumentException('Cannot delete category with child categories');
        }

        $category->delete();

        return true;
    }

    /**
     * Get a single category by ID.
     */
    public function getCategory(string $categoryId): ServiceCategoryData
    {
        $company = $this->companyContext->requireCompany();

        $category = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $categoryId)
            ->withCount('services')
            ->firstOrFail();

        return ServiceCategoryData::fromModel($category);
    }

    /**
     * List categories for a company with optional filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ServiceCategoryData>
     */
    public function listCategories(string $companyId, array $filters = []): Collection
    {
        $company = $this->companyContext->requireCompany();
        $this->assertCompanyMatchesContext($companyId, $company->id);

        $query = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->withCount('services');

        // Filter to root categories only
        if (isset($filters['root_only']) && $filters['root_only']) {
            $query->roots();
        }

        // Filter by active status
        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->active();
        }

        // Order by sort_order, then name
        $query->orderBy('sort_order')->orderBy('name');

        return $query->get()->map(fn (ServiceCategory $cat) => ServiceCategoryData::fromModel($cat));
    }

    /**
     * Get the full category tree for a company.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryTree(string $companyId): array
    {
        $company = $this->companyContext->requireCompany();
        $this->assertCompanyMatchesContext($companyId, $company->id);

        $categories = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->withCount('services')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->buildTree($categories);
    }

    /**
     * Build a hierarchical tree from flat categories.
     *
     * @param  Collection<int, ServiceCategory>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(Collection $categories, ?string $parentId = null): array
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);
                $tree[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'sort_order' => $category->sort_order,
                    'is_active' => $category->is_active,
                    'services_count' => $category->services_count,
                    'children' => $children,
                ];
            }
        }

        return $tree;
    }

    /**
     * Defense-in-depth: every public method that accepts a $companyId parameter
     * verifies it matches the tenant-validated CompanyContext. The middleware
     * pins context from the X-Company-Id header (or the user's first
     * membership) — any drift between the parameter and context indicates
     * either a controller bug or an attempt to call this service with a
     * cross-tenant company id.
     */
    private function assertCompanyMatchesContext(string $passedCompanyId, string $contextCompanyId): void
    {
        if ($passedCompanyId !== $contextCompanyId) {
            throw new InvalidArgumentException(
                "Passed companyId={$passedCompanyId} does not match active CompanyContext company_id={$contextCompanyId}.",
            );
        }
    }
}
