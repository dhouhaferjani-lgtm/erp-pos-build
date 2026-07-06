<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\PartnerLoyaltySummaryData;
use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Application\Services\MemberEnrollmentService;
use App\Modules\Loyalty\Application\Services\MemberProvisioningService;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Presentation\Requests\EnrollPartnerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Partner-record loyalty surface — powers the boss-app partner card and the
 * cashier enrollment flow. Keyed on a partner id (a tenant-DB partners row);
 * the partner existence check is tenant-scoped so a foreign partner id is
 * unreachable (tenant-isolation cluster invariant).
 */
class LoyaltyPartnerController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly MemberResolver $memberResolver,
        private readonly MemberProvisioningService $memberProvisioning,
        private readonly MemberEnrollmentService $enrollmentService,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly EnrollmentRepositoryInterface $enrollmentRepository,
    ) {}

    public function show(string $partnerId): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        abort_unless(Str::isUuid($partnerId), 404); // PG uuid-cast guard
        abort_unless($this->partnerExists($tenantId, $partnerId), 404);

        $member = $this->memberResolver->resolveByContactOrPartner($tenantId, null, $partnerId);

        return response()->json(['data' => $this->summarize($member)]);
    }

    public function enroll(EnrollPartnerRequest $request, string $partnerId): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        abort_unless(Str::isUuid($partnerId), 404);
        $partner = DB::table('partners')
            ->where('id', $partnerId)
            ->where('tenant_id', $tenantId)
            ->first(['id', 'name', 'phone']);
        abort_if($partner === null, 404);

        $data = $request->validated();
        $program = isset($data['program_id'])
            ? $this->programRepository->findById($data['program_id'])
            : $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
        if ($program === null || ! $program->isActive()) {
            return response()->json(['error' => ['message' => 'No active loyalty program']], 422);
        }

        $member = $this->memberProvisioning->findOrCreateMember(
            $tenantId,
            $partnerId,
            null,
            $data['phone'],
            $partner->name,
        );

        if ($this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id) === null) {
            $this->enrollmentService->enroll($member->id, $program->id);
        }

        return response()->json(['data' => $this->summarize($member->refresh())], 201);
    }

    /**
     * Tenant-scoped partner existence check. The db-per-tenant connection is
     * the tenant boundary in production; the explicit tenant_id predicate keeps
     * the read correct under the shared test connection and satisfies the
     * cluster invariant (route-param anchors must carry tenant_id).
     */
    private function partnerExists(string $tenantId, string $partnerId): bool
    {
        return DB::table('partners')
            ->where('id', $partnerId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function summarize(?LoyaltyMember $member): PartnerLoyaltySummaryData
    {
        if ($member === null) {
            return PartnerLoyaltySummaryData::notMember();
        }

        $member->loadMissing(['enrollments.program', 'enrollments.currentTier']);

        return PartnerLoyaltySummaryData::fromMember($member);
    }
}
