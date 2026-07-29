<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/pos/payment-policy
 *
 * Cash-rounding + tender-tolerance policy for the authenticated terminal's
 * company, cached by the device in SQLite for offline use. Same posture as
 * the fraud-settings endpoint: sanctum + CompanyContext, no extra gate, no
 * new permission.
 */
final class PosPaymentPolicyController extends Controller
{
    public function __construct(
        private readonly PosPaymentPolicyResolver $resolver,
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        return response()->json(['data' => $this->resolver->forCompany($companyId)->toArray()]);
    }
}
