<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Application\DTOs\CouponData;
use App\Modules\Coupon\Application\Services\CouponApplicationService;
use App\Modules\Coupon\Application\Services\CouponManagementService;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Coupon\Presentation\Requests\StoreCouponRequest;
use App\Modules\Coupon\Presentation\Requests\UpdateCouponRequest;
use App\Modules\Coupon\Presentation\Requests\ValidateCouponRequest;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CouponApplicationService $couponService,
        private readonly CouponManagementService $managementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $query = Coupon::query()->forTenant($tenantId)->forCompany($companyId);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $status = CouponStatus::tryFrom((string) $request->input('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $coupons = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $coupons->map(fn (Coupon $c) => CouponData::fromModel($c)),
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $coupon = Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id);

        return response()->json(['data' => CouponData::fromModel($coupon)]);
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $coupon = Coupon::create([
            ...$request->validated(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => CouponData::fromModel($coupon)], 201);
    }

    public function update(UpdateCouponRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $coupon = Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id);
        $coupon->update($request->validated());

        return response()->json(['data' => CouponData::fromModel($coupon)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $coupon = Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id);
        $coupon->delete();

        return response()->json(null, 204);
    }

    /**
     * Validate a coupon code for POS preview — does NOT record usage.
     */
    public function validate(ValidateCouponRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();

        $items = [];
        foreach (($validated['items'] ?? []) as $item) {
            /** @var numeric-string $unitPrice */
            $unitPrice = (string) $item['unit_price'];
            /** @var numeric-string $lineTotal */
            $lineTotal = (string) $item['line_total'];
            $items[] = new CartItemContext(
                productId: $item['product_id'],
                categoryId: $item['category_id'] ?? null,
                quantity: (int) $item['quantity'],
                unitPrice: $unitPrice,
                lineTotal: $lineTotal,
            );
        }

        /** @var numeric-string $subtotal */
        $subtotal = (string) $validated['subtotal'];
        $cart = new CartContext(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            items: $items,
            subtotal: $subtotal,
            appliedAt: Carbon::now()->toIso8601String(),
        );

        try {
            $discount = $this->couponService->validateAndCalculate(
                strtoupper((string) $validated['code']),
                $cart,
                $validated['customer_id'] ?? null,
            );

            return response()->json([
                'data' => [
                    'valid' => true,
                    'discount_amount' => $discount->discountAmount,
                    'promotion_name' => $discount->promotionName,
                ],
            ]);
        } catch (CouponInvalidException $e) {
            return response()->json([
                'data' => [
                    'valid' => false,
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    public function revoke(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $coupon = Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id);

        try {
            $this->managementService->revoke($coupon);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_STATUS_TRANSITION', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['data' => CouponData::fromModel($coupon->fresh() ?? $coupon)]);
    }

    public function reactivate(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $coupon = Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id);

        try {
            $this->managementService->reactivate($coupon);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_STATUS_TRANSITION', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['data' => CouponData::fromModel($coupon->fresh() ?? $coupon)]);
    }
}
