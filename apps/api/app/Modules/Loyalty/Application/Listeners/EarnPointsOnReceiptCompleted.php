<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Listeners;

use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listens for receipt completion and awards loyalty points
 * to any enrolled customer.
 */
final class EarnPointsOnReceiptCompleted implements ShouldQueue
{
    public function __construct(
        private readonly EarningProcessingService $earningService,
    ) {}

    public function handle(ReceiptCompleted $event): void
    {
        // Load receipt first to get contact_id
        $receipt = Receipt::with('lines.product')->find($event->receiptId);
        if ($receipt === null) {
            return;
        }

        // Find loyalty member via polymorphic lookup
        $member = null;

        // 1. Check contact_id first (individual loyalty)
        if ($receipt->contact_id !== null) {
            $member = LoyaltyMember::query()
                ->where('loyaltyable_type', 'contact')
                ->where('loyaltyable_id', $receipt->contact_id)
                ->where('tenant_id', $event->tenantId)
                ->first();
        }

        // 2. Fall back to partner_id (company loyalty)
        if ($member === null && $event->customerId !== null) {
            $member = LoyaltyMember::query()
                ->where(function ($q) use ($event) {
                    $q->where(function ($q2) use ($event) {
                        $q2->where('loyaltyable_type', 'partner')
                            ->where('loyaltyable_id', $event->customerId);
                    })->orWhere('customer_id', $event->customerId);
                })
                ->where('tenant_id', $event->tenantId)
                ->first();
        }

        if ($member === null) {
            return;
        }

        // Get all active enrollments for this member
        $enrollments = Enrollment::query()
            ->where('member_id', $member->id)
            ->where('status', EnrollmentStatus::Active)
            ->get();

        $items = [];
        foreach ($receipt->lines as $line) {
            $items[] = [
                'product_id' => $line->product_id,
                'category_id' => $line->product->category_id ?? null,
                'quantity' => $line->quantity,
                'price' => (float) $line->unit_price,
            ];
        }

        $transactionData = [
            'amount' => (float) $event->totalAmount,
            'currency' => $event->currency,
            'items' => $items,
            'timestamp' => $receipt->posted_at ?? now(),
        ];

        foreach ($enrollments as $enrollment) {
            try {
                $this->earningService->earnPoints(
                    enrollmentId: $enrollment->id,
                    transactionData: $transactionData,
                    sourceType: 'pos_receipt',
                    sourceId: $event->receiptId,
                    description: "POS receipt #{$receipt->receipt_number}",
                );
            } catch (\Throwable $e) {
                Log::error('Failed to earn loyalty points on receipt completion', [
                    'receipt_id' => $event->receiptId,
                    'enrollment_id' => $enrollment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
