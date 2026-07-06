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
use Illuminate\Support\Facades\DB;
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
            'items' => $this->resolveItemCategories($context->items), // Item/Category/Quantity rules
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

    /**
     * Enrich the sale-line snapshot with each product's catalog category so the
     * Category earning rule can match — the fiscal canonical payload carries no
     * category. `products.category_id` is a nullable bigint FK, so the resolved
     * value is an int (or null); this mirrors what `EarnPointsOnReceiptCompleted`
     * reads via `$line->product->category_id` on the server path.
     *
     * @param  list<array{product_id: string, quantity: string}>  $items
     * @return list<array{product_id: string, category_id: int|null, quantity: string}>
     */
    private function resolveItemCategories(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Deliberate DB-level read of the catalog table (no cross-module model
        // import, rule 6) — mirrors what EarnPointsOnReceiptCompleted gets via
        // $line->product->category_id, without the POS-model dependency.
        $categories = DB::table('products')
            ->whereIn('id', array_values(array_unique(array_column($items, 'product_id'))))
            ->pluck('category_id', 'id');

        return array_map(static function (array $i) use ($categories): array {
            $categoryId = $categories[$i['product_id']] ?? null;

            return [
                'product_id' => $i['product_id'],
                // Normalise to the bigint FK's int identity so the rule's strict
                // in_array() category match holds regardless of PDO stringification.
                'category_id' => $categoryId === null ? null : (int) $categoryId,
                'quantity' => $i['quantity'],
            ];
        }, $items);
    }
}
