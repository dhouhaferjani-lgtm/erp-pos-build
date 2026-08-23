<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\DTOs\LoyaltyMemberData;
use App\Modules\Loyalty\Application\DTOs\RewardData;
use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Application\Services\PosLoyaltyBalanceService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Presentation\Requests\PosBalanceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly PosLoyaltyBalanceService $posBalanceService,
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

        // manual:loyalty-pos-controller-preview-earning-unscoped-enrollment +
        // manual:earning-processing-service-find-by-id-unscoped — pre-load
        // Enrollment scoped via the member's tenant_id BEFORE delegating to
        // the unscoped EarningProcessingService::previewEarning. Mirrors the
        // canonical pattern already used by self::rewards (line 124).
        // Cross-tenant enrollment_id surfaces as 404 with no foreign data
        // leak instead of 200 with points_to_earn computed against the
        // foreign enrollment.
        $company = $this->companyContext->requireCompany();
        $enrollmentExists = Enrollment::query()
            ->where('id', $validated['enrollment_id'])
            ->whereHas('member', fn (Builder $q) => $q->whereRaw('tenant_id = ?', [$company->tenant_id]))
            ->exists();
        if (! $enrollmentExists) {
            return response()->json(['error' => ['message' => 'Enrollment not found']], 404);
        }

        $transactionData = [
            'amount' => (string) $validated['amount'],
            'items' => $validated['items'] ?? [],
            'timestamp' => now(),
        ];

        try {
            $points = $this->earningService->previewEarning(
                $validated['enrollment_id'],
                $transactionData,
            );

            // points_to_earn is a NON-FISCAL display preview (estimated points
            // shown in the cart), not a stored money value. The service keeps
            // the canonical bcmath string internally to avoid float drift; we
            // float it ONLY here at the display-preview wire boundary so the
            // API contract stays numeric (frontend types it as `number`).
            return response()->json([
                'data' => [
                    'points_to_earn' => (float) $points,
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
            ->whereHas('member', fn (Builder $q) => $q->whereRaw('tenant_id = ?', [$company->tenant_id]))
            ->with('program.rewards')
            ->firstOrFail();

        /** @var Collection<int, Reward> $availableRewards */
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
     * Redeem a reward.
     *
     * ⛔ EXPLICITLY REFUSED — 501 LOYALTY_REDEMPTION_NOT_WIRED.
     *
     * Session B lane Q-3, treasury gate r1 finding F-2
     * (docs/superpowers/reviews/2026-08-23-sb-q3-loyalty-gate-r1.md).
     *
     * This endpoint has never worked: `loyalty_transactions.created_at` is NOT
     * NULL with no default while `Transaction::$timestamps` is false, so every
     * call 500'd at insert. Lane Q-3 hardened `RedemptionProcessingService`
     * (locked read, conditional debit, status guard, idempotency key) and fixed
     * that insert — which would have RESURRECTED the endpoint. That is worse
     * than leaving it dead, because the client contract underneath it does not
     * exist:
     *
     *   1. The response DTO (`TransactionData`) carries no `reward_value` and
     *      no `points_spent`. The sole client hand-writes
     *      `Promise<{ id, points_spent, reward_value }>` at
     *      apps/web/src/features/pos/api/loyaltyApi.ts:97-105 — a fabricated
     *      type (rule 7 violation) whose value fields are `undefined` at runtime.
     *   2. The only MOUNTED consumer,
     *      apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:725-728,
     *      discards the discount outright (`void rewardValue`). The other call
     *      site (TransactionCart.tsx:199-203) is gated on an
     *      `onLoyaltyRewardRedeemed` prop that no caller ever supplies.
     *   3. No listener subscribes to `RewardRedeemedV2` — grep finds the
     *      dispatch site and nothing else — so nothing applies a discount,
     *      posts GL, or reserves reward quantity downstream.
     *
     * A cashier tap would therefore debit real points, write a ledger row, show
     * "redeemed" and deliver nothing. Silent points loss.
     *
     * TO LIFT THIS GATE a wiring lane must ship, together: a redemption
     * response DTO that carries the applied value, generated types (`php artisan
     * typescript:transform`, rule 7), a consumer that actually applies the
     * discount to the cart, and — if the reward is to affect stock or GL — a
     * `RewardRedeemedV2` listener. Restore this method by re-injecting
     * `RedemptionProcessingService` and calling `redeemReward(...)` with the
     * validated `idempotency_key`; the service is already hardened and covered
     * (tests/Feature/Loyalty/RedemptionDoubleSpendTest.php).
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['required', 'string', 'uuid'],
            'reward_id' => ['required', 'string', 'uuid'],
            // Accepted and validated so the wire shape a wiring lane must honour
            // stays visible and stable. INERT while the gate below stands.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        // manual:loyalty-pos-controller-redeem-unscoped-enrollment-reward +
        // manual:redemption-processing-service-find-by-id-unscoped — pre-load
        // BOTH Enrollment and Reward tenant-scoped before delegating to the
        // unscoped RedemptionProcessingService::redeemReward. Cross-tenant
        // enrollment_id OR reward_id surfaces as 404; without these guards
        // the service writes redemption transactions against foreign program
        // / member rows (CRITICAL Finding B from
        // 2026-05-04-loyalty-cross-cluster-blind-spots.md).
        //
        // These stay AHEAD of the not-wired refusal below: the tenant-scoping
        // contract (and the test that pins it,
        // PosStabilizationTenantIsolationTest::test_loyalty_pos_redeem_refuses_cross_tenant_enrollment_or_reward)
        // must not silently become "everything 501s" — a cross-tenant probe
        // still gets 404 and learns nothing about foreign rows.
        $company = $this->companyContext->requireCompany();
        $enrollmentExists = Enrollment::query()
            ->where('id', $validated['enrollment_id'])
            ->whereHas('member', fn (Builder $q) => $q->whereRaw('tenant_id = ?', [$company->tenant_id]))
            ->exists();
        if (! $enrollmentExists) {
            return response()->json(['error' => ['message' => 'Enrollment not found']], 404);
        }
        $rewardExists = Reward::query()
            ->where('id', $validated['reward_id'])
            ->whereHas('program', fn (Builder $q) => $q->whereRaw('tenant_id = ?', [$company->tenant_id]))
            ->exists();
        if (! $rewardExists) {
            return response()->json(['error' => ['message' => 'Reward not found']], 404);
        }

        // ⛔ The gate. Nothing below this line may debit points until the client
        // contract described in the method docblock exists. Deliberately NOT a
        // feature flag: there is no configuration under which the current
        // client can consume a redemption, so an operator must not be able to
        // switch this on without the wiring lane.
        return response()->json([
            'error' => [
                'code' => 'LOYALTY_REDEMPTION_NOT_WIRED',
                'message' => 'Reward redemption is not available: the POS redemption contract '
                    .'(response value + discount application) is not wired yet. Redeeming here '
                    .'would debit points without delivering a reward.',
            ],
        ], 501);
    }

    /**
     * Attach-time ensure-enroll + balance read.
     * Find-or-creates the loyalty member (if phone supplied) and guard-enrolls in the
     * active program. Returns enrolled status, current balance, tier name, and the
     * active Spend rule rate so the POS can estimate earning without a separate call.
     */
    public function balance(PosBalanceRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $result = $this->posBalanceService->ensureAndGetBalance(
            $company->tenant_id,
            $request->input('partner_id'),
            $request->input('contact_id'),
            $request->input('phone'),
            $request->input('name'),
        );

        return response()->json(['data' => $result]);
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

        // manual:loyalty-pos-controller-earn-unscoped-enrollment +
        // manual:earning-processing-service-find-by-id-unscoped — pre-load
        // tenant-scoped Enrollment before delegating. Without this guard
        // tenant-A can write loyalty transactions and decrement balance
        // against a foreign tenant's enrollment via POST /loyalty/pos/earn.
        $company = $this->companyContext->requireCompany();
        $enrollmentExists = Enrollment::query()
            ->where('id', $validated['enrollment_id'])
            ->whereHas('member', fn (Builder $q) => $q->whereRaw('tenant_id = ?', [$company->tenant_id]))
            ->exists();
        if (! $enrollmentExists) {
            return response()->json(['error' => ['message' => 'Enrollment not found']], 404);
        }

        $transactionData = [
            'amount' => (string) $validated['amount'],
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
