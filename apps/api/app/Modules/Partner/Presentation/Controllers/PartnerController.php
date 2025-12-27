<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\DTOs\PartnerData;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Presentation\Requests\CreatePartnerRequest;
use App\Modules\Partner\Presentation\Requests\UpdatePartnerRequest;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PartnerController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all partners for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $params = $this->getPaginationParams($request);

        $query = Partner::query()
            ->where('company_id', $companyId);

        // Filter by type - use scope methods to include 'both' type partners
        if ($request->has('type')) {
            $type = $request->input('type');
            if ($type === 'customer') {
                $query->customers();
            } elseif ($type === 'supplier') {
                $query->suppliers();
            } else {
                // For 'both' or any other value, filter by exact match
                $query->where('type', $type);
            }
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->input('is_active'));
        }

        // Search by name, email, or VAT number (case-insensitive)
        // Use LOWER() for database-agnostic case-insensitive search (works on both PostgreSQL and SQLite)
        if ($request->has('search')) {
            $search = mb_strtolower($request->input('search'));
            // Escape LIKE special characters to prevent LIKE pattern injection
            $search = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(vat_number) LIKE ?', ["%{$search}%"]);
            });
        }

        // Order by name for consistent pagination
        $query->orderBy('name');

        // Use cursor pagination
        $paginator = $query->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        return response()->json(
            $this->formatPaginatedResponse($paginator, PartnerData::class)
        );
    }

    /**
     * Get a single partner.
     */
    public function show(Request $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => PartnerData::fromModel($partnerModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new partner.
     */
    public function store(CreatePartnerRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $partner = Partner::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            ...$validated,
        ]);

        return response()->json([
            'data' => PartnerData::fromModel($partner),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing partner.
     */
    public function update(UpdatePartnerRequest $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $partnerModel->update($validated);

        /** @var Partner $freshPartner */
        $freshPartner = $partnerModel->fresh();

        return response()->json([
            'data' => PartnerData::fromModel($freshPartner),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a partner (soft delete).
     */
    public function destroy(Request $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $partnerModel->delete();

        return response()->json(null, 204);
    }
}
