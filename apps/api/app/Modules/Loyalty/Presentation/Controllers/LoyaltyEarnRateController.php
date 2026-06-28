<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the effective points-per-money-unit earning rate so the product
 * editor can show an indicative "≈ N points" figure. Read-only.
 */
final class LoyaltyEarnRateController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $programs = $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active);
        $rate = null;

        foreach ($programs as $program) {
            $spend = $this->earningRuleRepository->findActiveByProgram($program->id)
                ->first(fn (EarningRule $r) => $r->rule_type === EarningRuleType::Spend);
            if ($spend !== null) {
                $rate = (string) $spend->reward_value;
                break;
            }
        }

        return response()->json(['data' => ['rate' => $rate]]);
    }
}
