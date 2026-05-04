<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\DTOs\WithholdingCalculationData;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Services\WithholdingCalculationService;
use App\Modules\Taxation\Presentation\Requests\CalculateWithholdingRequest;
use Illuminate\Http\JsonResponse;

/**
 * Withholding Preview Controller
 *
 * Handles preview calculations for withholding tax.
 * Used by payment form to show user suggested withholding before payment creation.
 */
class WithholdingPreviewController extends Controller
{
    public function __construct(
        private readonly WithholdingCalculationService $calculationService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Preview withholding calculation for a payment.
     */
    public function preview(CalculateWithholdingRequest $request): JsonResponse
    {
        // api.taxation.012: tenant-scope Partner lookup. The validator at
        // CalculateWithholdingRequest also runs ScopedExists::tenantAndCompany
        // on partner_id (api.taxation.006); this service-tier check is
        // defense-in-depth so a service-direct caller cannot bypass.
        $company = $this->companyContext->requireCompany();

        /** @var Partner $partner */
        $partner = Partner::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($request->input('partner_id'));
        $amount = $request->input('amount');
        $currency = $request->input('currency', 'TND');
        $transactionType = $request->input('transaction_type')
            ? TransactionType::from($request->input('transaction_type'))
            : null;

        $preview = $this->calculationService->preview(
            $partner,
            $amount,
            $currency,
            $company->country_code,
            $this->companyContext->requireCompanyId(),
            $transactionType
        );

        return response()->json([
            'data' => [
                'should_withhold' => $preview['should_withhold'],
                'calculation' => $preview['calculation']
                    ? WithholdingCalculationData::fromValueObject($preview['calculation'])->toArray()
                    : null,
                'suggested_rate' => $preview['suggested_rate'],
            ],
        ]);
    }
}
