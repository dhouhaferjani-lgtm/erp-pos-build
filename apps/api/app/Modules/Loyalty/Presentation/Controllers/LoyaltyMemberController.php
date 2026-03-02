<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\DTOs\LoyaltyMemberData;
use App\Modules\Loyalty\Application\Services\MemberEnrollmentService;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
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
        $member = LoyaltyMember::with(['enrollments.program', 'customer'])->findOrFail($id);

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
        $member = LoyaltyMember::findOrFail($id);
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
        $this->enrollmentService->optOut($enrollmentId);

        return response()->json([
            'message' => 'Member opted out successfully',
        ]);
    }

    /**
     * Reactivate a member's enrollment
     */
    public function reactivate(string $memberId, string $enrollmentId): JsonResponse
    {
        $this->enrollmentService->reactivate($enrollmentId);

        return response()->json([
            'message' => 'Enrollment reactivated successfully',
        ]);
    }

    /**
     * Get all enrollments for a member
     */
    public function enrollments(string $id): JsonResponse
    {
        $member = LoyaltyMember::with(['enrollments.program'])->findOrFail($id);

        return response()->json([
            'data' => $member->enrollments->map(fn ($enrollment) => EnrollmentData::fromModel($enrollment)),
        ]);
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
