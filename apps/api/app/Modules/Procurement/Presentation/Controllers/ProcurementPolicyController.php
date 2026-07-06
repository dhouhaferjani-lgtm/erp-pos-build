<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Procurement\Application\DTOs\ProcurementPolicyData;
use App\Modules\Procurement\Domain\Enums\ProcurementPreset;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Procurement\Presentation\Requests\UpdateProcurementPolicyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ProcurementPolicyController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $policy = ProcurementPolicy::firstOrCreateForCompany($company);

        return response()->json([
            'data' => ProcurementPolicyData::fromModel($policy),
            'meta' => $this->meta($request),
        ]);
    }

    public function update(UpdateProcurementPolicyRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $policy = ProcurementPolicy::firstOrCreateForCompany($company);
        $validated = $request->validated();

        if (isset($validated['preset'])) {
            $policy->applyPreset(ProcurementPreset::from($validated['preset']))->save();
        } else {
            $policy->forceFill([
                'preset' => null,
                'bill_control_mode' => $validated['bill_control_mode'],
                'match_mode' => $validated['match_mode'],
                'match_enforcement' => $validated['match_enforcement'],
                'variance_tolerance_percent' => $this->formatDecimal((string) $validated['variance_tolerance_percent'], 2),
                'variance_tolerance_max_amount' => $this->formatDecimal((string) $validated['variance_tolerance_max_amount'], 3),
                'allow_receipt_first' => $validated['allow_receipt_first'],
                'allow_invoice_first' => $validated['allow_invoice_first'],
                'invoice_first_requires_approval' => $validated['invoice_first_requires_approval'],
            ])->save();
        }

        return response()->json([
            'data' => ProcurementPolicyData::fromModel($policy->refresh()),
            'meta' => $this->meta($request),
        ]);
    }

    private function formatDecimal(string $value, int $scale): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    /**
     * @return array{timestamp: string, request_id: string}
     */
    private function meta(Request $request): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
        ];
    }
}
