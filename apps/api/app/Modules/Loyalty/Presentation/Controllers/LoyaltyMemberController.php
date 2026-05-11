<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\DTOs\LoyaltyMemberData;
use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Application\Services\MemberEnrollmentService;
use App\Modules\Loyalty\Application\Services\PointAdjustmentService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Presentation\Requests\AdjustPointsRequest;
use App\Modules\Loyalty\Presentation\Requests\CreateMemberRequest;
use App\Modules\Loyalty\Presentation\Requests\EnrollMemberRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateMemberRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Controller for loyalty member management
 */
class LoyaltyMemberController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly MemberEnrollmentService $enrollmentService,
        private readonly PointAdjustmentService $adjustmentService,
    ) {}

    /**
     * List all loyalty members for the current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = LoyaltyMember::where('tenant_id', $tenantId)
            ->with(['enrollments.program']);

        // Optional search by phone
        if ($request->has('phone')) {
            $query->where('phone', 'like', '%'.$request->input('phone').'%');
        }

        // Optional search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $members = $query->paginate($perPage);

        return response()->json([
            'data' => $members->map(fn ($member) => LoyaltyMemberData::fromModel($member)),
            'meta' => [
                'current_page' => $members->currentPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single loyalty member
     */
    public function show(string $id): JsonResponse
    {
        // api.loyalty.006: tenant-scope LoyaltyMember route lookup.
        // loyalty_members has tenant_id only (no company_id column);
        // tenant predicate alone satisfies the cluster invariant.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)
            ->with(['enrollments.program', 'loyaltyable'])
            ->findOrFail($id);

        return response()->json([
            'data' => LoyaltyMemberData::fromModel($member),
        ]);
    }

    /**
     * Create a new loyalty member
     */
    public function store(CreateMemberRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $member = LoyaltyMember::create(array_merge($request->validated(), [
            'tenant_id' => $tenantId,
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]));

        return response()->json([
            'data' => LoyaltyMemberData::fromModel($member),
            'message' => 'Loyalty member created successfully',
        ], 201);
    }

    /**
     * Update an existing loyalty member
     */
    public function update(UpdateMemberRequest $request, string $id): JsonResponse
    {
        // api.loyalty.007: tenant-scope LoyaltyMember route lookup.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id);
        $member->update($request->validated());

        return response()->json([
            'data' => LoyaltyMemberData::fromModel($member),
            'message' => 'Loyalty member updated successfully',
        ]);
    }

    /**
     * Enroll a member in a loyalty program
     */
    public function enroll(EnrollMemberRequest $request, string $id): JsonResponse
    {
        // api.loyalty round-2 (Codex Finding 1): the inventory enumerated
        // 5 LoyaltyMember controller findOrFail callsites (show / update /
        // enrollments / transactions / adjust) but missed `enroll`. The
        // service path then resolves $id via unscoped LoyaltyMember::find
        // in EloquentLoyaltyMemberRepository, so without this pre-check
        // tenant-A could POST /loyalty/members/{tenantB-id}/enroll with a
        // tenant-A program_id and create an Enrollment row linking
        // tenant-B's member to tenant-A's program (loyalty_enrollments
        // has no tenant column). Resolve the route id under tenant scope
        // before delegating; ModelNotFoundException → 404 mirrors the
        // sibling controller methods.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validated();

        $enrollment = $this->enrollmentService->enroll(
            memberId: $id,
            programId: $data['program_id'],
            welcomeBonus: $data['welcome_bonus'] ?? null
        );

        return response()->json([
            'data' => $enrollment,
            'message' => 'Member enrolled successfully',
        ], 201);
    }

    /**
     * Opt a member out of a program enrollment
     */
    public function optOut(string $memberId, string $enrollmentId): JsonResponse
    {
        // api.loyalty round-3 (Codex round-2 Finding 1): the original
        // implementation ignored $memberId entirely and resolved
        // $enrollmentId via unscoped EloquentEnrollmentRepository::findById,
        // letting tenant-A mutate tenant-B's enrollment state. Mirror the
        // transactions/adjust pattern: pre-load tenant-scoped member,
        // then resolve enrollment chained to that member.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId);
        $enrollment = Enrollment::where('id', $enrollmentId)
            ->where('member_id', $member->id)
            ->firstOrFail();

        $this->enrollmentService->optOut($enrollment->id);

        return response()->json([
            'message' => 'Member opted out successfully',
        ]);
    }

    /**
     * Reactivate a member's enrollment
     */
    public function reactivate(string $memberId, string $enrollmentId): JsonResponse
    {
        // api.loyalty round-3 (Codex round-2 Finding 1): same as optOut.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId);
        $enrollment = Enrollment::where('id', $enrollmentId)
            ->where('member_id', $member->id)
            ->firstOrFail();

        $this->enrollmentService->reactivate($enrollment->id);

        return response()->json([
            'message' => 'Enrollment reactivated successfully',
        ]);
    }

    /**
     * Get all enrollments for a member
     */
    public function enrollments(string $id): JsonResponse
    {
        // api.loyalty.008: tenant-scope LoyaltyMember route lookup.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)
            ->with(['enrollments.program'])
            ->findOrFail($id);

        return response()->json([
            'data' => $member->enrollments->map(fn ($enrollment) => EnrollmentData::fromModel($enrollment)),
        ]);
    }

    /**
     * List transactions for an enrollment
     */
    public function transactions(Request $request, string $memberId, string $enrollmentId): JsonResponse
    {
        // api.loyalty.009: tenant-scope LoyaltyMember route lookup.
        // The downstream Enrollment query is anchored on $member->id, which
        // is now tenant-scoped, so the enrollment chain inherits isolation.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId);
        $enrollment = Enrollment::where('id', $enrollmentId)
            ->where('member_id', $member->id)
            ->firstOrFail();

        $perPage = min($request->integer('per_page', 20), 100);
        $transactions = $enrollment->transactions()->latest('created_at')->paginate($perPage);

        return response()->json([
            'data' => $transactions->map(fn ($tx) => TransactionData::fromModel($tx)),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    /**
     * Adjust points for an enrollment
     */
    public function adjust(AdjustPointsRequest $request, string $memberId, string $enrollmentId): JsonResponse
    {
        // api.loyalty.010: tenant-scope LoyaltyMember route lookup.
        // The downstream Enrollment query is anchored on $member->id, which
        // is now tenant-scoped, so the enrollment chain inherits isolation.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId);
        $enrollment = Enrollment::where('id', $enrollmentId)
            ->where('member_id', $member->id)
            ->firstOrFail();

        if ($enrollment->status !== EnrollmentStatus::Active) {
            return response()->json([
                'message' => 'Enrollment must be active to adjust points',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        /** @var numeric-string $points */
        $points = (string) $request->input('points');

        try {
            $result = $this->adjustmentService->adjust(
                $enrollment,
                $points,
                (string) $request->input('reason'),
                $user,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $result,
        ], 201);
    }

    /**
     * Lookup member by phone (for POS)
     */
    public function lookupByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $member = LoyaltyMember::where('tenant_id', $tenantId)
            ->where('phone', $request->input('phone'))
            ->with(['enrollments.program'])
            ->first();

        if (! $member) {
            return response()->json([
                'data' => null,
                'message' => 'Member not found',
            ], 404);
        }

        return response()->json([
            'data' => LoyaltyMemberData::fromModel($member),
        ]);
    }
}
