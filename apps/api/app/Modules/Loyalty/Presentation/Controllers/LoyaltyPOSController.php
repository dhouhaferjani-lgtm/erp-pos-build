<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\DTOs\LoyaltyMemberData;
use App\Modules\Loyalty\Application\DTOs\RewardData;
use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Application\Services\RedemptionProcessingService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * POS-facing loyalty endpoints for quick member lookup,
 * point earning preview, and reward redemption.
 */
class LoyaltyPOSController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly EarningProcessingService $earningService,
        private readonly RedemptionProcessingService $redemptionService,
    ) {}

    /**
     * Look up a loyalty member by phone number.
     * Returns member info with active enrollments and balances.
     */
    public function memberLookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $company = $this->companyContext->requireCompany();
        $phone = LoyaltyMember::normalizePhone($request->input('phone'));

        $member = LoyaltyMember::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('phone', $phone)
            ->first();

        if ($member === null) {
            return response()->json([
                'data' => null,
                'message' => 'No loyalty member found with this phone number',
            ], 404);
        }

        $enrollments = Enrollment::query()
            ->where('member_id', $member->id)
            ->where('status', EnrollmentStatus::Active)
            ->with(['program', 'currentTier'])
            ->get();

        return response()->json([
            'data' => [
                'member' => LoyaltyMemberData::fromModel($member),
                'enrollments' => $enrollments->map(fn (Enrollment $e) => EnrollmentData::fromModel($e)),
            ],
        ]);
    }

    /**
     * Preview points that would be earned for a cart.
     */
    public function previewEarning(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'string'],
            'items.*.category_id' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.price' => ['required_with:items', 'numeric', 'gte:0'],
        ]);

        $transactionData = [
            'amount' => (float) $validated['amount'],
            'items' => $validated['items'] ?? [],
            'timestamp' => now(),
        ];

        try {
            $points = $this->earningService->previewEarning(
                $validated['enrollment_id'],
                $transactionData,
            );

            return response()->json([
                'data' => [
                    'points_to_earn' => $points,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * List redeemable rewards for an enrollment.
     */
    public function rewards(string $enrollmentId): JsonResponse
    {
        if (! Str::isUuid($enrollmentId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();
        $enrollment = Enrollment::where('id', $enrollmentId)
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $company->tenant_id))
            ->with('program.rewards')
            ->firstOrFail();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Reward> $availableRewards */
        $availableRewards = $enrollment->program->rewards
            ->filter(function (Reward $reward) use ($enrollment) {
                return $reward->is_active
                    && (float) $enrollment->current_balance >= (float) $reward->points_cost;
            });

        return response()->json([
            'data' => [
                'current_balance' => $enrollment->current_balance,
                'rewards' => $availableRewards->values()->map(fn (Reward $r) => RewardData::fromModel($r)),
            ],
        ]);
    }

    /**
     * Redeem a reward. Deducts points and returns the discount.
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['required', 'string', 'uuid'],
            'reward_id' => ['required', 'string', 'uuid'],
        ]);

        try {
            $transaction = $this->redemptionService->redeemReward(
                $validated['enrollment_id'],
                $validated['reward_id'],
                'POS reward redemption',
            );

            return response()->json([
                'data' => $transaction,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * Explicitly earn points for a receipt (manual trigger).
     */
    public function earn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['required', 'string', 'uuid'],
            'receipt_id' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'string'],
            'items.*.category_id' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.price' => ['required_with:items', 'numeric', 'gte:0'],
        ]);

        $transactionData = [
            'amount' => (float) $validated['amount'],
            'items' => $validated['items'] ?? [],
            'timestamp' => now(),
        ];

        try {
            $transaction = $this->earningService->earnPoints(
                enrollmentId: $validated['enrollment_id'],
                transactionData: $transactionData,
                sourceType: 'pos_receipt',
                sourceId: $validated['receipt_id'],
                description: 'POS receipt earning',
            );

            return response()->json([
                'data' => $transaction,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['message' => $e->getMessage()],
            ], 422);
        }
    }
}
