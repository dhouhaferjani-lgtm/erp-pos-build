<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;

/**
 * Attach-time ensure-enroll + balance read. Online (normal request, CompanyContext
 * present). The POS supplies the phone (no cross-module Partner read). Idempotent:
 * find-first on member + enrollment so re-attach never duplicates. Returns the active
 * Spend `rate` so the POS estimate needs no separate (loyalty.view-gated) call.
 *
 * @phpstan-type BalanceResult array{enrolled: bool, balance: string, tier: string|null, rate: string|null}
 */
final readonly class PosLoyaltyBalanceService
{
    public function __construct(
        private MemberResolver $memberResolver,
        private LoyaltyProgramRepositoryInterface $programRepository,
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private EarningRuleRepositoryInterface $earningRuleRepository,
        private MemberEnrollmentService $enrollmentService,
    ) {}

    /** @return BalanceResult */
    public function ensureAndGetBalance(
        string $tenantId,
        ?string $partnerId,
        ?string $contactId,
        ?string $phone,
        ?string $name,
    ): array {
        $program = $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
        if ($program === null) {
            return ['enrolled' => false, 'balance' => '0.000', 'tier' => null, 'rate' => null];
        }

        $rate = $this->activeSpendRate($program);
        $notEnrolled = ['enrolled' => false, 'balance' => '0.000', 'tier' => null, 'rate' => $rate];

        $member = $this->memberResolver->resolveByContactOrPartner($tenantId, $contactId, $partnerId);

        if ($member === null) {
            if ($phone === null || $phone === '') {
                return $notEnrolled; // phone is the required unique key — cannot create
            }
            $member = $this->findOrCreateMember($tenantId, $partnerId, $contactId, $phone, $name);
        }

        $enrollment = $this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id);
        if ($enrollment === null) {
            // enroll() throws if already enrolled — guarded above. Returns EnrollmentData.
            $this->enrollmentService->enroll($member->id, $program->id);
            $enrollment = $this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id);
        }

        if ($enrollment === null) {
            return $notEnrolled;
        }

        $enrollment->loadMissing('currentTier');

        return [
            'enrolled' => true,
            'balance' => (string) $enrollment->current_balance,
            'tier' => $enrollment->currentTier?->name,
            'rate' => $rate,
        ];
    }

    private function activeSpendRate(LoyaltyProgram $program): ?string
    {
        $spend = $this->earningRuleRepository->findActiveByProgram($program->id)
            ->first(fn (EarningRule $r) => $r->rule_type === EarningRuleType::Spend);

        return $spend !== null ? (string) $spend->reward_value : null;
    }

    private function findOrCreateMember(
        string $tenantId,
        ?string $partnerId,
        ?string $contactId,
        string $phone,
        ?string $name,
    ): LoyaltyMember {
        $normalized = LoyaltyMember::normalizePhone($phone);

        // Dedupe by the unique key (tenant, phone): reuse an existing member if present.
        // The unique index is non-partial and the model soft-deletes, so a soft-deleted
        // row still occupies the index — query withTrashed and restore it instead of
        // creating (which would 500 on the unique violation). (Codex N2)
        $existing = LoyaltyMember::withTrashed()
            ->where('tenant_id', $tenantId)->where('phone', $normalized)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        [$type, $id] = $contactId !== null ? ['contact', $contactId] : ['partner', $partnerId];

        return LoyaltyMember::create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => $type,
            'loyaltyable_id' => $id,
            'customer_id' => $partnerId,
            'phone' => $normalized,
            'first_name' => $name,
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
    }
}
