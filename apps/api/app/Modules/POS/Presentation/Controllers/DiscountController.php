<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\DiscountOrchestratorService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Controller for POS Discount Permissions.
 *
 * Handles:
 * - Retrieving discount permissions for current terminal and cashier
 * - Calculating effective discount limits
 */
final class DiscountController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DiscountOrchestratorService $orchestrator,
    ) {}

    /**
     * Get discount permissions for the current terminal and cashier
     *
     * GET /api/v1/pos/discount-permissions
     *
     * Headers:
     * - X-Terminal-Code: Terminal code (e.g., POS01)
     */
    public function getPermissions(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'User not authenticated',
                ],
            ], 401);
        }

        $terminalCode = $request->header('X-Terminal-Code') ?? $request->query('terminal_code');
        if ($terminalCode === null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_CODE_REQUIRED',
                    'message' => 'X-Terminal-Code header or terminal_code query parameter is required',
                ],
            ], 422);
        }

        $companyId = $this->companyContext->getCompanyId();
        if ($companyId === null) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_REQUIRED',
                    'message' => 'Company context is required',
                ],
            ], 422);
        }

        $terminal = Terminal::forCompany($companyId)
            ->byCode($terminalCode)
            ->first();

        if ($terminal === null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_NOT_FOUND',
                    'message' => 'Terminal not found',
                ],
            ], 404);
        }

        $discountUser = $user;
        $operatorId = $request->query('operator_id');
        if (is_string($operatorId) && $operatorId !== '') {
            $operator = User::where('tenant_id', $user->tenant_id)
                ->whereKey($operatorId)
                ->first();

            if (! $operator instanceof User) {
                return response()->json([
                    'error' => [
                        'code' => 'OPERATOR_NOT_FOUND',
                        'message' => 'Operator not found',
                    ],
                ], 404);
            }

            $discountUser = $operator;
        }

        $isAdmin = $discountUser->hasRole(['super_admin', 'admin']);
        $canUserDiscount = $isAdmin || $discountUser->can_discount;
        $userMaxDiscountPercent = $isAdmin ? 100.0 : ($discountUser->max_discount_percent !== null ? (float) $discountUser->max_discount_percent : null);

        // Calculate effective limit (most restrictive)
        $effectiveLimit = $this->calculateEffectiveLimit($terminal, $discountUser, $isAdmin);

        // User can discount only if:
        // 1. User has can_discount permission
        // 2. Terminal allows discounts
        $terminalAllowsDiscounts = $terminal->allow_line_discounts ||
            $terminal->allow_transaction_discounts;
        $canDiscount = $canUserDiscount && $terminalAllowsDiscounts;

        return response()->json([
            'data' => [
                'canDiscount' => $canDiscount,
                'userCanDiscount' => $canUserDiscount,
                'userMaxDiscountPercent' => $userMaxDiscountPercent,
                'terminalAllowsDiscounts' => $terminalAllowsDiscounts,
                'canApplyLineDiscounts' => $canUserDiscount && $terminal->allow_line_discounts,
                'canApplyTransactionDiscounts' => $canUserDiscount && $terminal->allow_transaction_discounts,
                'maxDiscountPercent' => $effectiveLimit,
                'requiresReason' => $effectiveLimit > 10.00,
                'effectiveLimit' => $effectiveLimit,
            ],
        ]);
    }

    /**
     * Preview all discount sources for a cart without persisting.
     *
     * POST /api/v1/pos/cart/preview-discounts
     */
    public function previewDiscounts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.category_id' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'items.*.line_total' => ['required', 'numeric', 'gte:0'],
            'subtotal' => ['required', 'numeric', 'gt:0'],
            'manual_discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'manual_discount_reason' => ['nullable', 'string', 'max:255'],
            'coupon_code' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'loyalty_discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'loyalty_reward_id' => ['nullable', 'string'],
        ]);

        $company = $this->companyContext->requireCompany();

        $items = [];
        foreach ($validated['items'] as $item) {
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

        /** @var numeric-string|null $manualAmount */
        $manualAmount = isset($validated['manual_discount_amount'])
            ? (string) $validated['manual_discount_amount']
            : null;

        /** @var numeric-string|null $loyaltyAmount */
        $loyaltyAmount = isset($validated['loyalty_discount_amount'])
            ? (string) $validated['loyalty_discount_amount']
            : null;

        $breakdown = $this->orchestrator->resolve(
            cart: $cart,
            manualDiscountAmount: $manualAmount,
            manualDiscountReason: $validated['manual_discount_reason'] ?? null,
            couponCode: $validated['coupon_code'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            loyaltyDiscountAmount: $loyaltyAmount,
            loyaltyRewardId: $validated['loyalty_reward_id'] ?? null,
        );

        return response()->json([
            'data' => $breakdown->toArray(),
        ]);
    }

    /**
     * Calculate the effective discount limit (most restrictive)
     */
    private function calculateEffectiveLimit(Terminal $terminal, User $user, bool $isAdmin): float
    {
        // Terminal limit — decimal:2 cast returns string; convert for numeric comparison
        $terminalLimit = (float) $terminal->max_discount_percent;

        // User limit (null means no individual limit, use terminal limit)
        $userLimit = $isAdmin ? 100.0 : ($user->max_discount_percent !== null ? (float) $user->max_discount_percent : $terminalLimit);

        // Return the most restrictive (minimum of the two)
        return min($terminalLimit, $userLimit);
    }
}
