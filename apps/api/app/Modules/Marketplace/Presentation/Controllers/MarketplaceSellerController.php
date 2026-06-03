<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Presentation\Controllers;

use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class MarketplaceSellerController extends Controller
{
    /**
     * GET /api/v1/admin/marketplace/sellers
     */
    #[CrossTenantRoute(reason: 'Super-admin marketplace management: lists all marketplace sellers across all tenants for platform-operations review (mounted under the /admin/ route prefix). Accepts tenant_id + company_id in store() validation rules — fleet-wide seller management is the explicit purpose.')]
    public function index(): JsonResponse
    {
        $sellers = MarketplaceSeller::orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $sellers->items(),
            'meta' => [
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
                'per_page' => $sellers->perPage(),
                'total' => $sellers->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/marketplace/sellers
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'string', 'uuid'],
            'company_id' => ['nullable', 'string', 'uuid'],
            'seller_type' => ['required', Rule::enum(SellerType::class)],
            'display_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $seller = MarketplaceSeller::create(array_merge($validated, [
            'seller_status' => SellerStatus::PendingReview,
            'commission_rate' => $validated['commission_rate'] ?? 5.00,
        ]));

        return response()->json(['data' => $seller], 201);
    }

    /**
     * PATCH /api/v1/admin/marketplace/sellers/{id}
     */
    #[CrossTenantRoute(reason: 'Super-admin marketplace management: edits any marketplace seller record across the fleet (display_name, commission_rate, seller_status, settings); mounted under the /admin/ route prefix.')]
    public function update(Request $request, string $id): JsonResponse
    {
        $seller = MarketplaceSeller::findOrFail($id);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'seller_status' => ['sometimes', Rule::enum(SellerStatus::class)],
            'settings' => ['sometimes', 'array'],
        ]);

        $seller->update($validated);

        return response()->json(['data' => $seller->fresh()]);
    }

    /**
     * POST /api/v1/admin/marketplace/sellers/{id}/suspend
     */
    #[CrossTenantRoute(reason: 'Super-admin marketplace management: suspends any marketplace seller across the fleet (sets seller_status=Suspended); mounted under the /admin/ route prefix.')]
    public function suspend(string $id): JsonResponse
    {
        $seller = MarketplaceSeller::findOrFail($id);

        $seller->update([
            'seller_status' => SellerStatus::Suspended,
        ]);

        return response()->json(['data' => $seller->fresh()]);
    }
}
