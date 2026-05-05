<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Presentation\Concerns\RequiresCompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * Controller for managing account system purposes.
 *
 * These endpoints allow administrators to:
 * - View accounts with their system purposes
 * - Validate that a company has all required system accounts
 * - Assign or remove system purposes from accounts
 */
class AccountPurposeController extends Controller
{
    use RequiresCompanyAccess;

    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService
    ) {}

    /**
     * GET /api/companies/{companyId}/accounts/purposes
     *
     * List all accounts with their system purposes.
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $accounts = $this->chartOfAccountsService->getAccountsWithPurposes($companyId);

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'available_purposes' => $this->chartOfAccountsService->getAvailablePurposes(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/companies/{companyId}/accounts/purposes/validate
     *
     * Validate that a company has all required system accounts.
     */
    public function validate(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $result = $this->chartOfAccountsService->validateCompanyAccounts($companyId);

        return response()->json([
            'data' => [
                'valid' => $result['valid'],
                'missing_purposes' => $result['missing_purposes'],
                'required_purposes' => array_map(
                    fn (SystemAccountPurpose $purpose): string => $purpose->value,
                    SystemAccountPurpose::requiredPurposes()
                ),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * PUT /api/companies/{companyId}/accounts/{accountId}/purpose
     *
     * Assign a system purpose to an account.
     */
    public function assignPurpose(Request $request, string $companyId, string $accountId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $request->validate([
            'purpose' => [
                'required',
                'string',
                'in:'.implode(',', array_column(SystemAccountPurpose::cases(), 'value')),
            ],
        ]);

        /** @var string $purposeValue */
        $purposeValue = $request->input('purpose');
        $purpose = SystemAccountPurpose::from($purposeValue);

        try {
            $this->chartOfAccountsService->assignPurpose($companyId, $accountId, $purpose);

            return response()->json([
                'data' => [
                    'message' => 'Purpose assigned successfully',
                    'account_id' => $accountId,
                    'purpose' => $purpose->value,
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'PURPOSE_ALREADY_ASSIGNED',
                    'message' => $e->getMessage(),
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 422);
        }
    }

    /**
     * DELETE /api/companies/{companyId}/accounts/{accountId}/purpose
     *
     * Remove system purpose from an account.
     */
    public function removePurpose(Request $request, string $companyId, string $accountId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $this->chartOfAccountsService->removePurpose($companyId, $accountId);

        return response()->json([
            'data' => [
                'message' => 'Purpose removed successfully',
                'account_id' => $accountId,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
