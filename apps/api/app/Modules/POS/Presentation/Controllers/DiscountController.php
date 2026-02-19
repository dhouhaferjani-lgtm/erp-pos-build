<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
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

        // Calculate effective limit (most restrictive)
        $effectiveLimit = $this->calculateEffectiveLimit($terminal, $user);

        // User can discount only if:
        // 1. User has can_discount permission
        // 2. Terminal allows discounts
        $canDiscount = $user->can_discount && (
            $terminal->allow_line_discounts ||
            $terminal->allow_transaction_discounts
        );

        return response()->json([
            'data' => [
                'canDiscount' => $canDiscount,
                'canApplyLineDiscounts' => $user->can_discount && $terminal->allow_line_discounts,
                'canApplyTransactionDiscounts' => $user->can_discount && $terminal->allow_transaction_discounts,
                'maxDiscountPercent' => $effectiveLimit,
                'requiresReason' => $effectiveLimit > 10.00,
                'effectiveLimit' => $effectiveLimit,
            ],
        ]);
    }

    /**
     * Calculate the effective discount limit (most restrictive)
     *
     * @param  Terminal  $terminal
     * @param  \App\Modules\Identity\Domain\User  $user
     */
    private function calculateEffectiveLimit(Terminal $terminal, $user): float
    {
        // Terminal limit
        $terminalLimit = $terminal->max_discount_percent;

        // User limit (null means no individual limit, use terminal limit)
        $userLimit = $user->max_discount_percent ?? $terminalLimit;

        // Return the most restrictive (minimum of the two)
        return min($terminalLimit, $userLimit);
    }
}
