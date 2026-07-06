<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Credits loyalty points for a completed POS sale using the existing
 * points-per-money-unit Spend rule. Invoked behind the cross-module
 * contract by PosCoreReceiptProjection for device-authored sales, reading
 * the buyer from the sealed sale snapshot and passing money as strings
 * (rule 19). EarnPointsOnReceiptCompleted is NOT retired — it remains the
 * live earn path for server-authored receipts (e.g. the exchange flow),
 * listening directly for the ReceiptCompleted domain event. Best-effort:
 * never throws to the caller.
 */
final readonly class SaleEarningService implements LoyaltyEarningContract
{
    public function __construct(
        private EarningProcessingService $earningService,
        private LoyaltyProgramRepositoryInterface $programRepository,
        private MemberResolver $memberResolver,
    ) {}

    public function earnForSale(SaleEarnContext $context): void
    {
        // Module guard (Codex SF-2): no active loyalty program ⇒ Loyalty not
        // set up for this tenant ⇒ nothing to earn. Worker-safe (no CompanyContext).
        $activePrograms = $this->programRepository->findByTenantAndStatus(
            $context->tenantId,
            ProgramStatus::Active,
        );
        if ($activePrograms->isEmpty()) {
            return;
        }

        $member = $this->memberResolver->resolveByContactOrPartner(
            $context->tenantId, $context->contactId, $context->partnerId,
        );
        if ($member === null) {
            return;
        }

        $enrollments = Enrollment::query()
            ->where('member_id', $member->id)
            ->where('status', EnrollmentStatus::Active)
            ->get();

        $transactionData = [
            'amount' => $context->earnBase,        // numeric-string (rule 19)
            'currency' => $context->currency,
            'items' => [],                          // Spend rule needs only the aggregate base
            'timestamp' => $context->postedAt,      // sealed device time (Codex SF-3)
        ];

        foreach ($enrollments as $enrollment) {
            try {
                $this->earningService->earnPoints(
                    enrollmentId: $enrollment->id,
                    transactionData: $transactionData,
                    sourceType: $context->sourceType,
                    sourceId: $context->sourceId,
                    description: $context->receiptNumber !== null
                        ? "POS receipt #{$context->receiptNumber}"
                        : null,
                );
            } catch (InvalidArgumentException $e) {
                // earnPoints() throws InvalidArgumentException for BOTH the
                // already-earned duplicate AND enrollment-not-found. Only the
                // duplicate is the idempotent replay/double-fire we swallow —
                // discriminate by message (Codex S1). Anything else falls through
                // to the loud-log branch.
                if (str_contains($e->getMessage(), 'already earned')) {
                    continue;
                }
                Log::error('Loyalty earn failed for sale (recoverable by hand)', [
                    'tenant_id' => $context->tenantId,
                    'enrollment_id' => $enrollment->id,
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'receipt_number' => $context->receiptNumber,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Real, non-duplicate failure (e.g. transient DB error). Log
                // loudly with recovery context; never break the sale. Auto-retry
                // is deferred (best-effort this cutoff).
                Log::error('Loyalty earn failed for sale (recoverable by hand)', [
                    'tenant_id' => $context->tenantId,
                    'enrollment_id' => $enrollment->id,
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'receipt_number' => $context->receiptNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
